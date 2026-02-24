<?php

use App\Models\ChatThread;
use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Notifications\MessengerHumanTakeoverNeeded;
use App\Services\MessengerBotFlowService;
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

it('handoff step triggers human takeover with correct status', function () {
    Notification::fake();

    $flow = MessengerBotFlow::factory()->active()->create();

    $step1 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Name?',
    ]);

    $handoffStep = MessengerBotStep::factory()->handoff()->create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'message_text' => 'Let me connect you with a team member.',
    ]);

    $thread = ChatThread::factory()->facebook('11110000')->create();

    $service = app(MessengerBotFlowService::class);
    $conversation = $service->startConversation($thread, '11110000');

    // Answer first question, which should advance to handoff step
    $service->processResponse($conversation, 'Bob');
    $conversation->refresh();

    expect($conversation->status)->toBe('human_takeover');
});

it('collected answers are preserved after takeover', function () {
    Notification::fake();

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

    $handoffStep = MessengerBotStep::factory()->handoff()->create([
        'flow_id' => $flow->id,
        'sort_order' => 3,
        'message_text' => 'Transferring...',
    ]);

    $thread = ChatThread::factory()->facebook('22220000')->create();

    $service = app(MessengerBotFlowService::class);
    $conversation = $service->startConversation($thread, '22220000');

    $service->processResponse($conversation, 'Alice');
    $conversation->refresh();
    $service->processResponse($conversation, 'alice@test.com');
    $conversation->refresh();

    expect($conversation->status)->toBe('human_takeover');
    expect($conversation->answers)->toHaveKey("step_{$step1->id}");
    expect($conversation->answers["step_{$step1->id}"])->toBe('Alice');
    expect($conversation->answers["step_{$step2->id}"])->toBe('alice@test.com');
});

it('sends notification to assigned user on handoff', function () {
    Notification::fake();

    $assignedUser = User::factory()->internal()->create();

    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    $settings->update(['default_assigned_to' => $assignedUser->id]);
    MessengerSetting::clearInstanceCache();

    $flow = MessengerBotFlow::factory()->active()->create();

    $handoffStep = MessengerBotStep::factory()->handoff()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Connecting you to a human.',
    ]);

    $thread = ChatThread::factory()->facebook('33330000')->create();

    $service = app(MessengerBotFlowService::class);
    $conversation = $service->startConversation($thread, '33330000');

    // Notification is sent to assigned user
    Notification::assertSentTo($assignedUser, MessengerHumanTakeoverNeeded::class);
});

it('conversation status changes to human_takeover on handoff', function () {
    Notification::fake();

    $flow = MessengerBotFlow::factory()->active()->create();

    $handoffStep = MessengerBotStep::factory()->handoff()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Transferring to an agent...',
    ]);

    $thread = ChatThread::factory()->facebook('44440000')->create();

    $service = app(MessengerBotFlowService::class);
    $conversation = $service->startConversation($thread, '44440000');

    $conversation->refresh();
    expect($conversation->status)->toBe('human_takeover');
    expect($conversation->isHumanTakeover())->toBeTrue();
    expect($conversation->isInProgress())->toBeFalse();
});
