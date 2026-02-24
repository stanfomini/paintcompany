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

it('conversation is abandoned after timeout_minutes', function () {
    $flow = MessengerBotFlow::factory()->active()->create([
        'timeout_minutes' => 15,
    ]);

    $step = MessengerBotStep::factory()->textQuestion()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Question?',
    ]);

    $thread = ChatThread::factory()->facebook('50501010')->create();

    $conversation = MessengerConversation::create([
        'flow_id' => $flow->id,
        'thread_id' => $thread->id,
        'facebook_psid' => '50501010',
        'current_step_id' => $step->id,
        'status' => 'in_progress',
        'answers' => [],
        'started_at' => now()->subMinutes(30),
        'last_activity_at' => now()->subMinutes(20),
    ]);

    $service = app(MessengerBotFlowService::class);
    $count = $service->checkTimeouts();

    expect($count)->toBe(1);

    $conversation->refresh();
    expect($conversation->status)->toBe('abandoned');
    expect($conversation->isAbandoned())->toBeTrue();
});

it('partial lead is created on timeout', function () {
    $flow = MessengerBotFlow::factory()->active()->create([
        'timeout_minutes' => 10,
    ]);

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

    $thread = ChatThread::factory()->facebook('60602020')->create();

    // Start and answer first question
    $service = app(MessengerBotFlowService::class);
    $conversation = $service->startConversation($thread, '60602020');
    $service->processResponse($conversation, 'Dave');

    // Simulate timeout
    $conversation->update(['last_activity_at' => now()->subMinutes(15)]);

    $count = $service->checkTimeouts();
    expect($count)->toBe(1);

    $conversation->refresh();
    expect($conversation->status)->toBe('abandoned');
    // Answers collected before timeout should be preserved
    expect($conversation->answers)->toHaveKey("step_{$step1->id}");
    expect($conversation->answers["step_{$step1->id}"])->toBe('Dave');
});

it('does not timeout completed conversations', function () {
    $flow = MessengerBotFlow::factory()->active()->create([
        'timeout_minutes' => 10,
    ]);

    $thread = ChatThread::factory()->facebook('70703030')->create();

    MessengerConversation::create([
        'flow_id' => $flow->id,
        'thread_id' => $thread->id,
        'facebook_psid' => '70703030',
        'status' => 'completed',
        'answers' => [],
        'started_at' => now()->subHours(2),
        'completed_at' => now()->subHour(),
        'last_activity_at' => now()->subHours(2),
    ]);

    $service = app(MessengerBotFlowService::class);
    $count = $service->checkTimeouts();

    expect($count)->toBe(0);
});

it('does not timeout already abandoned conversations', function () {
    $flow = MessengerBotFlow::factory()->active()->create([
        'timeout_minutes' => 10,
    ]);

    $thread = ChatThread::factory()->facebook('80804040')->create();

    MessengerConversation::create([
        'flow_id' => $flow->id,
        'thread_id' => $thread->id,
        'facebook_psid' => '80804040',
        'status' => 'abandoned',
        'answers' => [],
        'started_at' => now()->subHours(2),
        'last_activity_at' => now()->subHours(2),
    ]);

    $service = app(MessengerBotFlowService::class);
    $count = $service->checkTimeouts();

    expect($count)->toBe(0);
});

it('respects per-flow timeout settings', function () {
    $fastFlow = MessengerBotFlow::factory()->active()->create([
        'timeout_minutes' => 5,
    ]);

    $step = MessengerBotStep::factory()->textQuestion()->create([
        'flow_id' => $fastFlow->id,
        'sort_order' => 1,
    ]);

    $thread = ChatThread::factory()->facebook('90905050')->create();

    // Last activity 7 minutes ago - past the 5-minute timeout
    MessengerConversation::create([
        'flow_id' => $fastFlow->id,
        'thread_id' => $thread->id,
        'facebook_psid' => '90905050',
        'current_step_id' => $step->id,
        'status' => 'in_progress',
        'answers' => [],
        'started_at' => now()->subMinutes(10),
        'last_activity_at' => now()->subMinutes(7),
    ]);

    $service = app(MessengerBotFlowService::class);
    $count = $service->checkTimeouts();

    expect($count)->toBe(1);
});
