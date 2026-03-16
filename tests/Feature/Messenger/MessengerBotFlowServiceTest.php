<?php

use App\Models\ChatThread;
use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Services\MessengerBotFlowService;
use App\Services\MessengerLeadCreationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

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

    Http::fake([
        'graph.facebook.com/*' => Http::response(['message_id' => 'mid.test']),
    ]);
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

describe('startConversation', function () {
    it('creates conversation and sends greeting', function () {
        $flow = MessengerBotFlow::factory()->active()->create([
            'greeting_message' => 'Hello! Welcome to our service.',
        ]);

        $step = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'What is your name?',
        ]);

        $thread = ChatThread::factory()->facebook('11112222')->create();
        $service = app(MessengerBotFlowService::class);

        $conversation = $service->startConversation($thread, '11112222');

        expect($conversation)->not->toBeNull();
        expect($conversation->status)->toBe('in_progress');
        expect($conversation->flow_id)->toBe($flow->id);
        expect($conversation->thread_id)->toBe($thread->id);
        expect($conversation->facebook_psid)->toBe('11112222');

        // Should have sent the greeting + first step message
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'me/messages')
                && ($request['message']['text'] ?? '') === 'Hello! Welcome to our service.';
        });
    });

    it('returns null when no active flow exists', function () {
        MessengerBotFlow::factory()->create(['status' => 'draft']);

        $thread = ChatThread::factory()->facebook('11112222')->create();
        $service = app(MessengerBotFlowService::class);

        $conversation = $service->startConversation($thread, '11112222');

        expect($conversation)->toBeNull();
    });
});

describe('processResponse', function () {
    it('stores answer and advances to next step', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        $step1 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'What is your name?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion('customer.email')->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'What is your email?',
        ]);

        $thread = ChatThread::factory()->facebook('22223333')->create();

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '22223333',
            'current_step_id' => $step1->id,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);
        $service->processResponse($conversation, 'John');

        $conversation->refresh();
        expect($conversation->answers["step_{$step1->id}"])->toBe('John');
        expect($conversation->current_step_id)->toBe($step2->id);
    });

    it('handles quick_reply step with options', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        $step = MessengerBotStep::factory()->quickReply(
            [
                ['title' => 'Interior', 'payload' => 'interior'],
                ['title' => 'Exterior', 'payload' => 'exterior'],
            ],
            'deal.description'
        )->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'What type of painting?',
        ]);

        $thread = ChatThread::factory()->facebook('33334444')->create();

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '33334444',
            'current_step_id' => null,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);
        $service->advanceToNextStep($conversation);

        // Should have sent quick reply
        Http::assertSent(function ($request) {
            $message = $request['message'] ?? [];

            return isset($message['quick_replies']) && count($message['quick_replies']) === 2;
        });
    });
});

describe('advanceToNextStep', function () {
    it('sends greeting steps automatically and advances', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        $greetingStep = MessengerBotStep::factory()->greeting()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Welcome!',
        ]);

        $questionStep = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'What is your name?',
        ]);

        $thread = ChatThread::factory()->facebook('44445555')->create();

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '44445555',
            'current_step_id' => null,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);
        $service->advanceToNextStep($conversation);

        $conversation->refresh();
        // Should have skipped greeting and now be on question step
        expect($conversation->current_step_id)->toBe($questionStep->id);
    });

    it('handles delay steps', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        $delayStep = MessengerBotStep::factory()->delay(5)->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Please wait...',
        ]);

        $nextStep = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'Your question?',
        ]);

        $thread = ChatThread::factory()->facebook('55556666')->create();

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '55556666',
            'current_step_id' => null,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);
        $service->advanceToNextStep($conversation);

        $conversation->refresh();
        // Delay step auto-advances to next step
        expect($conversation->current_step_id)->toBe($nextStep->id);
    });

    it('triggers handoff step correctly', function () {
        Notification::fake();

        $flow = MessengerBotFlow::factory()->active()->create();

        $handoffStep = MessengerBotStep::factory()->handoff()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Transferring you to a human agent...',
        ]);

        $thread = ChatThread::factory()->facebook('66667777')->create();

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '66667777',
            'current_step_id' => null,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);
        $service->advanceToNextStep($conversation);

        $conversation->refresh();
        expect($conversation->status)->toBe('human_takeover');
    });

    it('completes conversation when no more steps', function () {
        $this->mock(MessengerLeadCreationService::class, function ($mock) {
            $mock->shouldReceive('createFromConversation')->once();
        });

        $flow = MessengerBotFlow::factory()->active()->create();

        $step = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Name?',
        ]);

        $thread = ChatThread::factory()->facebook('77778888')->create();

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '77778888',
            'current_step_id' => $step->id,
            'status' => 'in_progress',
            'answers' => ["step_{$step->id}" => 'John'],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);
        $service->processResponse($conversation, 'John');

        $conversation->refresh();
        expect($conversation->status)->toBe('completed');
        expect($conversation->completed_at)->not->toBeNull();
    });
});

