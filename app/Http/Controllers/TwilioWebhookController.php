<?php

namespace App\Http\Controllers;

use App\Models\SmsSession;
use App\Models\User;
use App\Services\ChatService;
use App\Services\TwilioService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class TwilioWebhookController extends Controller
{
    public function __construct(
        protected TwilioService $twilio,
        protected ChatService $chatService
    ) {}

    public function receive(Request $request): Response
    {
        if (! $this->twilio->validateWebhookSignature($request)) {
            Log::warning('Twilio webhook: invalid signature');

            return response('Forbidden', 403);
        }

        $from = $request->input('From', '');
        $body = trim($request->input('Body', ''));

        if (empty($from) || empty($body)) {
            return $this->twimlResponse();
        }

        // Handle opt-out/opt-in keywords (TCPA compliance)
        $keyword = strtoupper($body);

        if (in_array($keyword, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'])) {
            $this->handleOptOut($from);

            return $this->twimlResponse();
        }

        if (in_array($keyword, ['START', 'YES', 'UNSTOP'])) {
            $this->handleOptIn($from);

            return $this->twimlResponse();
        }

        // Look up active SMS session for this phone number
        $formattedPhone = $this->twilio->formatE164($from);

        if (! $formattedPhone) {
            return $this->twimlResponse();
        }

        $session = SmsSession::findActiveByPhone($formattedPhone);

        if (! $session) {
            Log::info('Twilio webhook: no active session for phone', ['from' => $formattedPhone]);

            return $this->twimlResponse();
        }

        try {
            $session->load(['user', 'thread']);

            if (! $session->user || ! $session->thread) {
                return $this->twimlResponse();
            }

            // Post the reply into the chat thread
            $this->chatService->sendMessage(
                thread: $session->thread,
                sender: $session->user,
                content: $body,
                options: ['metadata' => ['source' => 'sms']],
            );

            // Extend session TTL
            $session->update(['expires_at' => now()->addHours(24)]);
        } catch (\Exception $e) {
            Log::error('Twilio webhook: failed to process reply', [
                'from' => $formattedPhone,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->twimlResponse();
    }

    private function handleOptOut(string $phone): void
    {
        $formattedPhone = $this->twilio->formatE164($phone);

        if (! $formattedPhone) {
            return;
        }

        // Find users with this phone number and mark them as opted out
        $this->findUsersByPhone($formattedPhone)->each(function (User $user) {
            $user->update(['sms_opted_out_at' => now()]);
        });

        Log::info('Twilio webhook: opt-out processed', ['phone' => $formattedPhone]);
    }

    private function handleOptIn(string $phone): void
    {
        $formattedPhone = $this->twilio->formatE164($phone);

        if (! $formattedPhone) {
            return;
        }

        // Find opted-out users with this phone and clear the opt-out
        $this->findUsersByPhone($formattedPhone)
            ->filter(fn (User $user) => $user->isSmsOptedOut())
            ->each(function (User $user) {
                $user->update(['sms_opted_out_at' => null]);
            });

        Log::info('Twilio webhook: opt-in processed', ['phone' => $formattedPhone]);
    }

    /**
     * Find users who have this phone number in their SMS sessions.
     */
    private function findUsersByPhone(string $phone): \Illuminate\Support\Collection
    {
        $userIds = SmsSession::where('phone_number', $phone)
            ->distinct()
            ->pluck('user_id');

        return User::whereIn('id', $userIds)->get();
    }

    private function twimlResponse(): Response
    {
        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, [
            'Content-Type' => 'text/xml',
        ]);
    }
}
