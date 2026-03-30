<?php

namespace App\Http\Controllers\Prospector;

use App\Events\Prospector\DialerCallConnected;
use App\Events\Prospector\DialerCallEnded;
use App\Http\Controllers\Controller;
use App\Models\Prospector\Call;
use App\Services\Prospector\AmdService;
use App\Services\Prospector\ProspectorSmsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ProspectorWebhookController extends Controller
{
    public function __construct(
        private AmdService $amdService,
        private ProspectorSmsService $smsService,
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

            broadcast(new DialerCallEnded($call->fresh(), $call->agent_id));
        }

        return response('', 200);
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
}
