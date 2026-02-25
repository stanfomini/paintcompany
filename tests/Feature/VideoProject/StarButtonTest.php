<?php

use App\Models\User;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders star button for video recording', function () {
    $user = User::factory()->internal()->create();
    $recording = VideoRecording::factory()->ready()->create();

    Livewire::actingAs($user)
        ->test('media.star-button', [
            'starrableType' => VideoRecording::class,
            'starrableId' => $recording->id,
        ])
        ->assertSuccessful()
        ->assertSet('isStarred', false);
});

it('toggles star on and off', function () {
    $user = User::factory()->internal()->create();
    $recording = VideoRecording::factory()->ready()->create();

    Livewire::actingAs($user)
        ->test('media.star-button', [
            'starrableType' => VideoRecording::class,
            'starrableId' => $recording->id,
        ])
        ->call('toggle')
        ->assertSet('isStarred', true)
        ->call('toggle')
        ->assertSet('isStarred', false);
});

it('shows filled star when already starred', function () {
    $user = User::factory()->internal()->create();
    $recording = VideoRecording::factory()->ready()->create();
    $recording->toggleStar($user);

    Livewire::actingAs($user)
        ->test('media.star-button', [
            'starrableType' => VideoRecording::class,
            'starrableId' => $recording->id,
        ])
        ->assertSet('isStarred', true);
});

it('dispatches star-toggled event on toggle', function () {
    $user = User::factory()->internal()->create();
    $recording = VideoRecording::factory()->ready()->create();

    Livewire::actingAs($user)
        ->test('media.star-button', [
            'starrableType' => VideoRecording::class,
            'starrableId' => $recording->id,
        ])
        ->call('toggle')
        ->assertDispatched('star-toggled');
});
