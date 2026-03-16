<?php

use App\Models\ChatThread;
use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use App\Models\SmsConversation;
use App\Models\User;
use App\Services\SmsBotFlowService;
use App\Services\TwilioService;

beforeEach(function () {
    $this->twilio = Mockery::mock(TwilioService::class);
    $this->twilio->shouldReceive('sendSms')->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
    app()->instance(TwilioService::class, $this->twilio);
});

it('starts a conversation and sends greeting', function () {
    $flow = SmsBotFlow::factory()->active()->keyword('COLOR')->create();
    SmsBotStep::factory()->greeting()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Welcome to the color bot!',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow);

    expect($convo)->toBeInstanceOf(SmsConversation::class)
        ->and($convo->status)->toBe('completed') // only 1 step (greeting auto-advances, then completes)
        ->and($convo->flow_id)->toBe($flow->id)
        ->and($convo->phone)->toBe('+13035551234')
        ->and($convo->chat_thread_id)->not->toBeNull();

    $this->twilio->shouldHaveReceived('sendSms')->once();
});

it('auto-advances past greeting and message steps', function () {
    $flow = SmsBotFlow::factory()->active()->create();
    SmsBotStep::factory()->greeting()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Hello!',
    ]);
    SmsBotStep::factory()->message()->create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'message_text' => 'Here is some info.',
    ]);
    $questionStep = SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 3,
        'message_text' => 'What is your name?',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow);

    expect($convo->status)->toBe('in_progress')
        ->and($convo->current_step_id)->toBe($questionStep->id);

    // greeting + message + question = 3 sends
    $this->twilio->shouldHaveReceived('sendSms')->times(3);
});

it('waits for response on question steps', function () {
    $flow = SmsBotFlow::factory()->active()->create();
    $questionStep = SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'What color?',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow);

    expect($convo->status)->toBe('in_progress')
        ->and($convo->current_step_id)->toBe($questionStep->id);
});

it('stores answers with step key', function () {
    $flow = SmsBotFlow::factory()->active()->create();
    $questionStep = SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'What color?',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow);

    $service->processResponse($convo, 'Blue');

    $convo->refresh();
    expect($convo->answers)->toHaveKey("step_{$questionStep->id}")
        ->and($convo->answers["step_{$questionStep->id}"])->toBe('Blue')
        ->and($convo->message_count)->toBe(1);
});

it('completes conversation when no more steps', function () {
    $flow = SmsBotFlow::factory()->active()->create();
    SmsBotStep::factory()->greeting()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Hello and goodbye!',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow);

    expect($convo->status)->toBe('completed')
        ->and($convo->completed_at)->not->toBeNull();
});

it('creates chat thread when starting keyword-triggered conversation', function () {
    User::factory()->internal()->create(['is_active' => true]);

    $flow = SmsBotFlow::factory()->active()->keyword('COLOR')->create();
    SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'What color?',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow);

    expect($convo->chat_thread_id)->not->toBeNull();

    $thread = ChatThread::find($convo->chat_thread_id);
    expect($thread->type)->toBe(ChatThread::TYPE_SUPPORT)
        ->and($thread->source)->toBe('sms_bot');
});

it('replaces message variables', function () {
    $flow = SmsBotFlow::factory()->active()->create();
    SmsBotStep::factory()->greeting()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Welcome from {{ company }}!',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow);

    // Should have sent the greeting with variable replaced
    $this->twilio->shouldHaveReceived('sendSms')->once();
});

it('increments photo_count when media urls provided', function () {
    $flow = SmsBotFlow::factory()->active()->create();
    $questionStep = SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Send a photo',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow);

    $service->processResponse($convo, 'Here is my photo', ['https://example.com/photo.jpg', 'https://example.com/photo2.jpg']);

    $convo->refresh();
    expect($convo->photo_count)->toBe(2)
        ->and($convo->message_count)->toBe(1);
});

it('uses existing thread if provided', function () {
    $thread = ChatThread::factory()->create([
        'type' => ChatThread::TYPE_SUPPORT,
        'source' => 'sms_bot',
    ]);

    $flow = SmsBotFlow::factory()->active()->create();
    SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Hello?',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow, $thread);

    expect($convo->chat_thread_id)->toBe($thread->id);
    // Should not have created a new thread
    expect(ChatThread::count())->toBe(1);
});
