<?php

use App\Models\PropertyPhoto;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRecording;
use Livewire\Livewire;

it('renders the quick creator for a project', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id, 'creation_mode' => 'quick']);
    $this->actingAs($user)
        ->get(route('studio.create', $project))
        ->assertOk()
        ->assertSeeLivewire('studio.quick-creator');
});

it('creates a default scene on mount', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    expect($project->scenes()->count())->toBe(0);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project]);

    expect($project->fresh()->scenes()->count())->toBe(1);
});

it('forbids access to other users projects', function () {
    $owner = User::factory()->internal()->create();
    $other = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $owner->id]);

    Livewire::actingAs($other)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->assertForbidden();
});

it('adds a photo clip', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo->id);

    expect($project->scenes()->first()->clips()->count())->toBe(1);
    $clip = $project->scenes()->first()->clips()->first();
    expect($clip->source_type)->toBe(PropertyPhoto::class);
    expect($clip->source_id)->toBe($photo->id);
});

it('adds a video recording clip', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $recording = VideoRecording::factory()->create(['user_id' => $user->id, 'status' => 'ready']);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'video-recording', $recording->id);

    $clip = $project->scenes()->first()->clips()->first();
    expect($clip->source_type)->toBe(VideoRecording::class);
});

it('removes a clip', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo->id)
        ->call('removeClip', 0);

    expect($project->scenes()->first()->clips()->count())->toBe(0);
});

it('updates project title', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id, 'title' => 'Old']);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->set('title', 'New Title')
        ->call('saveTitle');

    expect($project->fresh()->title)->toBe('New Title');
});

it('changes aspect ratio', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id, 'aspect_ratio' => '16:9']);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('setAspectRatio', '9:16');

    expect($project->fresh()->aspect_ratio)->toBe('9:16');
});

it('handles media-selected event', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->dispatch('media-selected', type: 'property-photo', id: $photo->id);

    expect($project->scenes()->first()->clips()->count())->toBe(1);
});

it('handles media-selected event with full class name for photos', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->dispatch('media-selected', type: PropertyPhoto::class, id: $photo->id);

    $clip = $project->scenes()->first()->clips()->first();
    expect($clip)->not->toBeNull();
    expect($clip->source_type)->toBe(PropertyPhoto::class);
    expect($clip->source_id)->toBe($photo->id);
});

it('handles media-selected event with full class name for videos', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $recording = VideoRecording::factory()->create(['user_id' => $user->id, 'status' => 'ready']);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->dispatch('media-selected', type: VideoRecording::class, id: $recording->id);

    $clip = $project->scenes()->first()->clips()->first();
    expect($clip)->not->toBeNull();
    expect($clip->source_type)->toBe(VideoRecording::class);
    expect($clip->source_id)->toBe($recording->id);
});
