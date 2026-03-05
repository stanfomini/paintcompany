<?php

use App\Jobs\SendFacebookMessageJob;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Services\ChatService;
use App\Services\FacebookMessengerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'verify_token' => 'test-verify-token',
        'app_secret' => 'test-app-secret',
        'page_access_token' => 'test-page-token',
        'page_id' => '123456',
    ]);
    MessengerSetting::clearInstanceCache();
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

describe('verifyWebhook', function () {
    it('returns challenge when mode and token are valid', function () {
        $service = app(FacebookMessengerService::class);

        $result = $service->verifyWebhook('subscribe', 'test-verify-token', 'my-challenge');

        expect($result)->toBe('my-challenge');
    });

    it('returns null for wrong verify token', function () {
        $service = app(FacebookMessengerService::class);

        $result = $service->verifyWebhook('subscribe', 'wrong-token', 'my-challenge');

        expect($result)->toBeNull();
    });

    it('returns null for wrong mode', function () {
        $service = app(FacebookMessengerService::class);

        $result = $service->verifyWebhook('unsubscribe', 'test-verify-token', 'my-challenge');

        expect($result)->toBeNull();
    });

    it('returns null for empty mode', function () {
        $service = app(FacebookMessengerService::class);

        $result = $service->verifyWebhook('', 'test-verify-token', 'my-challenge');

        expect($result)->toBeNull();
    });
});

describe('validateSignature', function () {
    it('validates correct HMAC signature', function () {
        $service = app(FacebookMessengerService::class);
        $payload = '{"test": "data"}';
        $signature = 'sha256='.hash_hmac('sha256', $payload, 'test-app-secret');

        expect($service->validateSignature($payload, $signature))->toBeTrue();
    });

    it('rejects invalid signature', function () {
        $service = app(FacebookMessengerService::class);

        expect($service->validateSignature('payload', 'sha256=invalid'))->toBeFalse();
    });

    it('rejects empty signature', function () {
        $service = app(FacebookMessengerService::class);

        expect($service->validateSignature('payload', ''))->toBeFalse();
    });

    it('rejects when app_secret is not configured', function () {
        $settings = MessengerSetting::getInstance();
        $settings->update(['app_secret' => null]);
        MessengerSetting::clearInstanceCache();

        $service = app(FacebookMessengerService::class);
        $payload = 'test';
        $signature = 'sha256='.hash_hmac('sha256', $payload, '');

        expect($service->validateSignature($payload, $signature))->toBeFalse();
    });
});

