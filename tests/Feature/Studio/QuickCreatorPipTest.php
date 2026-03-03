<?php

use App\Models\PropertyPhoto;
use App\Models\User;
use App\Models\VideoProject;
use Livewire\Livewire;

it('sets a clip as PIP background', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo->id)
        ->call('setClipAsPipBackground', 0)
        ->assertSet('pipBackgroundIndex', 0);
});

it('clears PIP background', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo->id)
        ->call('setClipAsPipBackground', 0)
        ->call('clearPipBackground')
        ->assertSet('pipBackgroundIndex', null);
});

it('ignores invalid clip index for PIP', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('setClipAsPipBackground', 99)
        ->assertSet('pipBackgroundIndex', null);
});
