<?php

use App\Models\ChatThread;
use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Services\MessengerBotFlowService;
use Illuminate\Support\Facades\Http;

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

describe('full bot flow lifecycle', function () {
    it('starts -> answers questions -> completes -> lead created', function () {
        $flow = MessengerBotFlow::factory()->active()->create([
            'greeting_message' => 'Welcome!',
        ]);

        $step1 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'What is your first name?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion('customer.email')->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'What is your email?',
        ]);

        $step3 = MessengerBotStep::factory()->textQuestion('customer.phone')->create([
            'flow_id' => $flow->id,
            'sort_order' => 3,
            'message_text' => 'What is your phone?',
        ]);

        $thread = ChatThread::factory()->facebook('55551111')->create();
        $service = app(MessengerBotFlowService::class);

        // Start conversation
        $conversation = $service->startConversation($thread, '55551111');
        expect($conversation->status)->toBe('in_progress');
        expect($conversation->current_step_id)->toBe($step1->id);

        // Answer step 1
        $service->processResponse($conversation, 'Jane');
        $conversation->refresh();
        expect($conversation->answers["step_{$step1->id}"])->toBe('Jane');
        expect($conversation->current_step_id)->toBe($step2->id);

        // Answer step 2
        $service->processResponse($conversation, 'jane@example.com');
        $conversation->refresh();
        expect($conversation->answers["step_{$step2->id}"])->toBe('jane@example.com');
        expect($conversation->current_step_id)->toBe($step3->id);

        // Answer step 3 (last step -> completes)
        $service->processResponse($conversation, '555-0123');
        $conversation->refresh();
        expect($conversation->status)->toBe('completed');
        expect($conversation->completed_at)->not->toBeNull();

        // Verify all answers were collected
        expect($conversation->answers["step_{$step1->id}"])->toBe('Jane');
        expect($conversation->answers["step_{$step2->id}"])->toBe('jane@example.com');
        expect($conversation->answers["step_{$step3->id}"])->toBe('555-0123');

        // Verify collected data maps correctly
        $data = $conversation->getCollectedData();
        expect($data['customer.first_name'])->toBe('Jane');
        expect($data['customer.email'])->toBe('jane@example.com');
        expect($data['customer.phone'])->toBe('555-0123');
    });
});

describe('partial bot flow', function () {
    it('starts -> answers some -> timeout -> partial lead created', function () {
        $flow = MessengerBotFlow::factory()->active()->create([
            'timeout_minutes' => 5,
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

        $thread = ChatThread::factory()->facebook('66662222')->create();
        $service = app(MessengerBotFlowService::class);

        $conversation = $service->startConversation($thread, '66662222');

        // Answer only step 1
        $service->processResponse($conversation, 'Bob');
        $conversation->refresh();
        expect($conversation->answers["step_{$step1->id}"])->toBe('Bob');

        // Simulate timeout
        $conversation->update(['last_activity_at' => now()->subMinutes(10)]);
        $count = $service->checkTimeouts();
        expect($count)->toBe(1);

        $conversation->refresh();
        expect($conversation->status)->toBe('abandoned');
        // Answers from before timeout should be preserved
        expect($conversation->answers)->toHaveKey("step_{$step1->id}");
        expect($conversation->answers["step_{$step1->id}"])->toBe('Bob');
    });
});

describe('bot flow with quick replies', function () {
    it('selections map to correct values', function () {
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
            'message_text' => 'What type?',
        ]);

        $thread = ChatThread::factory()->facebook('77773333')->create();
        $service = app(MessengerBotFlowService::class);

        $conversation = $service->startConversation($thread, '77773333');
        $service->processResponse($conversation, 'Interior');

        $conversation->refresh();
        expect($conversation->answers["step_{$step->id}"])->toBe('Interior');
    });
});

describe('field mapping', function () {
    it('correctly extracts customer and deal fields from answers', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        $step1 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Name?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion('customer.email')->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'Email?',
        ]);

        $step3 = MessengerBotStep::factory()->textQuestion('deal.title')->create([
            'flow_id' => $flow->id,
            'sort_order' => 3,
            'message_text' => 'Project?',
        ]);

        $thread = ChatThread::factory()->facebook('88884444')->create();

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '88884444',
            'status' => 'completed',
            'answers' => [
                "step_{$step1->id}" => 'Alice',
                "step_{$step2->id}" => 'alice@test.com',
                "step_{$step3->id}" => 'Kitchen repaint',
            ],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $collectedData = $conversation->getCollectedData();

        expect($collectedData)->toHaveKey('customer.first_name', 'Alice');
        expect($collectedData)->toHaveKey('customer.email', 'alice@test.com');
        expect($collectedData)->toHaveKey('deal.title', 'Kitchen repaint');
    });

    it('skips steps without field mapping', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        $step1 = MessengerBotStep::factory()->greeting()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Hello!',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'Name?',
        ]);

        $thread = ChatThread::factory()->facebook('99995555')->create();

        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '99995555',
            'status' => 'completed',
            'answers' => [
                "step_{$step2->id}" => 'Charlie',
            ],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $collectedData = $conversation->getCollectedData();

        expect($collectedData)->toHaveCount(1);
        expect($collectedData)->toHaveKey('customer.first_name', 'Charlie');
    });
});