describe('processIncomingMessage', function () {
    it('creates new thread for unknown PSID', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'profile_pic' => 'https://example.com/jane.jpg',
            ]),
        ]);

        $service = app(FacebookMessengerService::class);
        $service->processIncomingMessage([
            'id' => '123456',
            'time' => time(),
            'messaging' => [
                [
                    'sender' => ['id' => '12345678'],
                    'recipient' => ['id' => '123456'],
                    'message' => ['mid' => 'mid.1', 'text' => 'Hello'],
                ],
            ],
        ]);

        $thread = ChatThread::where('external_contact_id', '12345678')
            ->where('channel', ChatThread::CHANNEL_FACEBOOK)
            ->first();

        expect($thread)->not->toBeNull();
        expect($thread->type)->toBe(ChatThread::TYPE_SUPPORT);
        expect($thread->channel)->toBe(ChatThread::CHANNEL_FACEBOOK);
        expect($thread->subject)->toBe('Jane Smith');
    });

    it('routes to existing thread for known PSID', function () {
        Http::fake();

        $thread = ChatThread::factory()->facebook('99887766')->create();

        $service = app(FacebookMessengerService::class);
        $service->processIncomingMessage([
            'id' => '123456',
            'time' => time(),
            'messaging' => [
                [
                    'sender' => ['id' => '99887766'],
                    'recipient' => ['id' => '123456'],
                    'message' => ['mid' => 'mid.2', 'text' => 'Follow up message'],
                ],
            ],
        ]);

        // Should not create a new thread
        expect(ChatThread::where('external_contact_id', '99887766')->count())->toBe(1);
        // Should add message to existing thread
        expect($thread->messages()->count())->toBe(1);
        expect($thread->messages()->first()->content)->toBe('Follow up message');
    });

    it('stores message metadata with source and psid', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'first_name' => 'Test',
                'last_name' => 'User',
            ]),
        ]);

        $service = app(FacebookMessengerService::class);
        $service->processIncomingMessage([
            'id' => '123456',
            'time' => time(),
            'messaging' => [
                [
                    'sender' => ['id' => '77778888'],
                    'recipient' => ['id' => '123456'],
                    'message' => ['mid' => 'mid.meta1', 'text' => 'Test message'],
                ],
            ],
        ]);

        $message = ChatMessage::whereJsonContains('metadata->mid', 'mid.meta1')->first();

        expect($message)->not->toBeNull();
        expect($message->metadata['source'])->toBe('facebook_messenger');
        expect($message->metadata['psid'])->toBe('77778888');
        expect($message->metadata['mid'])->toBe('mid.meta1');
        expect($message->type)->toBe(ChatMessage::TYPE_TEXT);
        expect($message->user_id)->toBeNull();
    });

    it('handles entry with no messaging events gracefully', function () {
        $service = app(FacebookMessengerService::class);

        $service->processIncomingMessage([
            'id' => '123456',
            'time' => time(),
        ]);

        expect(ChatThread::where('channel', ChatThread::CHANNEL_FACEBOOK)->count())->toBe(0);
    });

    it('skips events without sender id', function () {
        $service = app(FacebookMessengerService::class);

        $service->processIncomingMessage([
            'id' => '123456',
            'time' => time(),
            'messaging' => [
                [
                    'recipient' => ['id' => '123456'],
                    'message' => ['mid' => 'mid.nosender', 'text' => 'No sender'],
                ],
            ],
        ]);

        expect(ChatMessage::whereJsonContains('metadata->mid', 'mid.nosender')->count())->toBe(0);
    });

    it('starts bot flow for first-time user when active flow exists', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'first_name' => 'Bot',
                'last_name' => 'User',
            ]),
        ]);

        $flow = MessengerBotFlow::factory()->active()->create([
            'greeting_message' => 'Welcome! How can I help?',
        ]);

        // Flow needs at least one step so conversation stays in_progress
        MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'What is your name?',
        ]);

        $service = app(FacebookMessengerService::class);
        $service->processIncomingMessage([
            'id' => '123456',
            'time' => time(),
            'messaging' => [
                [
                    'sender' => ['id' => '44445555'],
                    'recipient' => ['id' => '123456'],
                    'message' => ['mid' => 'mid.bot1', 'text' => 'Hi'],
                ],
            ],
        ]);

        $conversation = MessengerConversation::where('facebook_psid', '44445555')->first();
        expect($conversation)->not->toBeNull();
        expect($conversation->status)->toBe('in_progress');
        expect($conversation->flow_id)->toBe($flow->id);
        // Should now be on the first step
        expect($conversation->current_step_id)->not->toBeNull();

        // Greeting message should have been sent
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'me/messages')
                && ($request['message']['text'] ?? '') === 'Welcome! How can I help?';
        });

        // First step question should also have been sent
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'me/messages')
                && ($request['message']['text'] ?? '') === 'What is your name?';
        });
    });

    it('does not start new bot conversation when one is already in progress', function () {
        Http::fake();

        $flow = MessengerBotFlow::factory()->active()->create();
        $thread = ChatThread::factory()->facebook('33334444')->create();

        MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '33334444',
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(FacebookMessengerService::class);
        $service->processIncomingMessage([
            'id' => '123456',
            'time' => time(),
            'messaging' => [
                [
                    'sender' => ['id' => '33334444'],
                    'recipient' => ['id' => '123456'],
                    'message' => ['mid' => 'mid.existing', 'text' => 'Another message'],
                ],
            ],
        ]);

        // Should still only have one conversation
        expect(MessengerConversation::where('facebook_psid', '33334444')->count())->toBe(1);
    });

    it('uses Messenger User as fallback when profile fetch fails', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response([], 500),
        ]);

        $service = app(FacebookMessengerService::class);
        $service->processIncomingMessage([
            'id' => '123456',
            'time' => time(),
            'messaging' => [
                [
                    'sender' => ['id' => '66667777'],
                    'recipient' => ['id' => '123456'],
                    'message' => ['mid' => 'mid.noprofile', 'text' => 'Hi'],
                ],
            ],
        ]);

        $thread = ChatThread::where('external_contact_id', '66667777')->first();
        expect($thread)->not->toBeNull();
        expect($thread->subject)->toBe('Messenger User');
    });
});

describe('processPostback', function () {
    it('stores postback as a chat message', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'first_name' => 'Postback',
                'last_name' => 'User',
            ]),
        ]);

        $service = app(FacebookMessengerService::class);
        $service->processIncomingMessage([
            'id' => '123456',
            'time' => time(),
            'messaging' => [
                [
                    'sender' => ['id' => '55556666'],
                    'recipient' => ['id' => '123456'],
                    'postback' => [
                        'title' => 'Get Started',
                        'payload' => 'GET_STARTED',
                    ],
                ],
            ],
        ]);

        $thread = ChatThread::where('external_contact_id', '55556666')->first();
        expect($thread)->not->toBeNull();

        $message = $thread->messages()->first();
        expect($message->content)->toBe('Get Started');
        expect($message->metadata['postback_payload'])->toBe('GET_STARTED');
    });
});

