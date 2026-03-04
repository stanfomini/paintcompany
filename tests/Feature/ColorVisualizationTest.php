<?php

use App\Models\ColorVisualization;
use App\Models\Estimate;
use App\Models\User;
use App\Services\ColorVisualizationService;

it('can create a color visualization', function () {
    $viz = ColorVisualization::factory()->create();

    expect($viz)->toBeInstanceOf(ColorVisualization::class)
        ->and($viz->uuid)->not->toBeEmpty()
        ->and($viz->status)->toBe(ColorVisualization::STATUS_PENDING);
});

it('auto-generates uuid on creation', function () {
    $viz = ColorVisualization::factory()->create(['uuid' => null]);

    expect($viz->uuid)->not->toBeNull()
        ->and(strlen($viz->uuid))->toBe(36);
});

it('belongs to a user', function () {
    $user = User::factory()->create();
    $viz = ColorVisualization::factory()->forUser($user)->create();

    expect($viz->user->id)->toBe($user->id);
});

it('belongs to an estimate', function () {
    $viz = ColorVisualization::factory()->forEstimate()->create();

    expect($viz->estimate)->toBeInstanceOf(Estimate::class);
});

it('can transition through statuses', function () {
    $viz = ColorVisualization::factory()->create();

    expect($viz->isPending())->toBeTrue();

    $viz->markAsProcessing();
    expect($viz->fresh()->isProcessing())->toBeTrue();

    $viz->markAsCompleted('photos/result.png', [['name' => 'Test', 'hex' => '#FFFFFF']]);
    $viz->refresh();
    expect($viz->isCompleted())->toBeTrue()
        ->and($viz->result_image_path)->toBe('photos/result.png')
        ->and($viz->parsed_colors)->toHaveCount(1);
});

it('can mark as failed', function () {
    $viz = ColorVisualization::factory()->create();
    $viz->markAsFailed('API error');

    expect($viz->fresh()->isFailed())->toBeTrue()
        ->and($viz->fresh()->error_message)->toBe('API error');
});

it('scopes by status', function () {
    ColorVisualization::factory()->create(['status' => ColorVisualization::STATUS_PENDING]);
    ColorVisualization::factory()->create(['status' => ColorVisualization::STATUS_COMPLETED]);
    ColorVisualization::factory()->create(['status' => ColorVisualization::STATUS_FAILED]);

    expect(ColorVisualization::pending()->count())->toBe(1)
        ->and(ColorVisualization::completed()->count())->toBe(1)
        ->and(ColorVisualization::failed()->count())->toBe(1);
});

it('scopes by session', function () {
    ColorVisualization::factory()->withSession('session-abc')->count(2)->create();
    ColorVisualization::factory()->withSession('session-xyz')->create();

    expect(ColorVisualization::forSession('session-abc')->count())->toBe(2)
        ->and(ColorVisualization::forSession('session-xyz')->count())->toBe(1);
});

it('uses uuid as route key', function () {
    $viz = ColorVisualization::factory()->create();
    expect($viz->getRouteKeyName())->toBe('uuid');
});

it('casts parsed_colors as array', function () {
    $colors = [['name' => 'White Dove', 'hex' => '#F3EFE0']];
    $viz = ColorVisualization::factory()->create(['parsed_colors' => $colors]);

    expect($viz->fresh()->parsed_colors)->toBeArray()
        ->and($viz->fresh()->parsed_colors[0]['name'])->toBe('White Dove');
});

it('service tracks session usage', function () {
    $service = new ColorVisualizationService;

    expect($service->getSessionUsageCount('test-session'))->toBe(0)
        ->and($service->canGenerateForSession('test-session'))->toBeTrue()
        ->and($service->getRemainingFreeUses('test-session'))->toBe(2);
});

it('service blocks after free limit reached', function () {
    $service = new ColorVisualizationService;
    $sessionId = 'test-session-limit';

    ColorVisualization::factory()->withSession($sessionId)->count(2)->create();

    expect($service->canGenerateForSession($sessionId))->toBeFalse()
        ->and($service->getRemainingFreeUses($sessionId))->toBe(0);
});

it('service creates visualization', function () {
    $service = new ColorVisualizationService;

    $viz = $service->create([
        'original_image_path' => 'photos/test.jpg',
        'prompt' => 'Paint it white',
        'session_id' => 'test-session',
    ]);

    expect($viz)->toBeInstanceOf(ColorVisualization::class)
        ->and($viz->exists)->toBeTrue()
        ->and($viz->uuid)->not->toBeEmpty();
});
