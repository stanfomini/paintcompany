<?php

use App\Models\PropertyPhoto;
use App\Models\User;
use App\Models\VideoProject;
use Livewire\Livewire;

it('sets a transition on a clip', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo1 = PropertyPhoto::factory()->create();
    $photo2 = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo1->id)
        ->call('addClip', 'property-photo', $photo2->id)
        ->call('setTransition', 1, 'fade', 500);

    $clip = $project->scenes()->first()->clips()->orderBy('sort_order')->skip(1)->first();
    expect($clip->transition_type)->toBe('fade');
    expect($clip->transition_duration_ms)->toBe(500);
});

it('defaults transition duration to 500ms', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo1 = PropertyPhoto::factory()->create();
    $photo2 = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo1->id)
        ->call('addClip', 'property-photo', $photo2->id)
        ->call('setTransition', 1, 'slide-left');

    $clip = $project->scenes()->first()->clips()->orderBy('sort_order')->skip(1)->first();
    expect($clip->transition_type)->toBe('slide-left');
    expect($clip->transition_duration_ms)->toBe(500);
});

it('rejects invalid transition types', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo1 = PropertyPhoto::factory()->create();
    $photo2 = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo1->id)
        ->call('addClip', 'property-photo', $photo2->id)
        ->call('setTransition', 1, 'invalid-type');

    $clip = $project->scenes()->first()->clips()->orderBy('sort_order')->skip(1)->first();
    expect($clip->transition_type)->toBeNull();
});

it('can change transition type on a clip', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo1 = PropertyPhoto::factory()->create();
    $photo2 = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo1->id)
        ->call('addClip', 'property-photo', $photo2->id)
        ->call('setTransition', 1, 'fade')
        ->call('setTransition', 1, 'zoom', 750);

    $clip = $project->scenes()->first()->clips()->orderBy('sort_order')->skip(1)->first();
    expect($clip->transition_type)->toBe('zoom');
    expect($clip->transition_duration_ms)->toBe(750);
});
