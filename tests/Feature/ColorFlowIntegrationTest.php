<?php

use App\Jobs\GenerateColorVisualizationJob;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use App\Models\SmsConversation;
use App\Services\SmsBotFlowService;
use App\Services\TwilioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake();

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->andReturnUsing(fn ($phone) => $phone);
        $mock->shouldReceive('sendSms')->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
        $mock->shouldReceive('sendMms')->andReturn(['success' => true, 'sid' => 'SM456', 'error' => null]);
    });
});

it('completes full COLOR keyword flow: trigger → questions → photo → AI → MMS result', function () {
    Bus::fake([GenerateColorVisualizationJob::class]);

    // Seed the COLOR flow inline (mirroring ColorVisualizationFlowSeeder)
    $flow = SmsBotFlow::factory()->active()->keyword('COLOR')->create([
        'name' => 'Color Visualization',
        'completion_action' => 'create_lead',
        'timeout_minutes' => 60,
    ]);

    $greeting = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'step_type' => SmsBotStep::TYPE_GREETING,
        'message_text' => 'Hey! Welcome to our Color Visualization tool!',
    ]);

    $mediaStep = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'step_type' => SmsBotStep::TYPE_MEDIA_MESSAGE,
        'message_text' => 'Check out this quick tutorial:',
        'media_url' => 'https://example.com/tutorial.mp4',
    ]);

    $roomQ = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 3,
        'step_type' => SmsBotStep::TYPE_QUESTION,
        'message_text' => 'What room are you looking to paint?',
        'field_mapping' => 'project.rooms',
    ]);

    $scopeQ = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 4,
        'step_type' => SmsBotStep::TYPE_QUESTION,
        'message_text' => 'Interior, exterior, or both?',
        'field_mapping' => 'project.scope_type',
    ]);

    $colorQ = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 5,
        'step_type' => SmsBotStep::TYPE_QUESTION,
        'message_text' => 'Describe your ideal colors!',
    ]);

    $photoPrompt = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 6,
        'step_type' => SmsBotStep::TYPE_PHOTO_PROMPT,
        'message_text' => 'Send a photo of the room!',
        'wait_for_media' => true,
    ]);

    $aiStep = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 7,
        'step_type' => SmsBotStep::TYPE_AI_VISUALIZATION,
        'message_text' => 'Processing your color visualization...',
        'source_step_id' => $colorQ->id,
    ]);

    $completionStep = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 8,
        'step_type' => SmsBotStep::TYPE_MESSAGE,
        'message_text' => 'Hope you love it! Ready for a free estimate? {{ booking_link }}',
    ]);

    $phone = '+13035550001';

    // Step 1: Customer sends "COLOR" keyword → triggers the flow
    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => $phone,
        'Body' => 'COLOR',
    ]);
    $response->assertStatus(200);

    // Verify conversation was created and greeting + media + room question were sent
    $conversation = SmsConversation::where('phone', $phone)->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->flow_id)->toBe($flow->id);
    expect($conversation->status)->toBe('in_progress');

    // Current step should be the room question (greeting + media auto-advance)
    expect($conversation->current_step_id)->toBe($roomQ->id);

    // Verify a ChatThread was created
    $thread = ChatThread::find($conversation->chat_thread_id);
    expect($thread)->not->toBeNull();

    // Step 2: Customer answers room question
    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => $phone,
        'Body' => 'Living Room',
    ]);
    $response->assertStatus(200);

    $conversation->refresh();
    expect($conversation->answers["step_{$roomQ->id}"])->toBe('Living Room');
    expect($conversation->current_step_id)->toBe($scopeQ->id);

    // Step 3: Customer answers scope question
    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => $phone,
        'Body' => 'Interior',
    ]);
    $response->assertStatus(200);

    $conversation->refresh();
    expect($conversation->answers["step_{$scopeQ->id}"])->toBe('Interior');
    expect($conversation->current_step_id)->toBe($colorQ->id);

    // Step 4: Customer describes desired colors
    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => $phone,
        'Body' => 'Sherwin-Williams Agreeable Gray walls, white trim',
    ]);
    $response->assertStatus(200);

    $conversation->refresh();
    expect($conversation->answers["step_{$colorQ->id}"])->toBe('Sherwin-Williams Agreeable Gray walls, white trim');
    expect($conversation->current_step_id)->toBe($photoPrompt->id);

    // Step 5: Customer sends a photo
    Http::fake([
        'https://api.twilio.com/media/room-photo.jpg' => Http::response('fake-room-photo-bytes', 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $response = $this->post(route('webhooks.twilio.sms'), [
        'From' => $phone,
        'Body' => '',
        'NumMedia' => '1',
        'MediaUrl0' => 'https://api.twilio.com/media/room-photo.jpg',
    ]);
    $response->assertStatus(200);

    $conversation->refresh();
    // Photo should be stored in answers
    $photoPath = $conversation->answers["step_{$photoPrompt->id}_photo"] ?? null;
    expect($photoPath)->not->toBeNull();
    expect(str_starts_with($photoPath, 'chat-attachments/'))->toBeTrue();

    // Photo should exist in storage
    Storage::assertExists($photoPath);

    // A ChatAttachment should have been created for the photo
    $photoMessage = ChatMessage::where('thread_id', $thread->id)
        ->where('type', ChatMessage::TYPE_IMAGE)
        ->latest()
        ->first();
    expect($photoMessage)->not->toBeNull();

    $photoAttachment = ChatAttachment::where('message_id', $photoMessage->id)->first();
    expect($photoAttachment)->not->toBeNull();

    // Should now be on the AI visualization step
    expect($conversation->current_step_id)->toBe($aiStep->id);

    // GenerateColorVisualizationJob should have been dispatched
    Bus::assertDispatched(GenerateColorVisualizationJob::class);

    // Step 6: Simulate AI visualization completion
    $resultPath = 'visualizations/result-image.png';
    Storage::put($resultPath, 'fake-visualization-result');

    $botService = app(SmsBotFlowService::class);
    $botService->onVisualizationComplete($conversation, $resultPath);

    $conversation->refresh();
    expect($conversation->answers['visualization_result'])->toBe($resultPath);

    // Should have advanced past the completion message and completed the conversation
    expect($conversation->status)->toBe('completed');
    expect($conversation->completed_at)->not->toBeNull();

    // All messages should be visible on the ChatThread
    $allMessages = ChatMessage::where('thread_id', $thread->id)->get();
    expect($allMessages->count())->toBeGreaterThan(5);
});

it('handles COLOR flow with only text responses (no photo fallback)', function () {
    // A simpler flow without AI step — just questions and completion
    $flow = SmsBotFlow::factory()->active()->keyword('PAINT')->create([
        'name' => 'Simple Color Flow',
        'timeout_minutes' => 60,
    ]);

    SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'step_type' => SmsBotStep::TYPE_GREETING,
        'message_text' => 'Welcome!',
    ]);

    SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'step_type' => SmsBotStep::TYPE_QUESTION,
        'message_text' => 'What room?',
    ]);

    SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 3,
        'step_type' => SmsBotStep::TYPE_MESSAGE,
        'message_text' => 'Thanks! We will be in touch.',
    ]);

    $phone = '+13035550002';

    // Trigger flow
    $this->post(route('webhooks.twilio.sms'), [
        'From' => $phone,
        'Body' => 'PAINT',
    ])->assertStatus(200);

    $conversation = SmsConversation::where('phone', $phone)->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->status)->toBe('in_progress');

    // Answer the question
    $this->post(route('webhooks.twilio.sms'), [
        'From' => $phone,
        'Body' => 'Kitchen',
    ])->assertStatus(200);

    $conversation->refresh();

    // Should be completed (greeting auto-advanced, question answered, completion message auto-advanced)
    expect($conversation->status)->toBe('completed');
    expect($conversation->completed_at)->not->toBeNull();
});
