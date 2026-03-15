<?php

use App\Jobs\GeneratePreviewVisualizationJob;
use App\Jobs\ProcessPreviewPhotoJob;
use App\Models\CompanySetting;
use App\Models\ProjectPreviewPhoto;
use App\Models\ProjectPreviewVisualization;
use App\Services\PythonBridgeService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('dispatches visualization jobs for each default color', function () {
    Queue::fake([GeneratePreviewVisualizationJob::class]);
    Storage::fake('public');

    // Mock Python service
    $python = Mockery::mock(PythonBridgeService::class);
    $python->shouldReceive('detectSubstrate')->andReturn(['condition' => 'Good']);
    app()->instance(PythonBridgeService::class, $python);

    // Set up default colors
    CompanySetting::getInstance()->update([
        'default_visualization_colors' => [
            ['name' => 'Color A', 'hex' => '#AAAAAA', 'brand' => 'Test'],
            ['name' => 'Color B', 'hex' => '#BBBBBB', 'brand' => 'Test'],
            ['name' => 'Color C', 'hex' => '#CCCCCC', 'brand' => 'Test'],
        ],
    ]);

    Storage::disk('public')->put('previews/test.jpg', 'fake-image');
    $photo = ProjectPreviewPhoto::factory()->create([
        'original_path' => 'previews/test.jpg',
        'disk' => 'public',
    ]);

    ProcessPreviewPhotoJob::dispatchSync($photo);

    // Should create 3 visualization records
    expect(ProjectPreviewVisualization::where('project_preview_photo_id', $photo->id)->count())->toBe(3);

    // Should dispatch 3 visualization jobs
    Queue::assertPushed(GeneratePreviewVisualizationJob::class, 3);
});

it('handles substrate detection failure gracefully', function () {
    Queue::fake([GeneratePreviewVisualizationJob::class]);
    Storage::fake('public');

    // Mock Python service to fail
    $python = Mockery::mock(PythonBridgeService::class);
    $python->shouldReceive('detectSubstrate')->andThrow(new \Exception('Service unavailable'));
    app()->instance(PythonBridgeService::class, $python);

    Storage::disk('public')->put('previews/test.jpg', 'fake-image');
    $photo = ProjectPreviewPhoto::factory()->create([
        'original_path' => 'previews/test.jpg',
        'disk' => 'public',
    ]);

    // Should not throw — substrate detection is optional
    ProcessPreviewPhotoJob::dispatchSync($photo);

    // Visualizations should still be dispatched
    Queue::assertPushed(GeneratePreviewVisualizationJob::class);
});

it('sets first visualization as primary', function () {
    Queue::fake([GeneratePreviewVisualizationJob::class]);
    Storage::fake('public');

    $python = Mockery::mock(PythonBridgeService::class);
    $python->shouldReceive('detectSubstrate')->andReturn([]);
    app()->instance(PythonBridgeService::class, $python);

    Storage::disk('public')->put('previews/test.jpg', 'fake-image');
    $photo = ProjectPreviewPhoto::factory()->create([
        'original_path' => 'previews/test.jpg',
        'disk' => 'public',
    ]);

    ProcessPreviewPhotoJob::dispatchSync($photo);

    $visualizations = ProjectPreviewVisualization::where('project_preview_photo_id', $photo->id)
        ->orderBy('sort_order')
        ->get();

    expect($visualizations->first()->is_primary)->toBeTrue()
        ->and($visualizations->skip(1)->every(fn ($v) => ! $v->is_primary))->toBeTrue();
});
