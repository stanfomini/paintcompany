<?php

namespace App\Services;

use App\Models\CallGroup;
use App\Models\CallLog;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Twilio\TwiML\VoiceResponse;

class TwilioVoiceService
{
    public function __construct(
        public TwilioService $twilio,
        public CallRoutingService $routing
    ) {}

    /**
     * Check if voice calling is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->twilio->isEnabled()
            && (bool) config('services.twilio.voice_enabled');
    }

    /**
     * Generate TwiML to ring all members of a call group.
     */
    public function generateRingGroupTwiml(CallGroup $group, CallLog $callLog): string
    {
        $response = new VoiceResponse;
        $numbers = $group->getActiveForwardingNumbers();

        if (empty($numbers)) {
            Log::warning('TwilioVoice: call group has no active numbers, going to voicemail', [
                'call_group_id' => $group->id,
                'call_log_id' => $callLog->id,
            ]);

            return $this->generateVoicemailTwiml($callLog);
        }

        $settings = CompanySetting::getInstance();

        $dialOptions = [
            'timeout' => $group->ring_timeout_seconds,
            'callerId' => config('services.twilio.from'),
        ];

        // Set action URL for handling what happens after dial attempt
        try {
            $dialOptions['action'] = route('webhooks.twilio.voice.dial-status', [
                'callLog' => $callLog->ulid,
            ]);
        } catch (\Throwable) {
            // Route may not exist yet
        }

        // Enable recording if configured
        if ($settings->call_recording_enabled) {
            $dialOptions['record'] = 'record-from-answer-dual';

            try {
                $dialOptions['recordingStatusCallback'] = route('webhooks.twilio.voice.recording');
            } catch (\Throwable) {
                // Route may not exist yet
            }
        }

        $dial = $response->dial('', $dialOptions);

        $numberAttrs = [];
        try {
            $numberAttrs['url'] = route('webhooks.twilio.voice.whisper', ['callLog' => $callLog->ulid]);
        } catch (\Throwable) {
            // Route may not exist yet
        }

        foreach ($numbers as $number) {
            $dial->number($number, $numberAttrs);
        }

        return $response->__toString();
    }

    /**
     * Generate voicemail TwiML with greeting and recording.
     */
    public function generateVoicemailTwiml(CallLog $callLog): string
    {
        $response = new VoiceResponse;
        $settings = CompanySetting::getInstance();

        // Play greeting
        if ($settings->voicemail_greeting_type === 'upload' && $settings->voicemail_greeting_path) {
            $response->play(storage_url($settings->voicemail_greeting_path));
        } else {
            $greetingText = $settings->voicemail_greeting_text
                ?? 'We are unable to take your call right now. Please leave a message after the beep and we will get back to you as soon as possible.';
            $response->say($greetingText, ['voice' => 'Polly.Joanna']);
        }

        // Record voicemail
        $recordOptions = [
            'maxLength' => 120,
            'transcribe' => true,
            'playBeep' => true,
        ];

        try {
            $recordOptions['action'] = route('webhooks.twilio.voice.voicemail', [
                'callLog' => $callLog->ulid,
            ]);
            $recordOptions['transcribeCallback'] = route('webhooks.twilio.voice.transcription', [
                'callLog' => $callLog->ulid,
            ]);
            $recordOptions['recordingStatusCallback'] = route('webhooks.twilio.voice.recording');
        } catch (\Throwable) {
            // Routes may not exist yet
        }

        $response->record($recordOptions);

        // If they hang up without leaving a message
        $response->say('We did not receive a recording. Goodbye.', ['voice' => 'Polly.Joanna']);
        $response->hangup();

        return $response->__toString();
    }

    /**
     * Generate IVR menu TwiML.
     */
    public function generateIvrMenuTwiml(CallLog $callLog): string
    {
        $response = new VoiceResponse;
        $settings = CompanySetting::getInstance();
        $menu = $settings->ivr_menu ?? [];
        $options = $menu['options'] ?? [];
        $extensionPrompt = $menu['extension_prompt'] ?? false;

        $gatherOptions = [
            'input' => 'dtmf',
            'timeout' => 5,
        ];

        // If extensions are enabled, don't limit digits (let finishOnKey="#" handle it)
        // Otherwise, limit to single digit for simple menu selection
        if ($extensionPrompt) {
            $gatherOptions['finishOnKey'] = '#';
        } else {
            $gatherOptions['numDigits'] = 1;
        }

        try {
            $gatherOptions['action'] = route('webhooks.twilio.voice.ivr-select', [
                'callLog' => $callLog->ulid,
            ]);
        } catch (\Throwable) {
            // Route may not exist yet
        }

        $gather = $response->gather($gatherOptions);

        // Build menu text from IVR options
        $menuText = 'Thank you for calling. ';
        foreach ($options as $option) {
            $digit = $option['digit'] ?? '';
            $label = $option['label'] ?? "Option {$digit}";
            $menuText .= "Press {$digit} for {$label}. ";
        }

        if ($extensionPrompt) {
            $menuText .= 'Or dial your party\'s extension at any time.';
        }

        $gather->say($menuText, ['voice' => 'Polly.Joanna']);

        // No input fallback: go to voicemail
        $response->say('We did not receive your selection.', ['voice' => 'Polly.Joanna']);
        $response->redirect(route('webhooks.twilio.voice.voicemail-fallback', [
            'callLog' => $callLog->ulid,
        ]));

        return $response->__toString();
    }