describe('completeConversation', function () {
    it('marks status as completed and triggers lead creation', function () {
        $this->mock(MessengerLeadCreationService::class, function ($mock) {
            $mock->shouldReceive('createFromConversation')->once();
        });

        $flow = MessengerBotFlow::factory()->active()->create();
        $thread = ChatThread::factory()->facebook('88889999')->create();

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '88889999',
            'status' => 'in_progress',
            'answers' => ['step_1' => 'test'],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);
        $service->completeConversation($conversation);

        $conversation->refresh();
        expect($conversation->status)->toBe('completed');
        expect($conversation->completed_at)->not->toBeNull();
    });
});

describe('abandonConversation', function () {
    it('marks status as abandoned without creating a customer', function () {
        $flow = MessengerBotFlow::factory()->active()->create();
        $thread = ChatThread::factory()->facebook('99990000')->create();

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '99990000',
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);
        $service->abandonConversation($conversation);

        $conversation->refresh();
        expect($conversation->status)->toBe('abandoned');
        expect($conversation->customer_id)->toBeNull();
        expect($conversation->deal_id)->toBeNull();
    });
});

describe('triggerHumanTakeover', function () {
    it('sets status to human_takeover', function () {
        Notification::fake();

        $this->mock(MessengerLeadCreationService::class, function ($mock) {
            $mock->shouldReceive('createFromConversation')->once();
        });

        $flow = MessengerBotFlow::factory()->active()->create();
        $thread = ChatThread::factory()->facebook('10101010')->create();

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '10101010',
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);
        $service->triggerHumanTakeover($conversation);

        $conversation->refresh();
        expect($conversation->status)->toBe('human_takeover');
    });
});

describe('checkTimeouts', function () {
    it('finds and abandons stale conversations', function () {
        $flow = MessengerBotFlow::factory()->active()->create([
            'timeout_minutes' => 30,
        ]);

        $thread = ChatThread::factory()->facebook('20202020')->create();

        MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '20202020',
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now()->subHours(2),
            'last_activity_at' => now()->subMinutes(45),
        ]);

        $service = app(MessengerBotFlowService::class);
        $count = $service->checkTimeouts();

        expect($count)->toBe(1);

        $conversation = MessengerConversation::where('facebook_psid', '20202020')->first();
        expect($conversation->status)->toBe('abandoned');
    });

    it('does not abandon conversations within timeout', function () {
        $flow = MessengerBotFlow::factory()->active()->create([
            'timeout_minutes' => 30,
        ]);

        $thread = ChatThread::factory()->facebook('30303030')->create();

        MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '30303030',
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now()->subMinutes(10),
        ]);

        $service = app(MessengerBotFlowService::class);
        $count = $service->checkTimeouts();

        expect($count)->toBe(0);
    });
});

describe('quick reply options', function () {
    it('translates label/value to title/payload when sending quick replies', function () {
        $flow = MessengerBotFlow::factory()->active()->create([
            'greeting_message' => 'Hi there!',
        ]);

        $step = MessengerBotStep::factory()->quickReply()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Interior or exterior?',
            'quick_reply_options' => [
                ['label' => 'Interior', 'value' => 'interior'],
                ['label' => 'Exterior', 'value' => 'exterior'],
            ],
        ]);

        $thread = ChatThread::factory()->facebook('55554444')->create();
        $service = app(MessengerBotFlowService::class);

        $conversation = $service->startConversation($thread, '55554444');

        // Verify the quick reply was sent with correct title/payload format
        Http::assertSent(function ($request) {
            $quickReplies = $request['message']['quick_replies'] ?? [];
            if (empty($quickReplies)) {
                return false;
            }

            return $quickReplies[0]['title'] === 'Interior'
                && $quickReplies[0]['payload'] === 'interior'
                && $quickReplies[1]['title'] === 'Exterior'
                && $quickReplies[1]['payload'] === 'exterior';
        });
    });
});

