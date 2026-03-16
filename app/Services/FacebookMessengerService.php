<?php

namespace App\Services;

use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\MessengerAdFlowMapping;
use App\Models\MessengerBotFlow;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FacebookMessengerService
{
    protected const GRAPH_API_URL = 'https://graph.facebook.com/v19.0';

    /**
     * Verify the webhook subscription from Facebook.
     *
     * @return string|null The challenge string if valid, null otherwise.
     */
    public function verifyWebhook(string $mode, string $token, string $challenge): ?string
    {
        if ($mode !== 'subscribe') {
            return null;
        }

        $settings = MessengerSetting::getInstance();

        if ($token === $settings->verify_token) {
            return $challenge;
        }

        return null;
    }

    /**
     * Validate the X-Hub-Signature-256 header against the app secret.
     */
    public function validateSignature(string $payload, string $signature): bool
    {
        $settings = MessengerSetting::getInstance();

        if (empty($settings->app_secret)) {
            return false;
        }

        $expectedSignature = 'sha256='.hash_hmac('sha256', $payload, $settings->app_secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Send a text message to a Facebook user via the Send API.
     *
     * @return array{success: bool, message_id: string|null, error: string|null}
     */
    public function sendTextMessage(string $psid, string $text): array
    {
        return $this->sendMessage($psid, ['text' => $text]);
    }

    /**
     * Send an image attachment to a Facebook user via the Send API.
     *
     * @return array{success: bool, message_id: string|null, error: string|null}
     */
    public function sendImageMessage(string $psid, string $imageUrl): array
    {
        return $this->sendMessage($psid, [
            'attachment' => [
                'type' => 'image',
                'payload' => [
                    'url' => $imageUrl,
                    'is_reusable' => true,
                ],
            ],
        ]);
    }

    /**
     * Send a message with quick reply buttons.
     *
     * @param  array<int, array{title: string, payload: string}>  $options
     * @return array{success: bool, message_id: string|null, error: string|null}
     */
    public function sendQuickReply(string $psid, string $text, array $options): array
    {
        $quickReplies = array_map(fn (array $option) => [
            'content_type' => 'text',
            'title' => $option['title'],
            'payload' => $option['payload'],
        ], $options);

        return $this->sendMessage($psid, [
            'text' => $text,
            'quick_replies' => $quickReplies,
        ]);
    }

    /**
     * Send a typing indicator (on/off) to the user.
     */
    public function sendTypingIndicator(string $psid, bool $on = true): void
    {
        $settings = MessengerSetting::getInstance();

        if (empty($settings->page_access_token)) {
            return;
        }

        Http::post(self::GRAPH_API_URL.'/me/messages', [
            'access_token' => $settings->page_access_token,
            'recipient' => ['id' => $psid],
            'sender_action' => $on ? 'typing_on' : 'typing_off',
        ]);
    }

    /**
     * Fetch a user's profile information from the Graph API.
     *
     * @return array{first_name: string|null, last_name: string|null, profile_pic: string|null}
     */
    public function getUserProfile(string $psid): array
    {
        $settings = MessengerSetting::getInstance();

        $default = [
            'first_name' => null,
            'last_name' => null,
            'profile_pic' => null,
        ];

        if (empty($settings->page_access_token)) {
            return $default;
        }

        try {
            $response = Http::get(self::GRAPH_API_URL.'/'.$psid, [
                'fields' => 'first_name,last_name,profile_pic',
                'access_token' => $settings->page_access_token,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'first_name' => $data['first_name'] ?? null,
                    'last_name' => $data['last_name'] ?? null,
                    'profile_pic' => $data['profile_pic'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('FacebookMessenger: Failed to fetch user profile', [
                'psid' => $psid,
                'error' => $e->getMessage(),
            ]);
        }

        return $default;
    }

    /**
     * Process an incoming message webhook entry.
     * Routes the message to an existing thread or creates a new one.
     */
    public function processIncomingMessage(array $webhookEntry): void
    {
        $messaging = $webhookEntry['messaging'] ?? [];

        foreach ($messaging as $event) {
            $psid = $event['sender']['id'] ?? null;

            if (! $psid) {
                continue;
            }

            // Extract referral data (present when message originates from an ad)
            $referral = $event['referral'] ?? $event['message']['referral'] ?? $event['postback']['referral'] ?? null;

            if (isset($event['message'])) {
                $this->handleMessage($psid, $event['message'], $referral);
            } elseif (isset($event['postback'])) {
                $this->processPostback($psid, $event['postback'], $referral);
            }
        }
    }

    /**
     * Handle a text/attachment message from a user.
     */
    protected function handleMessage(string $psid, array $message, ?array $referral = null): void
    {
        $text = $message['text'] ?? '';
        $quickReplyPayload = $message['quick_reply']['payload'] ?? null;

        // If this is a quick reply, route to postback handler
        if ($quickReplyPayload) {
            $this->processPostback($psid, ['payload' => $quickReplyPayload], $referral);

            return;
        }

        $thread = $this->findOrCreateThread($psid);

        // Store the incoming message
        ChatMessage::create([
            'thread_id' => $thread->id,
            'user_id' => null,
            'content' => $text,
            'type' => ChatMessage::TYPE_TEXT,
            'metadata' => [
                'source' => 'facebook_messenger',
                'psid' => $psid,
                'mid' => $message['mid'] ?? null,
            ],
        ]);

        // Store any attachments (images, videos, files)
        $attachments = $message['attachments'] ?? [];
        if (! empty($attachments)) {
            $this->handleAttachments($psid, $attachments, $thread);
        }

        $thread->update(['last_message_at' => now()]);

        // Check for active bot conversation
        $conversation = MessengerConversation::where('facebook_psid', $psid)
            ->inProgress()
            ->first();

        if ($conversation) {
            app(MessengerBotFlowService::class)->processResponse($conversation, $text);

            return;
        }

        // Returning customer — skip bot, let message sit for human follow-up
        if ($this->isReturningCustomer($psid)) {
            return;
        }

        // Check for keyword-triggered flow
        $keywordFlow = MessengerBotFlow::findByKeyword($text);
        if ($keywordFlow) {
            app(MessengerBotFlowService::class)->startConversation($thread, $psid, $keywordFlow);

            return;
        }

        // Resolve flow from ad mapping or fall back to default
        $flow = $this->resolveFlowFromReferral($referral);
        app(MessengerBotFlowService::class)->startConversation($thread, $psid, $flow);
    }

    /**
     * Handle a postback (button click or quick reply).
     */
    public function processPostback(string $psid, array $postback, ?array $referral = null): void
    {
        $payload = $postback['payload'] ?? '';

        $thread = $this->findOrCreateThread($psid);

        // Store the postback as a message
        ChatMessage::create([
            'thread_id' => $thread->id,
            'user_id' => null,
            'content' => $postback['title'] ?? $payload,
            'type' => ChatMessage::TYPE_TEXT,
            'metadata' => [
                'source' => 'facebook_messenger',
                'psid' => $psid,
                'postback_payload' => $payload,
            ],
        ]);

        $thread->update(['last_message_at' => now()]);

        // Check for active bot conversation and route the response
        $conversation = MessengerConversation::where('facebook_psid', $psid)
            ->inProgress()
            ->first();

        if ($conversation) {
            app(MessengerBotFlowService::class)->processResponse($conversation, $postback['title'] ?? $payload);

            return;
        }

        // Returning customer — skip bot, let message sit for human follow-up
        if ($this->isReturningCustomer($psid)) {
            return;
        }

        // Resolve flow from ad mapping or fall back to default
        $flow = $this->resolveFlowFromReferral($referral);
        app(MessengerBotFlowService::class)->startConversation($thread, $psid, $flow);
    }

    /**
     * Check if this PSID belongs to a returning customer who already completed a bot flow.
     * Bypassed when bot_test_mode is enabled in MessengerSetting.
     */
    protected function isReturningCustomer(string $psid): bool
    {
        if (MessengerSetting::getInstance()->bot_test_mode) {
            return false;
        }

        return MessengerConversation::where('facebook_psid', $psid)
            ->whereNotNull('customer_id')
            ->exists();
    }

    /**
     * Resolve a bot flow from ad referral data.
     */
    protected function resolveFlowFromReferral(?array $referral): ?MessengerBotFlow
    {
        if (! $referral || empty($referral['ad_id'])) {
            return null;
        }

        $mapping = MessengerAdFlowMapping::where('facebook_ad_id', $referral['ad_id'])->first();

        if (! $mapping) {
            return null;
        }

        return $mapping->flow;
    }

    /**
     * Download and store Facebook Messenger attachments as ChatAttachments.
     *
     * @param  array<int, array{type: string, payload: array{url: string}}>  $attachments
     */
    protected function handleAttachments(string $psid, array $attachments, ChatThread $thread): void
    {
        foreach ($attachments as $attachment) {
            $type = $attachment['type'] ?? null;
            $url = $attachment['payload']['url'] ?? null;

            if (! $url || ! in_array($type, ['image', 'video', 'file', 'audio'])) {
                continue;
            }

            try {
                $response = Http::timeout(30)->get($url);
                if (! $response->successful()) {
                    Log::warning('FacebookMessenger: Failed to download attachment', [
                        'psid' => $psid,
                        'url' => $url,
                        'status' => $response->status(),
                    ]);

                    continue;
                }

                $contentType = $response->header('Content-Type', 'application/octet-stream');
                $ext = $this->guessExtension($contentType, $url);
                $uuid = Str::uuid();
                $storagePath = "chat-attachments/{$uuid}.{$ext}";

                Storage::put($storagePath, $response->body());

                $messageType = match ($type) {
                    'image' => ChatMessage::TYPE_IMAGE,
                    'audio' => ChatMessage::TYPE_AUDIO,
                    default => ChatMessage::TYPE_FILE,
                };

                $chatMessage = ChatMessage::create([
                    'thread_id' => $thread->id,
                    'user_id' => null,
                    'content' => '',
                    'type' => $messageType,
                    'metadata' => [
                        'source' => 'facebook_messenger',
                        'psid' => $psid,
                        'original_url' => $url,
                    ],
                ]);

                ChatAttachment::create([
                    'message_id' => $chatMessage->id,
                    'path' => $storagePath,
                    'filename' => "{$uuid}.{$ext}",
                    'original_filename' => basename(parse_url($url, PHP_URL_PATH)) ?: "{$uuid}.{$ext}",
                    'mime_type' => $contentType,
                    'file_size' => strlen($response->body()),
                ]);
            } catch (\Throwable $e) {
                Log::error('FacebookMessenger: Failed to process attachment', [
                    'psid' => $psid,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Guess file extension from Content-Type header or URL.
     */
    private function guessExtension(string $contentType, string $url): string
    {
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'application/pdf' => 'pdf',
        ];

        if (isset($mimeMap[$contentType])) {
            return $mimeMap[$contentType];
        }

        $pathExt = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
        if ($pathExt) {
            return strtolower($pathExt);
        }

        return 'bin';
    }

    /**
     * Find an existing thread for this PSID or create a new one.
     */
    protected function findOrCreateThread(string $psid): ChatThread
    {
        $thread = ChatThread::where('external_contact_id', $psid)
            ->where('channel', ChatThread::CHANNEL_FACEBOOK)
            ->first();

        if ($thread) {
            return $thread;
        }

        // Fetch user profile for display name
        $profile = $this->getUserProfile($psid);
        $displayName = trim(($profile['first_name'] ?? '').' '.($profile['last_name'] ?? ''));

        if (empty($displayName)) {
            $displayName = 'Messenger User';
        }

        // Check if this PSID is linked to an existing customer
        $conversation = MessengerConversation::where('facebook_psid', $psid)
            ->whereNotNull('customer_id')
            ->first();

        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'channel' => ChatThread::CHANNEL_FACEBOOK,
            'external_contact_id' => $psid,
            'customer_id' => $conversation?->customer_id,
            'subject' => $displayName,
            'last_message_at' => now(),
        ]);

        // Add all active messenger responders as participants so the thread is discoverable
        $responders = \App\Models\MessengerResponder::query()->active()->with('user')->get();
        foreach ($responders as $responder) {
            if ($responder->user) {
                $thread->addParticipant($responder->user);
            }
        }

        // Fallback: if no responders in roster, add default assigned user
        if ($responders->isEmpty()) {
            $settings = MessengerSetting::getInstance();
            if ($settings->default_assigned_to) {
                $assignedUser = User::find($settings->default_assigned_to);
                if ($assignedUser) {
                    $thread->addParticipant($assignedUser);
                }
            }
        }

        return $thread;
    }

    /**
     * Send a message via the Facebook Send API.
     *
     * @return array{success: bool, message_id: string|null, error: string|null}
     */
    protected function sendMessage(string $psid, array $message): array
    {
        $settings = MessengerSetting::getInstance();

        if (empty($settings->page_access_token)) {
            return [
                'success' => false,
                'message_id' => null,
                'error' => 'Page access token not configured',
            ];
        }

        try {
            $response = Http::post(self::GRAPH_API_URL.'/me/messages', [
                'access_token' => $settings->page_access_token,
                'recipient' => ['id' => $psid],
                'message' => $message,
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message_id' => $response->json('message_id'),
                    'error' => null,
                ];
            }

            $error = $response->json('error.message', 'Unknown error');
            Log::warning('FacebookMessenger: Send API error', [
                'psid' => $psid,
                'error' => $error,
                'status' => $response->status(),
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $error,
            ];
        } catch (\Throwable $e) {
            Log::error('FacebookMessenger: Failed to send message', [
                'psid' => $psid,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
            ];
        }
    }
}
