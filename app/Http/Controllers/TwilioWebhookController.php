<?php

namespace App\Http\Controllers;

use App\Events\NewThreadNotification;
use App\Models\AgentProfile;
use App\Models\ChatThread;
use App\Models\CrewProfile;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
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
            Log::error('Twilio webhook: invalid signature');

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

        if ($session) {
            $this->routeToExistingSession($session, $body, $formattedPhone);
        } else {
            $this->routeColdInbound($formattedPhone, $body);
        }

        return $this->twimlResponse();
    }

    /**
     * Route a reply to an existing active SMS session.
     */
    private function routeToExistingSession(SmsSession $session, string $body, string $phone): void
    {
        try {
            $session->load(['user', 'thread']);

            if (! $session->thread) {
                return;
            }

            if ($session->user) {
                $this->chatService->sendMessage(
                    thread: $session->thread,
                    sender: $session->user,
                    content: $body,
                    options: ['metadata' => ['source' => 'sms']],
                );
            } else {
                $this->chatService->sendGuestMessage(
                    thread: $session->thread,
                    content: $body,
                    metadata: ['source' => 'sms', 'phone' => $phone],
                );
            }

            $session->update(['expires_at' => now()->addHours(24)]);
        } catch (\Exception $e) {
            Log::error('Twilio webhook: failed to process reply', [
                'from' => $phone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Route a cold inbound SMS (no active session) into the chat system.
     */
    private function routeColdInbound(string $phone, string $body): void
    {
        try {
            $users = $this->findUsersByPhone($phone);
            $sender = $users->first();

            if ($sender) {
                $this->routeKnownUser($sender, $phone, $body);
            } else {
                $this->routeUnknownNumber($phone, $body);
            }
        } catch (\Exception $e) {
            Log::error('Twilio webhook: failed to route cold inbound', [
                'from' => $phone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Route an SMS from a known user into a chat thread.
     */
    private function routeKnownUser(User $sender, string $phone, string $body): void
    {
        // Check if sender is a customer with an assigned internal contact
        $customer = Customer::where('user_id', $sender->id)->first();

        if ($customer) {
            $internalUser = $customer->assignedTo
                ?? User::where('type', 'internal')->where('is_active', true)->first();

            if (! $internalUser) {
                Log::error('Twilio webhook: no internal user to route customer SMS to', ['phone' => $phone]);

                return;
            }

            $thread = $this->chatService->getOrCreateDirectThread(
                $sender,
                $internalUser,
                'SMS conversation',
            );
        } else {
            // Known non-customer user (crew, agent, etc.) — create support thread
            $thread = $this->createSmsSupporThread($phone);
            $thread->addParticipant($sender);
            $isNewSupportThread = true;
        }

        $this->chatService->sendMessage(
            thread: $thread,
            sender: $sender,
            content: $body,
            options: ['metadata' => ['source' => 'sms']],
        );

        SmsSession::updateOrCreate(
            ['phone_number' => $phone, 'thread_id' => $thread->id],
            [
                'user_id' => $sender->id,
                'expires_at' => now()->addHours(24),
            ]
        );

        // Broadcast new thread notification AFTER message is saved (non-critical)
        if (! empty($isNewSupportThread)) {
            $this->safeBroadcastNewThread($thread);
        }

        Log::info('Twilio webhook: routed cold inbound from known user', [
            'from' => $phone,
            'user_id' => $sender->id,
            'thread_id' => $thread->id,
        ]);
    }

    /**
     * Route an SMS from an unknown number into a support thread.
     */
    private function routeUnknownNumber(string $phone, string $body): void
    {
        $thread = $this->createSmsSupporThread($phone);

        $this->chatService->sendGuestMessage(
            thread: $thread,
            content: $body,
            metadata: ['source' => 'sms', 'phone' => $phone],
        );

        SmsSession::create([
            'user_id' => null,
            'thread_id' => $thread->id,
            'phone_number' => $phone,
            'expires_at' => now()->addHours(24),
        ]);

        // Broadcast new thread notification AFTER message is saved (non-critical)
        $this->safeBroadcastNewThread($thread);

        Log::info('Twilio webhook: routed cold inbound from unknown number', [
            'from' => $phone,
            'thread_id' => $thread->id,
        ]);
    }

    /**
     * Create a support thread for inbound SMS and add internal users with chat.view_website_leads.
     */
    private function createSmsSupporThread(string $phone): ChatThread
    {
        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => ChatThread::SOURCE_SMS_INBOUND,
            'subject' => "SMS from {$phone}",
            'last_message_at' => now(),
        ]);

        // Add internal users who can view website leads (same permission gates SMS threads)
        $internalUsers = User::where('type', 'internal')
            ->where('is_active', true)
            ->permission('chat.view_website_leads')
            ->get();

        // Fallback: if no users matched permission scope, add all active internal users
        if ($internalUsers->isEmpty()) {
            $internalUsers = User::where('type', 'internal')
                ->where('is_active', true)
                ->get();
        }

        foreach ($internalUsers as $user) {
            $thread->addParticipant($user);
        }

        return $thread;
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
     * Find users who have this phone number in sessions or profiles.
     */
    private function findUsersByPhone(string $phone): \Illuminate\Support\Collection
    {
        // Strip to last 10 digits for profile matching (US numbers, stored in various formats)
        $digits = preg_replace('/\D/', '', $phone);
        $last10 = substr($digits, -10);
        $phoneLike = '%'.$last10;

        // Collect user IDs from SMS sessions
        $userIds = SmsSession::where('phone_number', $phone)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->toArray();

        // Search users.phone directly
        $directUserIds = User::where('phone', 'LIKE', $phoneLike)
            ->pluck('id')
            ->toArray();

        // Search profile tables
        $profileUserIds = collect()
            ->merge(CustomerProfile::where('phone', 'LIKE', $phoneLike)->pluck('user_id'))
            ->merge(AgentProfile::where('phone', 'LIKE', $phoneLike)->pluck('user_id'))
            ->merge(CrewProfile::where('phone', 'LIKE', $phoneLike)->pluck('user_id'))
            ->merge(EmployeeProfile::where('phone', 'LIKE', $phoneLike)->pluck('user_id'))
            ->toArray();

        $allUserIds = collect(array_merge($userIds, $directUserIds, $profileUserIds))
            ->unique()
            ->values();

        return User::whereIn('id', $allUserIds)->get();
    }

    /**
     * Safely broadcast NewThreadNotification, catching any broadcast failures.
     */
    private function safeBroadcastNewThread(ChatThread $thread): void
    {
        try {
            event(new NewThreadNotification($thread));
        } catch (\Throwable $e) {
            Log::warning('Twilio webhook: broadcast failed', [
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function twimlResponse(): Response
    {
        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, [
            'Content-Type' => 'text/xml',
        ]);
    }
}