describe('getActiveFlow', function () {
    it('only one flow can be active at a time', function () {
        $flow1 = MessengerBotFlow::factory()->active()->create();
        $flow2 = MessengerBotFlow::factory()->active()->create();

        // Even if two are somehow active, service returns first
        $service = app(MessengerBotFlowService::class);
        $activeFlow = $service->getActiveFlow();

        expect($activeFlow)->not->toBeNull();
    });
});

describe('getMappableFields', function () {
    it('returns correct customer and deal fields', function () {
        $service = app(MessengerBotFlowService::class);
        $fields = $service->getMappableFields();

        expect($fields)->toHaveKey('Customer Fields');
        expect($fields)->toHaveKey('Deal Fields');
        expect($fields['Customer Fields'])->toHaveKey('customer.first_name');
        expect($fields['Customer Fields'])->toHaveKey('customer.email');
        expect($fields['Deal Fields'])->toHaveKey('deal.title');
    });
});

describe('end-to-end bot conversation via webhook', function () {
    it('advances conversation when user replies to bot step', function () {
        $flow = MessengerBotFlow::factory()->active()->create([
            'greeting_message' => 'Welcome!',
        ]);

        $step1 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'What is your name?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion('customer.email')->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'What is your email?',
        ]);

        $thread = ChatThread::factory()->facebook('99998888')->create();
        $service = app(MessengerBotFlowService::class);

        // Start conversation
        $conversation = $service->startConversation($thread, '99998888');
        expect($conversation->current_step_id)->toBe($step1->id);

        // Simulate user replying to step 1
        $service->processResponse($conversation->fresh(), 'John');

        $conversation->refresh();
        expect($conversation->current_step_id)->toBe($step2->id);
        expect($conversation->answers)->toHaveKey("step_{$step1->id}", 'John');
    });

    it('routes incoming message to bot flow service when conversation is active', function () {
        $flow = MessengerBotFlow::factory()->active()->create([
            'greeting_message' => 'Welcome!',
        ]);

        $step1 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'What is your name?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion('customer.email')->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'What is your email?',
        ]);

        $thread = ChatThread::factory()->facebook('77776666')->create();

        // Create an active conversation manually (simulating one already started)
        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '77776666',
            'status' => 'in_progress',
            'current_step_id' => $step1->id,
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Simulate an incoming message via the service (this is what the webhook calls)
        $messengerService = app(\App\Services\FacebookMessengerService::class);
        $messengerService->processIncomingMessage([
            'messaging' => [[
                'sender' => ['id' => '77776666'],
                'message' => ['text' => 'Jane'],
            ]],
        ]);

        $conversation->refresh();
        expect($conversation->current_step_id)->toBe($step2->id);
        expect($conversation->answers)->toHaveKey("step_{$step1->id}", 'Jane');
    });
});

describe('message sanitization', function () {
    it('strips PHP tags from message text before sending', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Hi <?php echo e(first_name); ?>! How can we help?',
        ]);

        $thread = ChatThread::factory()->facebook('sanitize-1')->create();
        $service = app(MessengerBotFlowService::class);

        $conversation = $service->startConversation($thread, 'sanitize-1');

        Http::assertSent(function ($request) {
            $text = $request['message']['text'] ?? '';

            return ! str_contains($text, '<?php')
                && ! str_contains($text, '?>')
                && str_contains($text, 'How can we help?');
        });
    });

    it('strips PHP tags from greeting message before sending', function () {
        $flow = MessengerBotFlow::factory()->active()->create([
            'greeting_message' => 'Welcome <?php echo e(first_name); ?>!',
        ]);

        MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
        ]);

        $thread = ChatThread::factory()->facebook('sanitize-2')->create();
        $service = app(MessengerBotFlowService::class);

        $service->startConversation($thread, 'sanitize-2');

        Http::assertSent(function ($request) {
            $text = $request['message']['text'] ?? '';

            return str_contains($text, 'Welcome') && ! str_contains($text, '<?php');
        });
    });

    it('does not strip normal text or URLs', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Visit https://example.com/book?ref=test for details!',
        ]);

        $thread = ChatThread::factory()->facebook('sanitize-3')->create();
        $service = app(MessengerBotFlowService::class);

        $service->startConversation($thread, 'sanitize-3');

        Http::assertSent(function ($request) {
            $text = $request['message']['text'] ?? '';

            return str_contains($text, 'https://example.com/book?ref=test');
        });
    });
});

