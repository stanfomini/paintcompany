<?php

use App\Jobs\GenerateColorVisualizationJob;
use App\Models\ColorVisualization;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('shows session history when completed visualizations exist', function () {
    $this->get(route('visualize'));
    $sessionId = session()->getId();

    ColorVisualization::factory()
        ->withSession($sessionId)
        ->completed()
        ->count(3)
        ->create();

    Livewire::test('public.color-visualizer')
        ->assertSee('Your Visualizations');
});

it('does not show history strip when no completed visualizations', function () {
    Livewire::test('public.color-visualizer')
        ->assertDontSee('Your Visualizations');
});

it('try different colors keeps reuse image and clears prompt', function () {
    Queue::fake();
    Storage::fake();

    $this->get(route('visualize'));
    $sessionId = session()->getId();

    $viz = ColorVisualization::factory()
        ->withSession($sessionId)
        ->completed()
        ->create(['original_image_path' => 'photos/house.jpg', 'disk' => 'local']);

    Livewire::test('public.color-visualizer')
        ->set('visualizationId', $viz->id)
        ->call('tryDifferentColors')
        ->assertSet('reuseImagePath', 'photos/house.jpg')
        ->assertSet('reuseImageDisk', 'local')
        ->assertSet('prompt', '')
        ->assertSet('visualizationId', null);
});

it('start fresh clears everything', function () {
    $this->get(route('visualize'));
    $sessionId = session()->getId();

    $viz = ColorVisualization::factory()
        ->withSession($sessionId)
        ->completed()
        ->create();

    Livewire::test('public.color-visualizer')
        ->set('visualizationId', $viz->id)
        ->call('startFresh')
        ->assertSet('reuseImagePath', null)
        ->assertSet('reuseImageDisk', null)
        ->assertSet('prompt', '')
        ->assertSet('visualizationId', null)
        ->assertSet('uploadedPhoto', null);
});

it('can generate with reused image path', function () {
    Queue::fake();
    Storage::fake();

    Livewire::test('public.color-visualizer')
        ->set('reuseImagePath', 'photos/house.jpg')
        ->set('reuseImageDisk', 'local')
        ->set('prompt', 'Paint the walls a nice blue color please')
        ->call('generateVisualization')
        ->assertSet('isGenerating', true);

    Queue::assertPushed(GenerateColorVisualizationJob::class);

    $viz = ColorVisualization::latest()->first();
    expect($viz->original_image_path)->toBe('photos/house.jpg');
});

it('view history loads a past visualization', function () {
    $this->get(route('visualize'));
    $sessionId = session()->getId();

    $viz = ColorVisualization::factory()
        ->withSession($sessionId)
        ->completed()
        ->create();

    Livewire::test('public.color-visualizer')
        ->call('viewHistory', $viz->id)
        ->assertSet('visualizationId', $viz->id);
});

it('view history rejects visualizations from different sessions', function () {
    $viz = ColorVisualization::factory()
        ->withSession('other-session-id')
        ->completed()
        ->create();

    Livewire::test('public.color-visualizer')
        ->call('viewHistory', $viz->id)
        ->assertSet('visualizationId', null);
});

it('clear reuse removes reuse state', function () {
    Livewire::test('public.color-visualizer')
        ->set('reuseImagePath', 'photos/house.jpg')
        ->set('reuseImageDisk', 'local')
        ->call('clearReuse')
        ->assertSet('reuseImagePath', null)
        ->assertSet('reuseImageDisk', null);
});

it('shows reuse indicator when reuseImagePath is set', function () {
    Storage::fake();

    Livewire::test('public.color-visualizer')
        ->set('reuseImagePath', 'photos/house.jpg')
        ->set('reuseImageDisk', 'local')
        ->assertSee('Using previous photo')
        ->assertSee('Upload a different photo instead');
});
