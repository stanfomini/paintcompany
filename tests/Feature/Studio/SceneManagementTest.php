<?php

use App\Models\StreamSession;
use App\Models\User;
use Livewire\Livewire;

it('can switch scenes', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('studio.studio', ['session' => $session])
        ->call('switchScene', 1)
        ->assertDispatched('scene-switched');

    $session->refresh();
    expect($session->current_scene_index)->toBe(1);
});

it('can save scenes', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->for($user)->create();

    $newScenes = [
        ['name' => 'Custom Scene', 'layout' => 'solo', 'slots' => [['source' => 'local', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]], 'overlays' => []],
    ];

    Livewire::actingAs($user)
        ->test('studio.studio', ['session' => $session])
        ->call('saveScenes', $newScenes);

    $session->refresh();
    expect($session->scenes)->toHaveCount(1);
    expect($session->scenes[0]['name'])->toBe('Custom Scene');
});

it('initializes with default scenes', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.studio');

    $session = StreamSession::where('user_id', $user->id)->latest()->first();
    expect($session->scenes)->toHaveCount(4);
});

it('switches to walkaround scene index 3 in walkaround mode', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->withQueryParams(['preset' => 'walkaround'])
        ->test('studio.studio');

    $session = StreamSession::where('user_id', $user->id)->latest()->first();
    expect($session->current_scene_index)->toBe(3);
});

it('persists scene index to database', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('studio.studio', ['session' => $session])
        ->call('switchScene', 2);

    expect(StreamSession::find($session->id)->current_scene_index)->toBe(2);
});
