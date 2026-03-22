<?php

namespace App\Http\Controllers;

use App\Events\Automation\InboundSmsReceived;
use App\Events\NewThreadNotification;
use App\Models\AgentProfile;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\CompanySetting;
use App\Models\CrewProfile;
use App\Models\Customer;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\SmsBotFlow;
use App\Models\SmsConversation;
use App\Models\SmsLog;
use App\Models\SmsSession;
use App\Models\User;
use App\Services\ChatService;
use App\Services\SmsBotFlowService;
use App\Services\TwilioService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        // Extract MMS media URLs (photos can arrive with empty body)
        $numMedia = (int) $request->input('NumMedia', 0);
        $mediaUrls = [];
        for ($i = 0; $i < $numMedia; $i++) {
            $mediaUrls[] = $request->input("MediaUrl{$i}");
        }

        if (empty($from) || (empty($body) && empty($mediaUrls))) {
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

        if (in_array($keyword, ['HELP', 'INFO'])) {
            $this->handleHelp($from);

            return $this->twimlResponse();
        }

        // Log inbound SMS for audit trail
        try {
            SmsLog::create([
                'phone' => $from,
                'direction' => 'inbound',
                'body' => mb_substr($body, 0, 160),
                'status' => 'received',
                'message_type' => 'service',
                'twilio_sid' => $request->input('MessageSid'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log inbound SMS', ['error' => $e->getMessage()]);
        }

        // Look up active SMS session for this phone number
        $formattedPhone = $this->twilio->formatE164($from);

        if (! $formattedPhone) {
            return $this->twimlResponse();
        }

        // Check for active SMS bot conversation FIRST (before normal session routing)
        $botConvo = SmsConversation::findActiveByPhone($formattedPhone);
        if ($botConvo) {
            app(SmsBotFlowService::class)->processResponse($botConvo, $body, $mediaUrls);

            return $this->twimlResponse();
        }

        // Check for keyword-triggered bot flow
        $keywordFlow = SmsBotFlow::findByKeyword($body);
        if ($keywordFlow) {
            $conversation = app(SmsBotFlowService::class)->startConversation($formattedPhone, $keywordFlow);
            if ($conversation) {
                return $this->twimlResponse();
            }
            // Fall through to normal routing if consent check blocked the flow
        }

        $session = SmsSession::findActiveByPhone($formattedPhone);

        if ($session) {
            $this->routeToExistingSession($session, $body, $formattedPhone, $mediaUrls);
        } else {
            $this->routeColdInbound($formattedPhone, $body, $mediaUrls);
        }

        // Dispatch automation event for known customers
        $this->dispatchInboundSmsEvent($formattedPhone, $body);

        return $this->twimlResponse();
    }

    /**
     * Route a reply to an existing active SMS session.
     */
    private function routeToExistingSession(SmsSession $session, string $body, string $phone, array $mediaUrls = []): void
    {
        try {
            $session->load(['user', 'thread']);

            if (! $session->thread) {
                return;
            }

            if ($body !== '') {
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
            }

            $this->createMediaAttachments($session->thread, $mediaUrls, ['source' => 'sms', 'phone' => $phone]);

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
    private function routeColdInbound(string $phone, string $body, array $mediaUrls = []): void
    {
        try {
            $users = $this->findUsersByPhone($phone);
            $sender = $users->first();

            if ($sender) {
                $this->routeKnownUser($sender, $phone, $body, $mediaUrls);
            } else {
                // Check if phone matches a Customer record without a user account
                $customer = $this->findCustomerByPhone($phone);

                if ($customer) {
                    $this->routeKnownCustomerWithoutAccount($customer, $phone, $body, $mediaUrls);
                } else {
                    $this->routeUnknownNumber($phone, $body, $mediaUrls);
                }
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
    private function routeKnownUser(User $sender, string $phone, string $body, array $mediaUrls = []): void
    {
        // Check if sender is a customer with an assigned internal contact
        $customer = Customer::where('user_id', $sender->id)->first();

        if ($customer) {
            // Check for active deal with existing chat thread first (context lives there)
            $activeDeal = $customer->deals()
                ->active()
                ->whereHas('chatThread')
                ->latest()
                ->first();

            if ($activeDeal && $activeDeal->chatThread) {
                $thread = $activeDeal->chatThread;
                // Ensure customer is a participant
                if (! $thread->hasParticipant($sender)) {
                    $thread->addParticipant($sender);
                }
            } else {
                // Fall back to direct thread with assigned contact
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
            }

            // Ensure customer_id is set on the thread for communications hub lookup
            if (! $thread->customer_id) {
                $thread->update(['customer_id' => $customer->id]);
            }
        } else {
            // Known non-customer user (crew, agent, etc.) — create support thread
            $thread = $this->findOrCreateSmsThread($phone);
            $thread->addParticipant($sender);
            $isNewSupportThread = true;
        }

        if ($body !== '') {
            $this->chatService->sendMessage(
                thread: $thread,
                sender: $sender,
                content: $body,
                options: ['metadata' => ['source' => 'sms']],
            );
        }

        $this->createMediaAttachments($thread, $mediaUrls, ['source' => 'sms', 'phone' => $phone]);

        SmsSession::updateOrCreate(
            ['phone_number' => $phone, 'thread_id' => $thread->id],
            [
                'user_id' => $sender->id,
                'customer_id' => $customer?->id,
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
    private function routeUnknownNumber(string $phone, string $body, array $mediaUrls = []): void
    {
        // Check if phone matches any Customer record (with or without user_id)
        $customer = $this->findCustomerByPhone($phone)
            ?? $this->findCustomerByPhoneIncludingLinked($phone);

        $thread = $this->findOrCreateSmsThread($phone, $customer);
        $isNewThread = $thread->wasRecentlyCreated;

        if ($body !== '') {
            $this->chatService->sendGuestMessage(
                thread: $thread,
                content: $body,
                metadata: ['source' => 'sms', 'phone' => $phone, 'customer_id' => $customer?->id],
            );
        }

        $this->createMediaAttachments($thread, $mediaUrls, ['source' => 'sms', 'phone' => $phone, 'customer_id' => $customer?->id]);

        SmsSession::updateOrCreate(
            ['phone_number' => $phone, 'thread_id' => $thread->id],
            [
                'user_id' => null,
                'customer_id' => $customer?->id,
                'expires_at' => now()->addHours(24),
            ]
        );

        if ($isNewThread) {
            $this->safeBroadcastNewThread($thread);
        }

        Log::info('Twilio webhook: routed cold inbound from unknown number', [
            'from' => $phone,
            'thread_id' => $thread->id,
            'customer_id' => $customer?->id,
            'reused_thread' => ! $isNewThread,
        ]);
    }

    /**
     * Route an SMS from a known Customer who has no user account.
     */
    private function routeKnownCustomerWithoutAccount(Customer $customer, string $phone, string $body, array $mediaUrls = []): void
    {
        $thread = $this->findOrCreateSmsThread($phone, $customer);
        $isNewThread = $thread->wasRecentlyCreated;

        if ($body !== '') {
            $this->chatService->sendGuestMessage(
                thread: $thread,
                content: $body,
                metadata: ['source' => 'sms', 'phone' => $phone, 'customer_id' => $customer->id],
            );
        }

        $this->createMediaAttachments($thread, $mediaUrls, ['source' => 'sms', 'phone' => $phone, 'customer_id' => $customer->id]);

        SmsSession::updateOrCreate(
            ['phone_number' => $phone, 'thread_id' => $thread->id],
            [
                'user_id' => null,
                'customer_id' => $customer->id,
                'expires_at' => now()->addHours(24),
            ]
        );

        if ($isNewThread) {
            $this->safeBroadcastNewThread($thread);
        }

        Log::info('Twilio webhook: routed cold inbound from known customer without account', [
            'from' => $phone,
            'customer_id' => $customer->id,
            'thread_id' => $thread->id,
            'reused_thread' => ! $isNewThread,
        ]);
    }

    /**
     * Find an existing SMS support thread for this phone, or create a new one.
     * Prevents duplicate threads when the same number texts after session expiry.
     */
    private function findOrCreateSmsThread(string $phone, ?Customer $customer = null): ChatThread
    {
        $digits = preg_replace('/\D/', '', $phone);
        $last10 = substr($digits, -10);

        // Search for an existing SMS inbound support thread for this phone
        $existingThread = ChatThread::where('type', ChatThread::TYPE_SUPPORT)
            ->where('source', ChatThread::SOURCE_SMS_INBOUND)
            ->where('subject', 'LIKE', "%{$last10}%")
            ->latest('last_message_at')
            ->first();

        if ($existingThread) {
            if ($customer && ! $existingThread->customer_id) {
                $existingThread->update(['customer_id' => $customer->id]);
            }

            $existingThread->update(['last_message_at' => now()]);

            return $existingThread;
        }

        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => ChatThread::SOURCE_SMS_INBOUND,
            'customer_id' => $customer?->id,
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

    /**
     * Handle Twilio delivery status callbacks.
     * Logs carrier-level failures (e.g., error 30034 = message blocked by carrier).
     */
    public function deliveryStatus(Request $request): Response
    {
        if (! $this->twilio->validateWebhookSignature($request)) {
            Log::error('Twilio status webhook: invalid signature');

            return response('Forbidden', 403);
        }

        $status = $request->input('MessageStatus', '');
        $errorCode = $request->input('ErrorCode');
        $sid = $request->input('MessageSid', '');
        $to = $request->input('To', '');

        if (in_array($status, ['undelivered', 'failed'])) {
            Log::error('Twilio SMS delivery failure', [
                'sid' => $sid,
                'to' => $to,
                'status' => $status,
                'error_code' => $errorCode,
                'error_message' => $request->input('ErrorMessage', ''),
            ]);
        }

        return response('OK', 200);
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

        // Abandon active bot conversations for this phone
        SmsConversation::where('phone', $formattedPhone)
            ->where('status', 'in_progress')
            ->update(['status' => 'abandoned', 'completed_at' => now()]);

        Log::info('Twilio webhook: opt-out processed', ['phone' => $formattedPhone]);
    }

    private function handleHelp(string $phone): void
    {
        $settings = CompanySetting::getInstance();
        $companyName = $settings->company_name ?? config('app.name', 'Our Company');
        $companyPhone = $settings->company_phone ?? '';
        $companyEmail = $settings->company_email ?? '';

        $parts = ["{$companyName}: For help"];
        if ($companyPhone) {
            $parts[] = "call {$companyPhone}";
        }
        if ($companyEmail) {
            $parts[] = "or email {$companyEmail}";
        }

        $message = implode(' ', $parts).'. Reply STOP to opt out. Msg&data rates apply.';

        $this->twilio->sendSms($phone, $message, ['message_type' => 'system']);
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

        // Search customers table (CRM phone field, may differ from profile phone)
        $customerUserIds = Customer::where('phone', 'LIKE', $phoneLike)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->toArray();

        $allUserIds = collect(array_merge($userIds, $directUserIds, $profileUserIds, $customerUserIds))
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

    /**
     * Find a Customer record by phone that has NO user account.
     */
    private function findCustomerByPhone(string $phone): ?Customer
    {
        $digits = preg_replace('/\D/', '', $phone);
        $last10 = substr($digits, -10);
        $phoneLike = '%'.$last10;

        return Customer::where('phone', 'LIKE', $phoneLike)
            ->whereNull('user_id')
            ->first();
    }

    /**
     * Find a Customer record by phone including those with user accounts.
     * Used as a fallback in routeUnknownNumber to link threads to customers.
     */
    private function findCustomerByPhoneIncludingLinked(string $phone): ?Customer
    {
        $digits = preg_replace('/\D/', '', $phone);
        $last10 = substr($digits, -10);
        $phoneLike = '%'.$last10;

        return Customer::where('phone', 'LIKE', $phoneLike)->first();
    }

    /**
     * Download Twilio media URLs and store as ChatMessage + ChatAttachment records.
     */
    private function createMediaAttachments(ChatThread $thread, array $mediaUrls, array $metadata = []): void
    {
        foreach ($mediaUrls as $mediaUrl) {
            try {
                $response = Http::get($mediaUrl);

                if ($response->failed()) {
                    Log::warning('Twilio webhook: failed to download media', [
                        'url' => $mediaUrl,
                        'status' => $response->status(),
                    ]);

                    continue;
                }

                $contentType = $response->header('Content-Type', 'image/jpeg');
                $extension = $this->guessExtensionFromMime($contentType);
                $filename = Str::uuid().'.'.$extension;
                $path = 'chat-attachments/'.$filename;

                Storage::put($path, $response->body());

                $message = ChatMessage::create([
                    'thread_id' => $thread->id,
                    'user_id' => null,
                    'content' => '',
                    'type' => ChatMessage::TYPE_IMAGE,
                    'metadata' => $metadata,
                ]);

                ChatAttachment::create([
                    'message_id' => $message->id,
                    'path' => $path,
                    'filename' => $filename,
                    'original_filename' => basename(parse_url($mediaUrl, PHP_URL_PATH) ?? $filename),
                    'mime_type' => $contentType,
                    'file_size' => strlen($response->body()),
                ]);
            } catch (\Exception $e) {
                Log::error('Twilio webhook: failed to store media attachment', [
                    'url' => $mediaUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Guess file extension from MIME type.
     */
    private function guessExtensionFromMime(string $mimeType): string
    {
        return match (true) {
            str_contains($mimeType, 'png') => 'png',
            str_contains($mimeType, 'webp') => 'webp',
            str_contains($mimeType, 'heic') => 'heic',
            str_contains($mimeType, 'gif') => 'gif',
            str_contains($mimeType, 'mp4') => 'mp4',
            str_contains($mimeType, 'pdf') => 'pdf',
            default => 'jpg',
        };
    }

    private function dispatchInboundSmsEvent(string $phone, string $body): void
    {
        try {
            $digits = preg_replace('/\D/', '', $phone);
            $last10 = substr($digits, -10);

            $customer = Customer::where('phone', 'LIKE', '%'.$last10)->first();

            if ($customer) {
                InboundSmsReceived::dispatch($customer, $phone, $body);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch InboundSmsReceived event', ['error' => $e->getMessage()]);
        }
    }

    private function twimlResponse(): Response
    {
        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, [
            'Content-Type' => 'text/xml',
        ]);
    }
}
