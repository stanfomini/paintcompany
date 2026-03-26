<?php

namespace App\Http\Controllers;

use App\Events\CallConnected;
use App\Events\CallEnded;
use App\Events\IncomingCall;
use App\Events\MissedCallReceived;
use App\Events\VoicemailReceived;
use App\Jobs\ProcessCallRecordingJob;
use App\Jobs\ProcessMissedCallJob;
use App\Jobs\TranscribeVoicemailJob;
use App\Models\CallGroup;
use App\Models\CallLog;
use App\Services\CallRoutingService;
use App\Services\TwilioService;
use App\Services\TwilioVoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TwilioVoiceWebhookController extends Controller
{
    public function __construct(
        protected TwilioService $twilio,
        protected TwilioVoiceService $voice,
        protected CallRoutingService $routing
    ) {}

    /**
     * Handle an incoming voice call.
     * POST /webhooks/twilio/voice
     */
    public function incomingCall(Request $request): Response
    {
        if (! $this->twilio->validateWebhookSignature($request)) {
            Log::error('Twilio voice webhook: invalid signature');

            return response('Forbidden', 403);
        }

        $from = $request->input('From', '');
        $to = $request->input('To', '');
        $callSid = $request->input('CallSid', '');

        if (empty($callSid)) {
            return $this->twimlResponse('<Response><Hangup/></Response>');
        }

        // Identify caller
        $identity = $this->routing->findCallerIdentity($from);

        // Create call log
        $callLog = CallLog::create([
            'twilio_call_sid' => $callSid,
            'direction' => 'inbound',
            'from_number' => $from,
            'to_number' => $to,
            'customer_id' => $identity['customer']?->id,
            'caller_user_id' => $identity['user']?->id,
            'status' => 'ringing',
            'started_at' => now(),
        ]);

        $settings = \App\Models\CompanySetting::getInstance();

        // If IVR is enabled, present the IVR menu instead of direct routing
        if ($settings->ivr_enabled) {
            $this->safeBroadcast(new IncomingCall($callLog, []));

            Log::info('Twilio voice webhook: incoming call routed to IVR', [
                'call_log_id' => $callLog->id,
                'from' => $from,
            ]);

            $twiml = $this->voice->generateIvrMenuTwiml($callLog);

            return $this->twimlResponse($twiml);
        }

        // Resolve call group for routing
        $callGroup = $this->routing->resolveCallGroup($from);

        // Broadcast incoming call to target users
        $targetUserIds = [];
        if ($callGroup) {
            $callLog->update(['call_group_id' => $callGroup->id]);

            $targetUserIds = $callGroup->members()
                ->where('is_active', true)
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->toArray();
        }

        $this->safeBroadcast(new IncomingCall($callLog, $targetUserIds));

        // Generate TwiML
        $twiml = $callGroup
            ? $this->voice->generateRingGroupTwiml($callGroup, $callLog)
            : $this->voice->generateVoicemailTwiml($callLog);

        Log::info('Twilio voice webhook: incoming call routed', [
            'call_log_id' => $callLog->id,
            'from' => $from,
            'call_group' => $callGroup?->name,
        ]);

        return $this->twimlResponse($twiml);
    }

    /**
     * Handle call status updates (completed, busy, failed, etc.).
     * POST /webhooks/twilio/voice/status
     */
    public function callStatus(Request $request): Response
    {
        if (! $this->twilio->validateWebhookSignature($request)) {
            return response('Forbidden', 403);
        }

        $callSid = $request->input('CallSid', '');
        $status = $request->input('CallStatus', '');
        $duration = $request->input('CallDuration');

        $callLog = CallLog::where('twilio_call_sid', $callSid)->first();

        if (! $callLog) {
            Log::warning('Twilio voice status: CallLog not found', ['sid' => $callSid]);

            return response('OK', 200);
        }

        // Preserve voicemail status when Twilio sends 'completed' for a call
        // that already reached our voicemail system (caller hung up during greeting)
        $wasVoicemail = $callLog->status === 'voicemail';

        $updateData = ['status' => ($wasVoicemail && $status === 'completed') ? 'voicemail' : $status];

        if ($duration !== null) {
            $updateData['duration_seconds'] = (int) $duration;
        }

        if (in_array($status, ['completed', 'no-answer', 'busy', 'failed', 'canceled'])) {
            $updateData['ended_at'] = now();
        }

        $callLog->update($updateData);

        if (in_array($status, ['completed', 'no-answer', 'busy', 'failed'])) {
            $userId = $callLog->answered_by_user_id ?? $callLog->caller_user_id ?? 0;
            $this->safeBroadcast(new CallEnded($callLog, $userId));
        }

        // Dispatch missed call processing for no-answer or voicemail calls
        if ($status === 'no-answer' || $wasVoicemail) {
            ProcessMissedCallJob::dispatch($callLog->id)->delay(now()->addSeconds(5));
        }

        // Broadcast missed call event for real-time counter updates
        if ($status === 'no-answer') {
            $this->safeBroadcast(new MissedCallReceived($callLog));
        }

        // Auto-complete follow-up when calling back a missed caller
        if ($status === 'completed' && $callLog->direction === 'outbound' && $callLog->to_number) {
            $recentMissed = CallLog::query()
                ->where('from_number', $callLog->to_number)
                ->where('direction', 'inbound')
                ->missed()
                ->unhandled()
                ->where('started_at', '>=', now()->subDays(7))
                ->get();

            foreach ($recentMissed as $missed) {
                $missed->markHandledBy(
                    \App\Models\User::find($callLog->caller_user_id) ?? auth()->user(),
                    'called_back'
                );

                // Auto-complete the linked activity
                if ($missed->follow_up_activity_id) {
                    \App\Models\Activity::where('id', $missed->follow_up_activity_id)
                        ->where('is_completed', false)
                        ->update(['is_completed' => true, 'completed_at' => now()]);
                }
            }
        }

        return response('OK', 200);
    }

    /**
     * Handle recording status callbacks.
     * POST /webhooks/twilio/voice/recording
     */
    public function recordingReady(Request $request): Response
    {
        if (! $this->twilio->validateWebhookSignature($request)) {
            return response('Forbidden', 403);
        }

        $callSid = $request->input('CallSid', '');
        $recordingSid = $request->input('RecordingSid', '');
        $recordingUrl = $request->input('RecordingUrl', '');
        $recordingDuration = $request->input('RecordingDuration');

        // Twilio returns recording URLs without file extension (returns JSON).
        // Append .mp3 so the URL is directly playable in browsers and by services.
        if (! empty($recordingUrl) && ! str_ends_with($recordingUrl, '.mp3')) {
            $recordingUrl = rtrim($recordingUrl, '/').'.mp3';
        }

        $callLog = CallLog::where('twilio_call_sid', $callSid)->first();

        if (! $callLog) {
            Log::warning('Twilio voice recording: CallLog not found', ['sid' => $callSid]);

            return response('OK', 200);
        }

        $callLog->update([
            'recording_sid' => $recordingSid,
            'recording_url' => $recordingUrl,
            'recording_duration_seconds' => $recordingDuration ? (int) $recordingDuration : null,
        ]);

        // Dispatch processing jobs
        if ($callLog->isVoicemail()) {
            TranscribeVoicemailJob::dispatch($callLog->id);
            $this->safeBroadcast(new VoicemailReceived($callLog));
            ProcessMissedCallJob::dispatch($callLog->id)->delay(now()->addSeconds(10));
        }

        ProcessCallRecordingJob::dispatch($callLog->id);

        Log::info('Twilio voice recording: processed', [
            'call_log_id' => $callLog->id,
            'recording_sid' => $recordingSid,
        ]);

        return response('OK', 200);
    }

    /**
     * Handle dial status (after ring group attempt).
     * POST /webhooks/twilio/voice/dial-status/{callLog}
     */
    public function dialStatus(Request $request, string $callLog): Response
    {
        if (! $this->twilio->validateWebhookSignature($request)) {
            return response('Forbidden', 403);
        }

        $log = CallLog::where('ulid', $callLog)->first();

        if (! $log) {
            return $this->twimlResponse('<Response><Hangup/></Response>');
        }

        $dialStatus = $request->input('DialCallStatus', '');

        if (in_array($dialStatus, ['completed', 'answered'])) {
            // Call was answered
            $log->update([
                'status' => 'in-progress',
                'answered_at' => now(),
            ]);

            // Try to find who answered from DialCallSid
            $answeredByUserId = $this->resolveAnsweringUser($request, $log);
            if ($answeredByUserId) {
                $log->update(['answered_by_user_id' => $answeredByUserId]);
                $this->safeBroadcast(new CallConnected($log, $answeredByUserId));
            }

            return $this->twimlResponse('<Response></Response>');
        }

        // Not answered — go to voicemail
        $log->update(['status' => 'voicemail']);

        // Safety-net notification in case caller hangs up during greeting
        ProcessMissedCallJob::dispatch($log->id)->delay(now()->addSeconds(30));

        $twiml = $this->voice->generateVoicemailTwiml($log);

        return $this->twimlResponse($twiml);
    }

    /**
     * Handle voicemail recording completion.
     * POST /webhooks/twilio/voice/voicemail/{callLog}
     */
    public function voicemailComplete(Request $request, string $callLog): Response
    {
        if (! $this->twilio->validateWebhookSignature($request)) {
            return response('Forbidden', 403);
        }

        $log = CallLog::where('ulid', $callLog)->first();

        if ($log) {
            $log->update([
                'status' => 'voicemail',
                'ended_at' => now(),
            ]);

            // Notify about voicemail (idempotent — skips if already processed)
            ProcessMissedCallJob::dispatch($log->id)->delay(now()->addSeconds(5));
        }

        // Return empty TwiML to end the call
        return $this->twimlResponse('<Response><Hangup/></Response>');
    }

    /**
     * Handle voicemail transcription callback.
     * POST /webhooks/twilio/voice/transcription/{callLog}
     */
    public function transcriptionComplete(Request $request, string $callLog): Response
    {
        if (! $this->twilio->validateWebhookSignature($request)) {
            return response('Forbidden', 403);
        }

        $log = CallLog::where('ulid', $callLog)->first();
        $transcription = $request->input('TranscriptionText', '');

        if ($log && ! empty($transcription)) {
            $log->update(['voicemail_transcription' => $transcription]);
        }

        return response('OK', 200);
    }

    /**
     * Handle IVR digit input (Phase 2).
     * POST /webhooks/twilio/voice/ivr-select/{callLog}
     */
    public function ivrSelect(Request $request, string $callLog): Response
    {
        if (! $this->twilio->validateWebhookSignature($request)) {
            return response('Forbidden', 403);
        }

        $log = CallLog::where('ulid', $callLog)->first();

        if (! $log) {
            return $this->twimlResponse('<Response><Hangup/></Response>');
        }

        $digits = $request->input('Digits', '');
        $settings = \App\Models\CompanySetting::getInstance();
        $ivrMenu = $settings->ivr_menu ?? [];
        $digitsLength = strlen($digits);

        // No digits (timeout)
        if ($digitsLength === 0) {
            return $this->handleIvrTimeout($log, $ivrMenu);
        }

        // Single digit → IVR menu option
        if ($digitsLength === 1) {
            return $this->handleIvrMenuOption($log, $digits, $ivrMenu);
        }

        // 3-4 digits → extension dialing
        if ($digitsLength >= 3 && $digitsLength <= 4) {
            return $this->handleExtensionDial($log, $digits, $ivrMenu);
        }

        // Unexpected digit length — replay IVR menu
        $twiml = $this->voice->generateIvrMenuTwiml($log);

        return $this->twimlResponse($twiml);
    }

    /**
     * Handle a single-digit IVR menu selection.
     */
    private function handleIvrMenuOption(CallLog $log, string $digit, array $ivrMenu): Response
    {
        $result = $this->routing->resolveIvrOption($digit, $ivrMenu);

        // Direct to voicemail
        if ($result['action'] === 'voicemail') {
            $log->update([
                'status' => 'voicemail',
                'metadata' => array_merge($log->metadata ?? [], [
                    'ivr_option' => $digit,
                    'ivr_action' => 'voicemail',
                ]),
            ]);

            // Safety-net notification in case caller hangs up during greeting
            ProcessMissedCallJob::dispatch($log->id)->delay(now()->addSeconds(30));

            $twiml = $this->voice->generateVoicemailTwiml($log);

            return $this->twimlResponse($twiml);
        }

        // Route to call group
        if ($result['action'] === 'route_to_group' && $result['call_group']) {
            $callGroup = $result['call_group'];

            $log->update([
                'call_group_id' => $callGroup->id,
                'metadata' => array_merge($log->metadata ?? [], [
                    'ivr_option' => $digit,
                    'call_group_id' => $callGroup->id,
                    'call_group_name' => $callGroup->name,
                ]),
            ]);

            // Broadcast incoming call to group members
            $targetUserIds = $callGroup->members()
                ->where('is_active', true)
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->toArray();

            $this->safeBroadcast(new IncomingCall($log, $targetUserIds));

            $twiml = $this->voice->generateRingGroupTwiml($callGroup, $log);

            return $this->twimlResponse($twiml);
        }

        // Invalid option — replay IVR menu
        $twiml = $this->voice->generateIvrMenuTwiml($log);

        return $this->twimlResponse($twiml);
    }

    /**
     * Handle extension dialing from IVR.
     */
    private function handleExtensionDial(CallLog $log, string $digits, array $ivrMenu): Response
    {
        $user = $this->routing->resolveFromExtension($digits);

        if ($user) {
            // Update call log with voicemail ownership and metadata
            $log->update([
                'voicemail_for_user_id' => $user->id,
                'metadata' => array_merge($log->metadata ?? [], [
                    'extension_dialed' => $digits,
                    'extension_user_id' => $user->id,
                    'extension_user_name' => $user->name,
                ]),
            ]);

            // Broadcast incoming call to the specific user
            $this->safeBroadcast(new IncomingCall($log, [$user->id]));

            // Dial the user's forwarding number directly
            $response = new \Twilio\TwiML\VoiceResponse;
            $dialOptions = [
                'timeout' => 17,
                'callerId' => config('services.twilio.from'),
            ];

            try {
                $dialOptions['action'] = route('webhooks.twilio.voice.dial-status', [
                    'callLog' => $log->ulid,
                ]);
            } catch (\Throwable) {
                // Route may not exist yet
            }

            $dial = $response->dial('', $dialOptions);

            $numberAttrs = [];
            try {
                $numberAttrs['url'] = route('webhooks.twilio.voice.whisper', ['callLog' => $log->ulid]);
            } catch (\Throwable) {
                // Route may not exist yet
            }

            $dial->number($user->forwarding_number, $numberAttrs);

            return $this->twimlResponse($response->__toString());
        }

        // Extension not found — replay IVR menu
        $twiml = $this->voice->generateIvrMenuTwiml($log);

        return $this->twimlResponse($twiml);
    }

    /**
     * Handle IVR timeout (no digits entered).
     */
    private function handleIvrTimeout(CallLog $log, array $ivrMenu): Response
    {
        $timeoutAction = $ivrMenu['timeout_action'] ?? 'ring_default';

        if ($timeoutAction === 'ring_default') {
            $settings = \App\Models\CompanySetting::getInstance();
            $defaultGroup = $settings->default_call_group_id
                ? CallGroup::find($settings->default_call_group_id)
                : null;

            if ($defaultGroup) {
                $log->update(['call_group_id' => $defaultGroup->id]);

                $targetUserIds = $defaultGroup->members()
                    ->where('is_active', true)
                    ->whereNotNull('user_id')
                    ->pluck('user_id')
                    ->toArray();

                $this->safeBroadcast(new IncomingCall($log, $targetUserIds));

                $twiml = $this->voice->generateRingGroupTwiml($defaultGroup, $log);

                return $this->twimlResponse($twiml);
            }
        }

        // Default fallback: voicemail
        $log->update(['status' => 'voicemail']);

        // Safety-net notification in case caller hangs up during greeting
        ProcessMissedCallJob::dispatch($log->id)->delay(now()->addSeconds(30));

        $twiml = $this->voice->generateVoicemailTwiml($log);

        return $this->twimlResponse($twiml);
    }

    /**
     * Handle outbound call connection — bridge the caller to the target number.
     * POST /webhooks/twilio/voice/outbound-connect/{callLog}
     */
    public function outboundConnect(Request $request, string $callLog): Response
    {
        if (! $this->twilio->validateWebhookSignature($request)) {
            return response('Forbidden', 403);
        }

        $log = CallLog::where('ulid', $callLog)->first();

        if (! $log) {
            return $this->twimlResponse('<Response><Hangup/></Response>');
        }

        // Mark the call as in-progress (caller has answered their phone)
        $log->update([
            'status' => 'in-progress',
            'answered_at' => now(),
            'answered_by_user_id' => $log->caller_user_id,
        ]);

        $this->safeBroadcast(new CallConnected($log, $log->caller_user_id));

        // Generate TwiML to dial the target number
        $response = new \Twilio\TwiML\VoiceResponse;

        $dialOptions = [
            'callerId' => $log->from_number,
        ];

        try {
            $dialOptions['action'] = route('webhooks.twilio.voice.dial-status', [
                'callLog' => $log->ulid,
            ]);
        } catch (\Throwable) {
            // Route may not exist yet
        }

        $dial = $response->dial('', $dialOptions);
        $dial->number($log->to_number);

        return $this->twimlResponse($response->__toString());
    }

    /**
     * Voicemail fallback for IVR no-input.
     * POST /webhooks/twilio/voice/voicemail-fallback/{callLog}
     */
    public function voicemailFallback(Request $request, string $callLog): Response
    {
        if (! $this->twilio->validateWebhookSignature($request)) {
            return response('Forbidden', 403);
        }

        $log = CallLog::where('ulid', $callLog)->first();

        if (! $log) {
            return $this->twimlResponse('<Response><Hangup/></Response>');
        }

        $log->update(['status' => 'voicemail']);

        // Safety-net notification: if caller hangs up during greeting before <Record>
        // fires, no recording callbacks will trigger. This ensures we still get notified.
        // The job is idempotent — it skips if already processed via recording callbacks.
        ProcessMissedCallJob::dispatch($log->id)->delay(now()->addSeconds(30));

        $twiml = $this->voice->generateVoicemailTwiml($log);

        return $this->twimlResponse($twiml);
    }

    /**
     * Try to resolve which user answered the call.
     */
    private function resolveAnsweringUser(Request $request, CallLog $callLog): ?int
    {
        // Primary: Use DialCallSid to fetch the child call and get the answered number
        $dialCallSid = $request->input('DialCallSid');

        if ($dialCallSid) {
            try {
                $childCall = $this->twilio->getClient()->calls($dialCallSid)->fetch();

                if ($childCall->to) {
                    $userId = $this->matchForwardingNumberToUser($childCall->to, $callLog->call_group_id);

                    if ($userId) {
                        return $userId;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Twilio voice: failed to fetch child call for user resolution', [
                    'dial_call_sid' => $dialCallSid,
                    'call_log_id' => $callLog->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: If call group has exactly one active member, it must be them
        if ($callLog->call_group_id) {
            $members = $callLog->callGroup?->members()
                ->where('is_active', true)
                ->whereNotNull('user_id')
                ->pluck('user_id');

            if ($members && $members->count() === 1) {
                return $members->first();
            }
        }

        return null;
    }

    /**
     * Match a phone number to a user by their forwarding number.
     * Checks call group member overrides first, then user defaults.
     */
    private function matchForwardingNumberToUser(string $phoneNumber, ?int $callGroupId = null): ?int
    {
        $formatted = $this->twilio->formatE164($phoneNumber);

        if (! $formatted) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $formatted);
        $last10 = substr($digits, -10);

        // Check call group member overrides first (more specific)
        if ($callGroupId) {
            $member = \App\Models\CallGroupMember::where('call_group_id', $callGroupId)
                ->where('forwarding_number', 'LIKE', '%'.$last10)
                ->where('is_active', true)
                ->first();

            if ($member?->user_id) {
                return $member->user_id;
            }
        }

        // Fall back to user's default forwarding number
        return \App\Models\User::where('forwarding_number', 'LIKE', '%'.$last10)->value('id');
    }

    /**
     * Play a whisper announcement to the answering agent before connecting.
     * POST /webhooks/twilio/voice/whisper/{callLog}
     */
    public function whisper(Request $request, string $callLog): Response
    {
        if (! $this->twilio->validateWebhookSignature($request)) {
            return response('Forbidden', 403);
        }

        $log = CallLog::where('ulid', $callLog)->first();

        if (! $log) {
            return $this->twimlResponse('<Response><Hangup/></Response>');
        }

        // Determine call type from metadata
        $metadata = $log->metadata ?? [];
        $extra = '';

        if (! empty($metadata['transferred'])) {
            $callType = 'Transferred call';
            // Include who transferred the call
            if (! empty($metadata['transferred_from_user_id'])) {
                try {
                    $transferredBy = \App\Models\User::find($metadata['transferred_from_user_id']);
                    if ($transferredBy) {
                        $extra = ', transferred by '.$transferredBy->name;
                    }
                } catch (\Throwable) {
                    // Skip if user lookup fails
                }
            }
        } elseif (! empty($metadata['extension_dialed'])) {
            $callType = 'Extension call';
        } elseif (! empty($metadata['call_group_name'])) {
            $callType = 'Inbound call for '.$metadata['call_group_name'];
        } else {
            $callType = 'Inbound call';
        }

        // Identify caller
        $callerName = $log->from_number;
        try {
            if ($log->customer_id) {
                $customer = $log->customer;
                if ($customer) {
                    $callerName = $customer->full_name ?: $log->from_number;
                }
            }
        } catch (\Throwable) {
            // Fall back to phone number
        }

        $response = new \Twilio\TwiML\VoiceResponse;
        $settings = \App\Models\CompanySetting::getInstance();
        $response->say("{$callType} from {$callerName}{$extra}.", ['voice' => $settings->tts_voice ?? 'Polly.Joanna']);

        return $this->twimlResponse($response->__toString());
    }

    private function twimlResponse(string $twiml): Response
    {
        // Ensure XML declaration is present
        if (! str_starts_with($twiml, '<?xml')) {
            $twiml = '<?xml version="1.0" encoding="UTF-8"?>'.$twiml;
        }

        return response($twiml, 200, [
            'Content-Type' => 'text/xml',
        ]);
    }

    private function safeBroadcast(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable $e) {
            Log::warning('Twilio voice webhook: broadcast failed', [
                'event' => get_class($event),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