    /**
     * Initiate an outbound call via Twilio.
     *
     * Calls the user's forwarding number first, then bridges to the target.
     *
     * @throws \InvalidArgumentException If user has no forwarding number or number is invalid
     * @throws \Twilio\Exceptions\TwilioException If Twilio API call fails
     */
    public function initiateOutboundCall(User $user, string $toNumber): CallLog
    {
        if (empty($user->forwarding_number)) {
            throw new \InvalidArgumentException('User does not have a forwarding number configured.');
        }

        $formatted = $this->twilio->formatE164($toNumber);

        if (! $formatted) {
            throw new \InvalidArgumentException('Invalid phone number format.');
        }

        // Create call log before making the API call
        $callLog = CallLog::create([
            'twilio_call_sid' => 'pending-'.uniqid(),
            'direction' => 'outbound',
            'from_number' => config('services.twilio.from'),
            'to_number' => $formatted,
            'caller_user_id' => $user->id,
            'status' => 'ringing',
            'started_at' => now(),
        ]);

        // Build Twilio call options
        $options = [
            'url' => route('webhooks.twilio.voice.outbound-connect', ['callLog' => $callLog->ulid]),
            'statusCallback' => route('webhooks.twilio.voice.status'),
            'statusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
        ];

        // Enable recording if configured
        $settings = CompanySetting::getInstance();
        if ($settings->call_recording_enabled) {
            $options['record'] = 'record-from-answer-dual';

            try {
                $options['recordingStatusCallback'] = route('webhooks.twilio.voice.recording');
            } catch (\Throwable) {
                // Route may not exist yet
            }
        }

        // Call the user's forwarding number; when they answer, the URL TwiML will dial the target
        $twilioCall = $this->twilio->getClient()->calls->create(
            $user->forwarding_number,
            config('services.twilio.from'),
            $options
        );

        // Update call log with the real Twilio SID
        $callLog->update(['twilio_call_sid' => $twilioCall->sid]);

        Log::info('TwilioVoice: outbound call initiated', [
            'call_log_id' => $callLog->id,
            'to' => $formatted,
            'twilio_sid' => $twilioCall->sid,
        ]);

        return $callLog;
    }

    /**
     * Transfer an active call to another user by extension.
     *
     * @throws \InvalidArgumentException If target user not found or has no forwarding number
     * @throws \Twilio\Exceptions\TwilioException If Twilio API call fails
     */
    public function transferCall(string $callSid, string $targetExtension): CallLog
    {
        $targetUser = User::where('extension', $targetExtension)
            ->where('type', 'internal')
            ->first();

        if (! $targetUser) {
            throw new \InvalidArgumentException("No internal user found with extension {$targetExtension}.");
        }

        if (empty($targetUser->forwarding_number)) {
            throw new \InvalidArgumentException("User {$targetUser->name} does not have a forwarding number configured.");
        }

        $callLog = CallLog::where('twilio_call_sid', $callSid)->firstOrFail();

        // Build TwiML to dial the target user's forwarding number
        $response = new VoiceResponse;
        $dialOptions = [
            'callerId' => config('services.twilio.from'),
        ];

        try {
            $dialOptions['action'] = route('webhooks.twilio.voice.dial-status', [
                'callLog' => $callLog->ulid,
            ]);
        } catch (\Throwable) {
            // Route may not exist yet
        }

        $dial = $response->dial('', $dialOptions);

        $numberAttrs = [];
        try {
            $numberAttrs['url'] = route('webhooks.twilio.voice.whisper', ['callLog' => $callLog->ulid]);
        } catch (\Throwable) {
            // Route may not exist yet
        }

        $dial->number($targetUser->forwarding_number, $numberAttrs);

        // Update the live call via Twilio REST API
        $this->twilio->getClient()->calls($callSid)->update([
            'twiml' => $response->__toString(),
        ]);

        // Track the transfer in the call log
        $previousUserId = $callLog->answered_by_user_id;
        $callLog->update([
            'answered_by_user_id' => $targetUser->id,
            'metadata' => array_merge($callLog->metadata ?? [], [
                'transferred' => true,
                'transferred_from_user_id' => $previousUserId,
                'transferred_to_user_id' => $targetUser->id,
                'transferred_at' => now()->toISOString(),
            ]),
        ]);

        Log::info('TwilioVoice: call transferred', [
            'call_log_id' => $callLog->id,
            'call_sid' => $callSid,
            'from_user_id' => $previousUserId,
            'to_user_id' => $targetUser->id,
            'target_extension' => $targetExtension,
        ]);

        return $callLog;
    }
}
