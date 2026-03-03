<?php

use App\Models\PropertyPhoto;
use App\Models\User;
use App\Models\VideoProject;
use Livewire\Livewire;

it('sets clip speed', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo->id)
        ->call('setClipSpeed', 0, 2.0);

    $clip = $project->scenes()->first()->clips()->first();
    expect((float) $clip->metadata['speed'])->toBe(2.0);
});

it('rejects invalid speed values', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo->id)
        ->call('setClipSpeed', 0, 3.0);

    $clip = $project->scenes()->first()->clips()->first();
    expect($clip->metadata['speed'] ?? null)->toBeNull();
});

it('defaults to 1x speed', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo->id);

    $clip = $project->scenes()->first()->clips()->first();
    expect($clip->metadata['speed'] ?? 1.0)->toBe(1.0);
});