describe('sendTextMessage', function () {
    it('sends text message with correct API format', function () {
        Http::fake([
            'graph.facebook.com/*/me/messages' => Http::response(['message_id' => 'mid.sent1']),
        ]);

        $service = app(FacebookMessengerService::class);
        $result = $service->sendTextMessage('12345', 'Hello there!');

        expect($result['success'])->toBeTrue();
        expect($result['message_id'])->toBe('mid.sent1');
        expect($result['error'])->toBeNull();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'me/messages')
                && $request['recipient']['id'] === '12345'
                && $request['message']['text'] === 'Hello there!'
                && $request['access_token'] === 'test-page-token';
        });
    });

    it('returns error when page access token is missing', function () {
        $settings = MessengerSetting::getInstance();
        $settings->update(['page_access_token' => null]);
        MessengerSetting::clearInstanceCache();

        $service = app(FacebookMessengerService::class);
        $result = $service->sendTextMessage('12345', 'Hello');

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('Page access token not configured');
    });

    it('returns error when API call fails', function () {
        Http::fake([
            'graph.facebook.com/*/me/messages' => Http::response([
                'error' => ['message' => 'Invalid recipient'],
            ], 400),
        ]);

        $service = app(FacebookMessengerService::class);
        $result = $service->sendTextMessage('invalid-psid', 'Hello');

        expect($result['success'])->toBeFalse();
        expect($result['error'])->toBe('Invalid recipient');
    });
});

describe('sendQuickReply', function () {
    it('sends quick reply with correct API format', function () {
        Http::fake([
            'graph.facebook.com/*/me/messages' => Http::response(['message_id' => 'mid.qr1']),
        ]);

        $service = app(FacebookMessengerService::class);
        $result = $service->sendQuickReply('12345', 'Pick one:', [
            ['title' => 'Interior', 'payload' => 'interior'],
            ['title' => 'Exterior', 'payload' => 'exterior'],
        ]);

        expect($result['success'])->toBeTrue();

        Http::assertSent(function ($request) {
            $message = $request['message'] ?? [];

            return isset($message['quick_replies'])
                && count($message['quick_replies']) === 2
                && $message['quick_replies'][0]['title'] === 'Interior'
                && $message['quick_replies'][0]['content_type'] === 'text'
                && $message['quick_replies'][0]['payload'] === 'interior'
                && $message['quick_replies'][1]['title'] === 'Exterior'
                && $message['text'] === 'Pick one:';
        });
    });
});

describe('sendTypingIndicator', function () {
    it('sends typing_on action', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response([]),
        ]);

        $service = app(FacebookMessengerService::class);
        $service->sendTypingIndicator('12345', true);

        Http::assertSent(function ($request) {
            return $request['sender_action'] === 'typing_on'
                && $request['recipient']['id'] === '12345';
        });
    });

    it('sends typing_off action', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response([]),
        ]);

        $service = app(FacebookMessengerService::class);
        $service->sendTypingIndicator('12345', false);

        Http::assertSent(function ($request) {
            return $request['sender_action'] === 'typing_off';
        });
    });

    it('does nothing when page access token is missing', function () {
        $settings = MessengerSetting::getInstance();
        $settings->update(['page_access_token' => null]);
        MessengerSetting::clearInstanceCache();

        Http::fake();

        $service = app(FacebookMessengerService::class);
        $service->sendTypingIndicator('12345');

        Http::assertNothingSent();
    });
});

describe('getUserProfile', function () {
    it('fetches user profile from Graph API', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'profile_pic' => 'https://example.com/pic.jpg',
            ]),
        ]);

        $service = app(FacebookMessengerService::class);
        $profile = $service->getUserProfile('12345');

        expect($profile['first_name'])->toBe('Jane');
        expect($profile['last_name'])->toBe('Doe');
        expect($profile['profile_pic'])->toBe('https://example.com/pic.jpg');
    });

    it('returns defaults when API fails', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response([], 500),
        ]);

        $service = app(FacebookMessengerService::class);
        $profile = $service->getUserProfile('12345');

        expect($profile['first_name'])->toBeNull();
        expect($profile['last_name'])->toBeNull();
        expect($profile['profile_pic'])->toBeNull();
    });

    it('returns defaults when page access token is missing', function () {
        $settings = MessengerSetting::getInstance();
        $settings->update(['page_access_token' => null]);
        MessengerSetting::clearInstanceCache();

        $service = app(FacebookMessengerService::class);
        $profile = $service->getUserProfile('12345');

        expect($profile['first_name'])->toBeNull();
        expect($profile['last_name'])->toBeNull();
        expect($profile['profile_pic'])->toBeNull();
    });
});

