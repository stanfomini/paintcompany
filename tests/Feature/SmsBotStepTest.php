<?php

use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('has correct type constants', function () {
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

it('does not have TYPE_PHOTO_RECEIVED constant', function () {
    $reflection = new ReflectionClass(SmsBotStep::class);
    $constants = $reflection->getConstants();

    expect($constants)->not->toHaveKey('TYPE_PHOTO_RECEIVED');
});

it('can be created with media columns', function () {
    $flow = SmsBotFlow::factory()->create();
    $step = SmsBotStep::factory()->mediaMessage()->create([
        'flow_id' => $flow->id,
        'media_url' => 'https://example.com/image.jpg',
        'wait_for_media' => false,
    ]);

    expect($step->media_url)->toBe('https://example.com/image.jpg');
    expect($step->wait_for_media)->toBeFalse();
});

it('casts wait_for_media to boolean', function () {
    $step = SmsBotStep::factory()->photoPrompt()->create();

    expect($step->wait_for_media)->toBeTrue();
    expect($step->wait_for_media)->toBeBool();
});

it('can reference a source step', function () {
    $flow = SmsBotFlow::factory()->create();
    $sourceStep = SmsBotStep::factory()->question()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
    ]);
    $aiStep = SmsBotStep::factory()->aiVisualization()->create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'source_step_id' => $sourceStep->id,
    ]);

    expect($aiStep->sourceStep->id)->toBe($sourceStep->id);
    expect($aiStep->sourceStep)->toBeInstanceOf(SmsBotStep::class);
});

it('nulls source_step_id when source step is deleted', function () {
    $flow = SmsBotFlow::factory()->create();
    $sourceStep = SmsBotStep::factory()->photoPrompt()->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
    ]);
    $aiStep = SmsBotStep::factory()->aiVisualization()->create([
        'flow_id' => $flow->id,
        'sort_order' => 2,
        'source_step_id' => $sourceStep->id,
    ]);

    $sourceStep->delete();
    $aiStep->refresh();

    expect($aiStep->source_step_id)->toBeNull();
});

it('returns true for requiresResponse on question type', function () {
    $step = SmsBotStep::factory()->question()->make();

    expect($step->requiresResponse())->toBeTrue();
});

it('returns true for requiresResponse on photo_prompt type', function () {
    $step = SmsBotStep::factory()->photoPrompt()->make();

    expect($step->requiresResponse())->toBeTrue();
});

it('returns true for requiresResponse on ai_visualization type', function () {
    $step = SmsBotStep::factory()->aiVisualization()->make();

    expect($step->requiresResponse())->toBeTrue();
});

it('returns false for requiresResponse on message type', function () {
    $step = SmsBotStep::factory()->message()->make();

    expect($step->requiresResponse())->toBeFalse();
});

it('returns false for requiresResponse on greeting type', function () {
    $step = SmsBotStep::factory()->greeting()->make();

    expect($step->requiresResponse())->toBeFalse();
});

it('returns false for requiresResponse on media_message type', function () {
    $step = SmsBotStep::factory()->mediaMessage()->make();

    expect($step->requiresResponse())->toBeFalse();
});

it('returns true for isQuestion only on question type', function () {
    expect(SmsBotStep::factory()->question()->make()->isQuestion())->toBeTrue();
    expect(SmsBotStep::factory()->greeting()->make()->isQuestion())->toBeFalse();
    expect(SmsBotStep::factory()->photoPrompt()->make()->isQuestion())->toBeFalse();
    expect(SmsBotStep::factory()->message()->make()->isQuestion())->toBeFalse();
});

it('belongs to a flow', function () {
    $flow = SmsBotFlow::factory()->create();
    $step = SmsBotStep::factory()->create(['flow_id' => $flow->id]);

    expect($step->flow->id)->toBe($flow->id);
});

it('includes new fields in fillable', function () {
    $step = new SmsBotStep;
    $fillable = $step->getFillable();

    expect($fillable)->toContain('media_url');
    expect($fillable)->toContain('source_step_id');
    expect($fillable)->toContain('wait_for_media');
});