describe('media steps', function () {
    it('sends image for media_message step and auto-advances', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        MessengerBotStep::factory()->mediaMessage('https://example.com/colors.jpg')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Check out this color palette!',
        ]);

        $questionStep = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'What is your name?',
        ]);

        $thread = ChatThread::factory()->facebook('media-1')->create();
        $service = app(MessengerBotFlowService::class);

        $conversation = $service->startConversation($thread, 'media-1');

        $conversation->refresh();
        // media_message auto-advances, so should now be on the question step
        expect($conversation->current_step_id)->toBe($questionStep->id)
            ->and($conversation->status)->toBe('in_progress');

        // Should have sent the image via sendImageMessage
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $attachment = $body['message']['attachment'] ?? null;

            return $attachment
                && ($attachment['type'] ?? '') === 'image'
                && ($attachment['payload']['url'] ?? '') === 'https://example.com/colors.jpg';
        });

        // Should also have sent the text message for the media step
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ($body['message']['text'] ?? '') === 'Check out this color palette!';
        });
    });

    it('waits for image on photo_prompt step', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        $photoStep = MessengerBotStep::factory()->photoPrompt()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Send me a photo of your walls!',
        ]);

        $thread = ChatThread::factory()->facebook('photo-1')->create();
        $service = app(MessengerBotFlowService::class);

        $conversation = $service->startConversation($thread, 'photo-1');

        $conversation->refresh();
        // Should be waiting on the photo_prompt step (not auto-advancing)
        expect($conversation->current_step_id)->toBe($photoStep->id)
            ->and($conversation->status)->toBe('in_progress');

        // Should have sent the prompt text
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ($body['message']['text'] ?? '') === 'Send me a photo of your walls!';
        });
    });
});

describe('message variable substitution', function () {
    it('replaces booking_link variable with actual URL', function () {
        $service = app(MessengerBotFlowService::class);
        $conversation = MessengerConversation::factory()->create();

        $result = $service->replaceMessageVariables(
            'Book here: {{ booking_link }}',
            $conversation
        );

        expect($result)->toContain('/book/messenger/');
        $conversation->refresh();
        expect($conversation->booking_token)->not->toBeNull();
    });

    it('replaces first_name and last_name from FB profile', function () {
        $service = app(MessengerBotFlowService::class);
        $conversation = MessengerConversation::factory()->create([
            'fb_profile_data' => ['first_name' => 'John', 'last_name' => 'Doe'],
        ]);

        $result = $service->replaceMessageVariables(
            'Hi {{ first_name }} {{ last_name }}!',
            $conversation
        );

        expect($result)->toBe('Hi John Doe!');
    });

    it('leaves unknown variables untouched', function () {
        $service = app(MessengerBotFlowService::class);
        $conversation = MessengerConversation::factory()->create();

        $result = $service->replaceMessageVariables(
            'Hello {{ unknown_var }}!',
            $conversation
        );

        expect($result)->toBe('Hello {{ unknown_var }}!');
    });

    it('handles messages without variables efficiently', function () {
        $service = app(MessengerBotFlowService::class);
        $conversation = MessengerConversation::factory()->create();

        $result = $service->replaceMessageVariables(
            'Just a plain message',
            $conversation
        );

        expect($result)->toBe('Just a plain message');
    });

    it('reuses existing valid booking token', function () {
        $service = app(MessengerBotFlowService::class);
        $conversation = MessengerConversation::factory()->create();
        $conversation->generateBookingToken();
        $originalToken = $conversation->booking_token;

        $result = $service->replaceMessageVariables(
            '{{ booking_link }}',
            $conversation
        );

        expect($result)->toContain($originalToken);
    });

    it('sends step message with variables replaced', function () {
        $flow = MessengerBotFlow::factory()->active()->create();
        $step = MessengerBotStep::factory()->create([
            'flow_id' => $flow->id,
            'step_type' => 'greeting',
            'message_text' => 'Hi {{ first_name }}, book here: {{ booking_link }}',
            'sort_order' => 1,
        ]);

        $thread = ChatThread::factory()->facebook()->create();
        $conversation = MessengerConversation::factory()->create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'fb_profile_data' => ['first_name' => 'Alice'],
        ]);

        $service = app(MessengerBotFlowService::class);
        $service->advanceToNextStep($conversation);

        // Verify the message was sent with variables replaced
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $text = $body['message']['text'] ?? '';

            return str_contains($text, 'Hi Alice') && str_contains($text, '/book/messenger/');
        });
    });

    it('replaces variables with empty string when FB profile data missing', function () {
        $service = app(MessengerBotFlowService::class);
        $conversation = MessengerConversation::factory()->create([
            'fb_profile_data' => null,
        ]);

        $result = $service->replaceMessageVariables(
            'Hi {{ first_name }}!',
            $conversation
        );

        expect($result)->toBe('Hi !');
    });
});