describe('customer linking', function () {
    it('links customer_id to thread when PSID matches existing conversation', function () {
        Http::fake();

        $customer = Customer::factory()->create();

        // Create a completed conversation that linked this PSID to a customer
        // The conversation's thread is a different thread (e.g., from a previous session)
        $previousThread = ChatThread::factory()->facebook('88889999')->create();
        MessengerConversation::create([
            'flow_id' => MessengerBotFlow::factory()->create()->id,
            'thread_id' => $previousThread->id,
            'facebook_psid' => '88889999',
            'customer_id' => $customer->id,
            'status' => 'completed',
            'answers' => [],
            'started_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
            'last_activity_at' => now()->subDay(),
        ]);

        // The existing thread already exists, so processIncomingMessage
        // routes to it. But let's verify that if a NEW thread were to be created
        // for a different PSID that has the same customer, it would link.
        // Instead, test the path directly: use a PSID that has no thread but has a conversation
        // We need to soft-delete the existing thread so findOrCreateThread creates a new one.
        $previousThread->delete(); // Soft delete — won't cascade, conversation still exists

        $service = app(FacebookMessengerService::class);
        $service->processIncomingMessage([
            'id' => '123456',
            'time' => time(),
            'messaging' => [
                [
                    'sender' => ['id' => '88889999'],
                    'recipient' => ['id' => '123456'],
                    'message' => ['mid' => 'mid.link1', 'text' => 'Hi again'],
                ],
            ],
        ]);

        // The soft-deleted thread won't be found by the query (no withTrashed),
        // so a new thread is created with the customer_id from the conversation
        $thread = ChatThread::where('external_contact_id', '88889999')
            ->where('channel', ChatThread::CHANNEL_FACEBOOK)
            ->whereNull('deleted_at')
            ->latest('id')
            ->first();

        expect($thread)->not->toBeNull();
        expect($thread->customer_id)->toBe($customer->id);
    });
});

describe('ChatService Facebook sync', function () {
    it('dispatches SendFacebookMessageJob when internal user replies to facebook thread', function () {
        Queue::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->facebook('55551111')->create();
        $thread->addParticipant($user);

        $chatService = app(ChatService::class);
        $chatService->sendMessage($thread, $user, 'We can schedule you for next Tuesday.');

        Queue::assertPushed(SendFacebookMessageJob::class, function ($job) {
            return $job->psid === '55551111'
                && $job->text === 'We can schedule you for next Tuesday.';
        });
    });

    it('does not dispatch SendFacebookMessageJob for internal channel threads', function () {
        Queue::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->direct()->create();
        $thread->addParticipant($user);

        $chatService = app(ChatService::class);
        $chatService->sendMessage($thread, $user, 'Internal message');

        Queue::assertNotPushed(SendFacebookMessageJob::class);
    });

    it('triggers human takeover when internal user messages active bot conversation', function () {
        Queue::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $flow = MessengerBotFlow::factory()->active()->create();
        $thread = ChatThread::factory()->facebook('77771234')->create();
        $thread->addParticipant($user);

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '77771234',
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $chatService = app(ChatService::class);
        $chatService->sendMessage($thread, $user, 'Let me help you directly.');

        $conversation->refresh();
        expect($conversation->status)->toBe('human_takeover');

        Queue::assertPushed(SendFacebookMessageJob::class);
    });

    it('does not trigger human takeover for completed bot conversations', function () {
        Queue::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $flow = MessengerBotFlow::factory()->active()->create();
        $thread = ChatThread::factory()->facebook('77775678')->create();
        $thread->addParticipant($user);

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '77775678',
            'status' => 'completed',
            'answers' => [],
            'started_at' => now()->subHour(),
            'completed_at' => now(),
            'last_activity_at' => now(),
        ]);

        $chatService = app(ChatService::class);
        $chatService->sendMessage($thread, $user, 'Follow up message');

        $conversation->refresh();
        expect($conversation->status)->toBe('completed');

        Queue::assertPushed(SendFacebookMessageJob::class);
    });
});

