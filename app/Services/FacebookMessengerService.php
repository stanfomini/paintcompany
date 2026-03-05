<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

            if (isset($event['message'])) {
                $this->handleMessage($psid, $event['message']);
            } elseif (isset($event['postback'])) {
                $this->processPostback($psid, $event['postback']);
            }
        }
    }

    /**
     * Handle a text/attachment message from a user.
     */
    protected function handleMessage(string $psid, array $message): void
    {
        $text = $message['text'] ?? '';
        $quickReplyPayload = $message['quick_reply']['payload'] ?? null;

        // If this is a quick reply, route to postback handler
        if ($quickReplyPayload) {
            $this->processPostback($psid, ['payload' => $quickReplyPayload]);

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

        $thread->update(['last_message_at' => now()]);

        // Check for active bot conversation
        $conversation = MessengerConversation::where('facebook_psid', $psid)
            ->inProgress()
            ->first();

        if ($conversation) {
            app(MessengerBotFlowService::class)->processResponse($conversation, $text);

            return;
        }

        // Start bot flow if one is active (sends greeting + first question)
        app(MessengerBotFlowService::class)->startConversation($thread, $psid);
    }

    /**
     * Handle a postback (button click or quick reply).
     */
    public function processPostback(string $psid, array $postback): void
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

        // "Get Started" or similar postbacks should start a bot flow
        app(MessengerBotFlowService::class)->startConversation($thread, $psid);
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

        // Add the default assigned user as a participant so the thread is discoverable
        $settings = MessengerSetting::getInstance();
        if ($settings->default_assigned_to) {
            $assignedUser = User::find($settings->default_assigned_to);
            if ($assignedUser) {
                $thread->addParticipant($assignedUser);
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
