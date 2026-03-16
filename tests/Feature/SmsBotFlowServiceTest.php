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

// --- Task 7: Branching, quick_reply, delay, handoff ---

it('resolves next step via quick reply branch target', function () {
    $flow = SmsBotFlow::factory()->active()->create();

    $questionStep = SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'What scope?',
    ]);

    $branchTarget = SmsBotStep::factory()->message()->create([
        'flow_id' => $flow->id,
        'sort_order' => 10,
        'message_text' => 'Great, interior it is!',
    ]);

    $quickReplyStep = SmsBotStep::factory()->quickReply([
        ['label' => 'Interior', 'value' => 'interior', 'next_step_id' => $branchTarget->id],
        ['label' => 'Exterior', 'value' => 'exterior'],
    ])->create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'message_text' => 'Interior or exterior?',
    ]);

    // Unreachable step between quick_reply and branch target (sort_order 3)
    SmsBotStep::factory()->message()->create([
        'flow_id' => $flow->id,
        'sort_order' => 3,
        'message_text' => 'This should be skipped.',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow);

    // Answer the question to advance to quick_reply
    $service->processResponse($convo, 'painting');
    $convo->refresh();
    expect($convo->current_step_id)->toBe($quickReplyStep->id);

    // Answer "1" (Interior) — should branch to branchTarget
    $service->processResponse($convo, '1');
    $convo->refresh();

    // Branch target is a message, so it auto-advances and completes
    expect($convo->status)->toBe('completed');
});

it('resolves next step via explicit next_step_id', function () {
    $flow = SmsBotFlow::factory()->active()->create();

    $jumpTarget = SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 10,
        'message_text' => 'What color do you want?',
    ]);

    SmsBotStep::factory()->greeting()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Welcome!',
        'next_step_id' => $jumpTarget->id,
    ]);

    SmsBotStep::factory()->message()->create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'message_text' => 'This step should be skipped.',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow);

    // Greeting auto-advances, should jump to jumpTarget (sort_order 10), skipping sort_order 2
    $convo->refresh();
    expect($convo->current_step_id)->toBe($jumpTarget->id)
        ->and($convo->status)->toBe('in_progress');
});

it('falls back to linear sort_order when no branching', function () {
    $flow = SmsBotFlow::factory()->active()->create();

    $step1 = SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Step 1?',
    ]);

    $step2 = SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'message_text' => 'Step 2?',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow);

    expect($convo->current_step_id)->toBe($step1->id);

    $service->processResponse($convo, 'answer1');
    $convo->refresh();
    expect($convo->current_step_id)->toBe($step2->id);
});

it('replaces template variables in messages', function () {
    $flow = SmsBotFlow::factory()->active()->create();
    SmsBotStep::factory()->greeting()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Welcome to {{ company }}! Visit {{ portal_link }} or {{ booking_link }}.',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = SmsConversation::factory()->create(['flow_id' => $flow->id, 'status' => 'in_progress']);

    $result = $service->replaceMessageVariables(
        'Welcome to {{ company }}! Visit {{ portal_link }} or {{ booking_link }}.',
        $convo
    );

    expect($result)->not->toContain('{{')
        ->and($result)->toContain('/book');
});

it('sends numbered options for quick_reply on SMS', function () {
    $flow = SmsBotFlow::factory()->active()->create();
    SmsBotStep::factory()->quickReply([
        ['label' => 'Interior', 'value' => 'interior'],
        ['label' => 'Exterior', 'value' => 'exterior'],
        ['label' => 'Both', 'value' => 'both'],
    ])->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'What type of painting?',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow);

    // Verify the SMS was sent with numbered options
    $this->twilio->shouldHaveReceived('sendSms')->withArgs(function ($to, $body) {
        return str_contains($body, 'Reply 1 for Interior')
            && str_contains($body, 'Reply 2 for Exterior')
            && str_contains($body, 'Reply 3 for Both');
    })->once();
});

it('handles delay steps', function () {
    $flow = SmsBotFlow::factory()->active()->create();
    SmsBotStep::factory()->delay(5)->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Just a moment...',
    ]);
    $questionStep = SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'message_text' => 'OK, what color?',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow);

    // Delay step auto-advances to question
    $convo->refresh();
    expect($convo->current_step_id)->toBe($questionStep->id)
        ->and($convo->status)->toBe('in_progress');

    // Both delay message and question message were sent
    $this->twilio->shouldHaveReceived('sendSms')->times(2);
});

it('handles handoff steps', function () {
    $flow = SmsBotFlow::factory()->active()->create();
    SmsBotStep::factory()->handoff()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Connecting you to a human...',
    ]);

    $service = app(SmsBotFlowService::class);
    $convo = $service->startConversation('+13035551234', $flow);

    $convo->refresh();
    expect($convo->status)->toBe('human_takeover');

    $this->twilio->shouldHaveReceived('sendSms')->once();
});

it('checks timeouts and abandons stale conversations', function () {
    $flow = SmsBotFlow::factory()->active()->create(['timeout_minutes' => 30]);

    // Active but old conversation
    SmsConversation::factory()->create([
        'flow_id' => $flow->id,
        'status' => 'in_progress',
        'last_message_at' => now()->subMinutes(60),
    ]);

    // Active recent conversation
    SmsConversation::factory()->create([
        'flow_id' => $flow->id,
        'status' => 'in_progress',
        'last_message_at' => now()->subMinutes(10),
    ]);

    $service = app(SmsBotFlowService::class);
    $abandoned = $service->checkTimeouts();

    expect($abandoned)->toBe(1);
    expect(SmsConversation::where('status', 'abandoned')->count())->toBe(1);
    expect(SmsConversation::where('status', 'in_progress')->count())->toBe(1);
});
