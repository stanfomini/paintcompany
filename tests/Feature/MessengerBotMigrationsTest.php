<?php

use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- MessengerBotFlow: trigger_keyword ---

it('has trigger_keyword in fillable', function () {
    $flow = new MessengerBotFlow;

    expect($flow->getFillable())->toContain('trigger_keyword');
});

it('can create flow with trigger_keyword', function () {
    $flow = MessengerBotFlow::factory()->active()->create([
        'trigger_keyword' => 'COLOR',
    ]);

    expect($flow->trigger_keyword)->toBe('COLOR');
});

it('finds active flow by keyword case-insensitively', function () {
    MessengerBotFlow::factory()->active()->create([
        'trigger_keyword' => 'COLOR',
    ]);

    $found = MessengerBotFlow::findByKeyword('color');
    expect($found)->not->toBeNull();
    expect($found->trigger_keyword)->toBe('COLOR');
});

it('finds active flow by keyword with whitespace', function () {
    MessengerBotFlow::factory()->active()->create([
        'trigger_keyword' => 'COLOR',
    ]);

    $found = MessengerBotFlow::findByKeyword('  color  ');
    expect($found)->not->toBeNull();
});

it('does not find inactive flow by keyword', function () {
    MessengerBotFlow::factory()->create([
        'status' => 'draft',
        'trigger_keyword' => 'COLOR',
    ]);

    expect(MessengerBotFlow::findByKeyword('color'))->toBeNull();
});

it('returns null when no flow matches keyword', function () {
    expect(MessengerBotFlow::findByKeyword('NONEXISTENT'))->toBeNull();
});

// --- MessengerBotStep: media_url and new type constants ---

it('has TYPE_MEDIA_MESSAGE constant', function () {
    expect(MessengerBotStep::TYPE_MEDIA_MESSAGE)->toBe('media_message');
});

it('has TYPE_PHOTO_PROMPT constant', function () {
    expect(MessengerBotStep::TYPE_PHOTO_PROMPT)->toBe('photo_prompt');
});

it('has media_url in fillable', function () {
    $step = new MessengerBotStep;

    expect($step->getFillable())->toContain('media_url');
});

it('can create step with media_url', function () {
    $step = MessengerBotStep::factory()->mediaMessage()->create();

    expect($step->media_url)->toBe('https://example.com/image.jpg');
    expect($step->step_type)->toBe('media_message');
});

it('can create photo prompt step', function () {
    $step = MessengerBotStep::factory()->photoPrompt()->create();

    expect($step->step_type)->toBe('photo_prompt');
});

it('stores null media_url by default', function () {
    $step = MessengerBotStep::factory()->greeting()->create();

    expect($step->media_url)->toBeNull();
});
