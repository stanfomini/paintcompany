<?php

use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has all expected step type constants', function () {
    expect(SmsBotStep::TYPE_GREETING)->toBe('greeting');
    expect(SmsBotStep::TYPE_MESSAGE)->toBe('message');
    expect(SmsBotStep::TYPE_MEDIA_MESSAGE)->toBe('media_message');
    expect(SmsBotStep::TYPE_PHOTO_PROMPT)->toBe('photo_prompt');
    expect(SmsBotStep::TYPE_AI_VISUALIZATION)->toBe('ai_visualization');
    expect(SmsBotStep::TYPE_QUESTION)->toBe('question');
    expect(SmsBotStep::TYPE_QUICK_REPLY)->toBe('quick_reply');
    expect(SmsBotStep::TYPE_DELAY)->toBe('delay');
    expect(SmsBotStep::TYPE_HANDOFF)->toBe('handoff');
});

it('does not have the retired TYPE_PHOTO_RECEIVED constant', function () {
    $reflection = new ReflectionClass(SmsBotStep::class);
    expect($reflection->hasConstant('TYPE_PHOTO_RECEIVED'))->toBeFalse();
});

it('stores media_url, source_step_id, and wait_for_media', function () {
    $flow = SmsBotFlow::factory()->create();

    $sourceStep = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'step_type' => SmsBotStep::TYPE_QUESTION,
        'message_text' => 'What color?',
    ]);

    $step = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'step_type' => SmsBotStep::TYPE_AI_VISUALIZATION,
        'message_text' => 'Processing your visualization...',
        'media_url' => 'https://example.com/image.jpg',
        'source_step_id' => $sourceStep->id,
        'wait_for_media' => true,
    ]);

    $step->refresh();

    expect($step->media_url)->toBe('https://example.com/image.jpg');
    expect($step->source_step_id)->toBe($sourceStep->id);
    expect($step->wait_for_media)->toBeTrue();
});

it('has sourceStep relationship', function () {
    $flow = SmsBotFlow::factory()->create();

    $sourceStep = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'step_type' => SmsBotStep::TYPE_QUESTION,
        'message_text' => 'Describe your colors',
    ]);

    $aiStep = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'step_type' => SmsBotStep::TYPE_AI_VISUALIZATION,
        'message_text' => 'Processing...',
        'source_step_id' => $sourceStep->id,
    ]);

    expect($aiStep->sourceStep)->toBeInstanceOf(SmsBotStep::class);
    expect($aiStep->sourceStep->id)->toBe($sourceStep->id);
});

it('returns true from requiresResponse for question, photo_prompt, and ai_visualization', function () {
    $flow = SmsBotFlow::factory()->create();

    $question = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'step_type' => SmsBotStep::TYPE_QUESTION,
        'message_text' => 'What room?',
    ]);

    $photoPrompt = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'step_type' => SmsBotStep::TYPE_PHOTO_PROMPT,
        'message_text' => 'Send a photo',
    ]);

    $aiViz = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 3,
        'step_type' => SmsBotStep::TYPE_AI_VISUALIZATION,
        'message_text' => 'Processing...',
    ]);

    expect($question->requiresResponse())->toBeTrue();
    expect($photoPrompt->requiresResponse())->toBeTrue();
    expect($aiViz->requiresResponse())->toBeTrue();
});

it('returns false from requiresResponse for auto-advance step types', function () {
    $flow = SmsBotFlow::factory()->create();

    $types = [
        SmsBotStep::TYPE_GREETING,
        SmsBotStep::TYPE_MESSAGE,
        SmsBotStep::TYPE_MEDIA_MESSAGE,
        SmsBotStep::TYPE_DELAY,
        SmsBotStep::TYPE_HANDOFF,
    ];

    foreach ($types as $type) {
        $step = SmsBotStep::create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'step_type' => $type,
            'message_text' => 'Test',
        ]);

        expect($step->requiresResponse())->toBeFalse("Expected {$type} to not require response");
    }
});

it('returns true from isQuestion only for question type', function () {
    $flow = SmsBotFlow::factory()->create();

    $question = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'step_type' => SmsBotStep::TYPE_QUESTION,
        'message_text' => 'What room?',
    ]);

    $message = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'step_type' => SmsBotStep::TYPE_MESSAGE,
        'message_text' => 'Hello',
    ]);

    expect($question->isQuestion())->toBeTrue();
    expect($message->isQuestion())->toBeFalse();
});

it('defaults wait_for_media to false', function () {
    $flow = SmsBotFlow::factory()->create();

    $step = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'step_type' => SmsBotStep::TYPE_MESSAGE,
        'message_text' => 'Hello',
    ]);

    $step->refresh();

    expect($step->wait_for_media)->toBeFalse();
});

it('casts wait_for_media to boolean', function () {
    $flow = SmsBotFlow::factory()->create();

    $step = SmsBotStep::create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'step_type' => SmsBotStep::TYPE_PHOTO_PROMPT,
        'message_text' => 'Send photo',
        'wait_for_media' => 1,
    ]);

    $step->refresh();

    expect($step->wait_for_media)->toBeBool();
    expect($step->wait_for_media)->toBeTrue();
});