describe('handleMessage sends first question after greeting', function () {
    it('sends greeting AND first step question when new user messages', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'first_name' => 'New',
                'last_name' => 'Lead',
            ]),
        ]);

        $flow = MessengerBotFlow::factory()->active()->create([
            'greeting_message' => 'Welcome!',
        ]);

        $step1 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'What is your name?',
        ]);

        $service = app(FacebookMessengerService::class);
        $service->processIncomingMessage([
            'messaging' => [[
                'sender' => ['id' => '11110001'],
                'message' => ['mid' => 'mid.first', 'text' => 'Hi there'],
            ]],
        ]);

        $conversation = MessengerConversation::where('facebook_psid', '11110001')->first();
        expect($conversation)->not->toBeNull();
        expect($conversation->status)->toBe('in_progress');
        expect($conversation->current_step_id)->toBe($step1->id);

        // Both greeting and first question should have been sent
        $sentMessages = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'me/messages'))
            ->map(fn ($pair) => $pair[0]['message']['text'] ?? null)
            ->filter()
            ->values();

        expect($sentMessages)->toContain('Welcome!');
        expect($sentMessages)->toContain('What is your name?');
    });
});

describe('postback routes to bot flow', function () {
    it('routes quick reply postback to active bot conversation', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['message_id' => 'mid.test']),
        ]);

        $flow = MessengerBotFlow::factory()->active()->create();

        $step1 = MessengerBotStep::factory()->quickReply(
            [['title' => 'Interior', 'payload' => 'interior'], ['title' => 'Exterior', 'payload' => 'exterior']],
            'deal.description'
        )->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'What type of painting?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion('customer.email')->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'What is your email?',
        ]);

        $thread = ChatThread::factory()->facebook('22220001')->create();

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '22220001',
            'status' => 'in_progress',
            'current_step_id' => $step1->id,
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Simulate a quick reply postback
        $service = app(FacebookMessengerService::class);
        $service->processPostback('22220001', [
            'title' => 'Interior',
            'payload' => 'interior',
        ]);

        $conversation->refresh();
        expect($conversation->answers)->toHaveKey("step_{$step1->id}", 'Interior');
        expect($conversation->current_step_id)->toBe($step2->id);
    });

    it('starts bot flow on Get Started postback for new user', function () {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'first_name' => 'Postback',
                'last_name' => 'User',
            ]),
        ]);

        $flow = MessengerBotFlow::factory()->active()->create([
            'greeting_message' => 'Hello!',
        ]);

        MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'What is your name?',
        ]);

        $service = app(FacebookMessengerService::class);
        $service->processPostback('33330001', [
            'title' => 'Get Started',
            'payload' => 'GET_STARTED',
        ]);

        $conversation = MessengerConversation::where('facebook_psid', '33330001')->first();
        expect($conversation)->not->toBeNull();
        expect($conversation->status)->toBe('in_progress');

        // Should have sent greeting + first question
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'me/messages')
                && ($request['message']['text'] ?? '') === 'Hello!';
        });
    });
});

describe('findOrCreateThread adds participant', function () {
    it('adds default_assigned_to user as participant on new facebook thread', function () {
        $admin = User::factory()->internal()->create();

        MessengerSetting::clearInstanceCache();
        MessengerSetting::getInstance()->update(['default_assigned_to' => $admin->id]);
        MessengerSetting::clearInstanceCache();

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'first_name' => 'New',
                'last_name' => 'Contact',
            ]),
        ]);

        $service = app(FacebookMessengerService::class);
        $service->processIncomingMessage([
            'messaging' => [[
                'sender' => ['id' => '44440001'],
                'message' => ['mid' => 'mid.participant', 'text' => 'Hello'],
            ]],
        ]);

        $thread = ChatThread::where('external_contact_id', '44440001')
            ->where('channel', ChatThread::CHANNEL_FACEBOOK)
            ->first();

        expect($thread)->not->toBeNull();
        expect($thread->hasParticipant($admin))->toBeTrue();
    });

    it('does not add participant when default_assigned_to is not set', function () {
        MessengerSetting::clearInstanceCache();
        MessengerSetting::getInstance()->update(['default_assigned_to' => null]);
        MessengerSetting::clearInstanceCache();

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'first_name' => 'Solo',
                'last_name' => 'User',
            ]),
        ]);

        $service = app(FacebookMessengerService::class);
        $service->processIncomingMessage([
            'messaging' => [[
                'sender' => ['id' => '55550001'],
                'message' => ['mid' => 'mid.noassign', 'text' => 'Hello'],
            ]],
        ]);

        $thread = ChatThread::where('external_contact_id', '55550001')
            ->where('channel', ChatThread::CHANNEL_FACEBOOK)
            ->first();

        expect($thread)->not->toBeNull();
        expect($thread->participants()->count())->toBe(0);
    });
});
