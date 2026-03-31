<?php

namespace App\Http\Controllers\Prospector;

use App\Events\Prospector\DialerCallConnected;
use App\Events\Prospector\DialerCallEnded;
use App\Http\Controllers\Controller;
use App\Models\Prospector\Call;
use App\Models\Prospector\RvmDrop;
use App\Services\Prospector\AmdService;
use App\Services\Prospector\ProspectorSmsService;
use App\Services\Prospector\RvmService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ProspectorWebhookController extends Controller
{
    public function __construct(
        private AmdService $amdService,
        private ProspectorSmsService $smsService,
        private RvmService $rvmService,
    ) {}

    /**
     * POST /prospector/webhook/twilio/voice/status
     *
     * Twilio call status callback. Updates call record based on
     * the CallStatus param (completed, busy, no-answer, failed, canceled).
     */
    public function callStatus(Request $request): Response
    {
        $callSid = $request->input('CallSid');
        $status = $request->input('CallStatus');

        Log::info('Prospector call status webhook', [
            'call_sid' => $callSid,
            'status' => $status,
        ]);

        if (! $callSid || ! $status) {
            return response('', 200);
        }

        $call = Call::where('twilio_call_sid', $callSid)->first();

        if (! $call) {
            Log::warning('Prospector call status: call not found', ['call_sid' => $callSid]);

            return response('', 200);
        }

        $statusMap = [
            'completed' => 'completed',
            'busy' => 'busy',
            'no-answer' => 'no_answer',
            'failed' => 'failed',
            'canceled' => 'canceled',
        ];

        $mappedStatus = $statusMap[$status] ?? $status;

        $updateData = ['status' => $mappedStatus];

        // Handle answered/in-progress — broadcast connected event as fallback
        // (AMD callback handles this when AMD is enabled, but this covers non-AMD calls)
        if ($status === 'in-progress' && ! $call->answered_at) {
            $updateData['answered_at'] = now();
            $call->update($updateData);
            broadcast(new DialerCallConnected($call->fresh(), $call->agent_id));

            return response('', 200);
        }

        if (in_array($mappedStatus, ['completed', 'busy', 'no_answer', 'failed', 'canceled'])) {
            $updateData['ended_at'] = now();

            if ($request->has('CallDuration')) {
                $updateData['duration_seconds'] = (int) $request->input('CallDuration');
            }
        }

        $call->update($updateData);

        if (in_array($mappedStatus, ['completed', 'busy', 'no_answer', 'failed', 'canceled'])) {
            broadcast(new DialerCallEnded($call, $call->agent_id));
        }

        return response('', 200);
    }

    /**
     * POST /prospector/webhook/twilio/amd
     *
     * Async AMD detection result from Twilio.
     * If human: broadcast connected event for agent UI.
     * If machine: broadcast ended event and optionally leave voicemail.
     */
    public function amdCallback(Request $request): Response
    {
        $callSid = $request->input('CallSid');
        $answeredBy = $request->input('AnsweredBy', 'unknown');

        Log::info('Prospector AMD callback', [
            'call_sid' => $callSid,
            'answered_by' => $answeredBy,
        ]);

        if (! $callSid) {
            return response('', 200);
        }

        $call = Call::where('twilio_call_sid', $callSid)->first();

        if (! $call) {
            Log::warning('Prospector AMD callback: call not found', ['call_sid' => $callSid]);

            return response('', 200);
        }

        $this->amdService->handleAmdResult($call, $answeredBy);

        if ($this->amdService->isHuman($answeredBy)) {
            broadcast(new DialerCallConnected($call->fresh(), $call->agent_id));
        } else {
            $call->update([
                'status' => 'voicemail',
                'ended_at' => now(),
            ]);

            // Auto-VM drop if campaign has it enabled
            $this->attemptAutoVmDrop($call);

            broadcast(new DialerCallEnded($call->fresh(), $call->agent_id));
        }

        return response('', 200);
    }

    /**
     * Attempt automatic voicemail drop when AMD detects a machine.
     *
     * If the call's campaign has auto_vm_drop enabled with a default
     * RVM template, drop the voicemail and auto-disposition the call.
     */
    private function attemptAutoVmDrop(Call $call): void
    {
        $call->loadMissing(['campaign.defaultRvmTemplate', 'contact', 'fromPhoneNumber']);

        $campaign = $call->campaign;

        if (! $campaign || ! $campaign->auto_vm_drop || ! $campaign->default_rvm_template_id) {
            return;
        }

        $template = $campaign->defaultRvmTemplate;
        $company = $campaign->company;

        if (! $template || ! $company || ! $call->contact) {
            return;
        }

        try {
            $rvmService = app(\App\Services\Prospector\RvmService::class);
            $rvmService->drop($company, $call->contact, $template);

            // Auto-disposition as voicemail
            $call->update([
                'disposition' => 'voicemail',
                'disposition_notes' => 'Auto VM drop',
            ]);

            Log::info('Auto-VM dropped', [
                'call_id' => $call->id,
                'campaign_id' => $campaign->id,
                'template_id' => $template->id,
            ]);
        } catch (\Exception $e) {
            Log::warning('Auto-VM drop failed', [
                'call_id' => $call->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /prospector/webhook/twilio/conference/status
     *
     * Conference status events (start, end, participant-join, participant-leave).
     * Logged for debugging purposes.
     */
    public function conferenceStatus(Request $request): Response
    {
        Log::info('Prospector conference status', [
            'conference_sid' => $request->input('ConferenceSid'),
            'status_event' => $request->input('StatusCallbackEvent'),
            'friendly_name' => $request->input('FriendlyName'),
            'participant_sid' => $request->input('CallSid'),
        ]);

        return response('', 200);
    }

    /**
     * POST /prospector/webhook/twilio/sms/incoming
     *
     * Handle inbound SMS messages.
     * Find contact by phone number and log the message.
     */
    public function incomingSms(Request $request): Response
    {
        $from = $request->input('From');
        $to = $request->input('To');
        $body = $request->input('Body', '');
        $messageSid = $request->input('MessageSid');

        // Collect MMS media URLs
        $numMedia = (int) $request->input('NumMedia', 0);
        $mediaUrls = [];
        for ($i = 0; $i < $numMedia; $i++) {
            $url = $request->input("MediaUrl{$i}");
            if ($url) {
                $mediaUrls[] = $url;
            }
        }

        Log::info('Prospector incoming SMS', [
            'from' => $from,
            'to' => $to,
            'message_sid' => $messageSid,
            'body' => $body,
            'num_media' => $numMedia,
        ]);

        try {
            $this->smsService->handleIncoming(
                from: $from,
                to: $to,
                body: $body,
                mediaUrls: $mediaUrls ?: null,
                twilioSid: $messageSid,
            );
        } catch (\Exception $e) {
            Log::warning('Prospector incoming SMS processing failed', [
                'from' => $from,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }

        return response('', 200);
    }

    /**
     * POST /prospector/webhook/twilio/sms/status
     *
     * SMS delivery status callback.
     * Updates message delivery status.
     */
    public function smsStatus(Request $request): Response
    {
        $messageSid = $request->input('MessageSid');
        $messageStatus = $request->input('MessageStatus');

        Log::info('Prospector SMS status', [
            'message_sid' => $messageSid,
            'message_status' => $messageStatus,
            'to' => $request->input('To'),
        ]);

        if ($messageSid && $messageStatus) {
            if ($messageStatus === 'delivered') {
                $this->smsService->markDelivered($messageSid);
            } elseif (in_array($messageStatus, ['failed', 'undelivered'])) {
                $this->smsService->markFailed($messageSid, $request->input('ErrorMessage'));
            }
        }

        return response('', 200);
    }

    // ──────────────────────────────────────
    // RVM (Ringless Voicemail) Callbacks
    // ──────────────────────────────────────

    /**
     * POST /prospector/webhook/twilio/rvm/twiml/{drop}
     *
     * Returns TwiML to play the voicemail audio.
     * Called by Twilio when AMD detects a machine (voicemail).
     */
    public function rvmTwiml(Request $request, int $drop): Response
    {
        $rvmDrop = RvmDrop::with('template')->find($drop);

        if (! $rvmDrop || ! $rvmDrop->template) {
            Log::warning('RVM TwiML: drop or template not found', ['drop_id' => $drop]);

            return response('<Response><Hangup/></Response>', 200, [
                'Content-Type' => 'text/xml',
            ]);
        }

        $audioUrl = $rvmDrop->template->audio_path;
        if (! str_starts_with($audioUrl, 'http')) {
            $audioUrl = asset('storage/'.$audioUrl);
        }

        $twiml = '<Response><Play>'.htmlspecialchars($audioUrl).'</Play><Hangup/></Response>';

        return response($twiml, 200, [
            'Content-Type' => 'text/xml',
        ]);
    }

    /**
     * POST /prospector/webhook/twilio/rvm/amd/{drop}
     *
     * Async AMD result for RVM calls.
     * If machine detected: let the TwiML play the audio.
     * If human detected: hang up the call immediately.
     */
    public function rvmAmdCallback(Request $request, int $drop): Response
    {
        $answeredBy = $request->input('AnsweredBy', 'unknown');
        $callSid = $request->input('CallSid');

        Log::info('RVM AMD callback', [
            'drop_id' => $drop,
            'answered_by' => $answeredBy,
            'call_sid' => $callSid,
        ]);

        $rvmDrop = RvmDrop::find($drop);

        if (! $rvmDrop) {
            Log::warning('RVM AMD: drop not found', ['drop_id' => $drop]);

            return response('', 200);
        }

        // If a human answered, hang up — we only want voicemail
        if (in_array($answeredBy, ['human', 'unknown'])) {
            $rvmDrop->markFailed("Human answered ({$answeredBy}), call terminated");

            // Hang up the call via Twilio
            if ($callSid) {
                try {
                    $rvmDrop->loadMissing('company.mainPhoneNumber.twilioAccount');
                    $account = $rvmDrop->company->mainPhoneNumber?->twilioAccount;
                    if ($account) {
                        $client = $account->getClient();
                        $client->calls($callSid)->update(['status' => 'completed']);
                    }
                } catch (\Exception $e) {
                    Log::warning('RVM AMD: failed to hang up human call', [
                        'drop_id' => $drop,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Machine detected — TwiML handler will play the audio

        return response('', 200);
    }

    /**
     * POST /prospector/webhook/twilio/rvm/status/{drop}
     *
     * Twilio RVM call status callback.
     */
    public function rvmStatusCallback(Request $request, int $drop): Response
    {
        $callStatus = $request->input('CallStatus');

        Log::info('RVM status callback', [
            'drop_id' => $drop,
            'call_status' => $callStatus,
        ]);

        $rvmDrop = RvmDrop::find($drop);

        if (! $rvmDrop) {
            return response('', 200);
        }

        match ($callStatus) {
            'completed' => $rvmDrop->markDelivered(),
            'busy', 'no-answer', 'failed', 'canceled' => $rvmDrop->markFailed("Call status: {$callStatus}"),
            default => null,
        };

        return response('', 200);
    }

    /**
     * POST /prospector/webhook/twilio/rvm/callback
     *
     * Slybroadcast delivery callback.
     */
    public function rvmCallback(Request $request): Response
    {
        $providerId = $request->input('session_id') ?? $request->input('provider_id');
        $status = $request->input('status', 'delivered');

        Log::info('RVM provider callback', [
            'provider_id' => $providerId,
            'status' => $status,
        ]);

        if ($providerId) {
            $this->rvmService->handleCallback($providerId, $status);
        }

        return response('', 200);
    }
}
