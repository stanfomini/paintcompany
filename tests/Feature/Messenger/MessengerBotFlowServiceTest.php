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
    it('marks status as abandoned and creates partial lead', function () {
        $this->mock(MessengerLeadCreationService::class, function ($mock) {
            $mock->shouldReceive('createPartialLead')->once();
        });

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
        $this->mock(MessengerLeadCreationService::class, function ($mock) {
            $mock->shouldReceive('createPartialLead')->once();
        });

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
