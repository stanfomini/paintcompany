<?php

use App\Ai\Agents\ColorVisualizerAgent;
use App\Events\ColorVisualizationCompleted;
use App\Jobs\GenerateColorVisualizationJob;
use App\Models\ColorVisualization;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Image;

it('updates status to processing then completed', function () {
    Event::fake([ColorVisualizationCompleted::class]);

    ColorVisualizerAgent::fake([
        "- Surface: walls | Color: Benjamin Moore White Dove (OC-17) | Hex: #F3EFE0\n- Surface: trim | Color: Sherwin-Williams Iron Ore (SW-7069) | Hex: #433F3E",
    ]);

    Image::fake();

    $visualization = ColorVisualization::factory()->create([
        'prompt' => 'BM White Dove on walls, SW Iron Ore on trim',
        'original_image_path' => 'photos/test-house.jpg',
    ]);

    expect($visualization->isPending())->toBeTrue();

    (new GenerateColorVisualizationJob($visualization))->handle();

    $visualization->refresh();

    expect($visualization->isCompleted())->toBeTrue()
        ->and($visualization->result_image_path)->not->toBeNull()
        ->and($visualization->parsed_colors)->toBeArray()
        ->and($visualization->parsed_colors)->not->toBeEmpty();
});

it('stores parsed colors from agent response', function () {
    Event::fake([ColorVisualizationCompleted::class]);

    ColorVisualizerAgent::fake([
        '- Surface: walls | Color: Benjamin Moore White Dove (OC-17) | Hex: #F3EFE0',
    ]);

    Image::fake();

    $visualization = ColorVisualization::factory()->create([
        'prompt' => 'Benjamin Moore White Dove on walls',
        'original_image_path' => 'photos/test.jpg',
    ]);

    (new GenerateColorVisualizationJob($visualization))->handle();

    $visualization->refresh();
    $colors = $visualization->parsed_colors;

    expect($colors)->toHaveCount(1)
        ->and($colors[0]['name'])->toBe('White Dove')
        ->and($colors[0]['hex'])->toBe('#F3EFE0')
        ->and($colors[0]['brand'])->toBe('Benjamin Moore');
});

it('broadcasts completion event', function () {
    Event::fake([ColorVisualizationCompleted::class]);

    ColorVisualizerAgent::fake(['No colors found']);
    Image::fake();

    $visualization = ColorVisualization::factory()->create([
        'original_image_path' => 'photos/test.jpg',
    ]);

    (new GenerateColorVisualizationJob($visualization))->handle();

    Event::assertDispatched(ColorVisualizationCompleted::class, function ($event) use ($visualization) {
        return $event->visualization->uuid === $visualization->uuid;
    });
});

it('marks as failed on exception', function () {
    ColorVisualizerAgent::fake(function () {
        throw new \Exception('API timeout');
    });

    $visualization = ColorVisualization::factory()->create([
        'original_image_path' => 'photos/test.jpg',
    ]);

    try {
        (new GenerateColorVisualizationJob($visualization))->handle();
    } catch (\Exception $e) {
        // Expected
    }

    $visualization->refresh();
    expect($visualization->isFailed())->toBeTrue()
        ->and($visualization->error_message)->toContain('API timeout');
});

it('calls Image::of with enhanced prompt', function () {
    Event::fake([ColorVisualizationCompleted::class]);

    ColorVisualizerAgent::fake([
        '- Surface: walls | Color: Benjamin Moore White Dove (OC-17) | Hex: #F3EFE0',
    ]);

    Image::fake();

    $visualization = ColorVisualization::factory()->create([
        'prompt' => 'BM White Dove on walls',
        'original_image_path' => 'photos/test.jpg',
    ]);

    (new GenerateColorVisualizationJob($visualization))->handle();

    Image::assertGenerated(function ($pendingGeneration) {
        return str_contains($pendingGeneration->prompt, '#F3EFE0')
            && str_contains($pendingGeneration->prompt, 'Edit this photograph');
    });
});

it('handles agent response with fallback format', function () {
    Event::fake([ColorVisualizationCompleted::class]);

    ColorVisualizerAgent::fake([
        "Benjamin Moore White Dove (OC-17): #F3EFE0\nSherwin-Williams Iron Ore (SW-7069): #433F3E",
    ]);

    Image::fake();

    $visualization = ColorVisualization::factory()->create([
        'original_image_path' => 'photos/test.jpg',
    ]);

    (new GenerateColorVisualizationJob($visualization))->handle();

    $visualization->refresh();
    expect($visualization->parsed_colors)->toHaveCount(2);
});
