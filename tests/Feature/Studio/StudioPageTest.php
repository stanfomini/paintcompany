<?php

use App\Models\StreamSession;
use App\Models\User;
use Livewire\Livewire;

it('requires authentication to access studio', function () {
    $this->get('/studio')
        ->assertRedirect('/login');
});

it('allows internal users to access studio', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get('/studio')
        ->assertOk();
});

it('allows agent users to access studio', function () {
    $user = User::factory()->agent()->create();

    $this->actingAs($user, 'agent')
        ->get('/studio')
        ->assertOk();
});

it('denies customer users access to studio', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'customer')
        ->get('/studio')
        ->assertRedirect(route('customer.dashboard'))
        ->assertSessionHas('warning');
});

it('creates a new session when accessing studio live without session id', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get('/studio/live');

    $this->assertDatabaseHas('stream_sessions', [
        'user_id' => $user->id,
        'status' => 'preparing',
    ]);
});

it('loads existing session when session id is provided', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->for($user)->create();

    $this->actingAs($user)
        ->get("/studio/live/{$session->id}")
        ->assertOk();
});

it('denies access to another users session', function () {
    $owner = User::factory()->internal()->create();
    $other = User::factory()->internal()->create();
    $session = StreamSession::factory()->for($owner)->create();

    $this->actingAs($other)
        ->get("/studio/live/{$session->id}")
        ->assertForbidden();
});

it('sets walkaround mode from query parameter', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->withQueryParams(['preset' => 'walkaround'])
        ->test('studio.studio')
        ->assertSet('walkaroundMode', true);
});

it('defaults to non-walkaround mode', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.studio')
        ->assertSet('walkaroundMode', false);
});

it('sets walkaround scene index to 3 when preset is walkaround', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->withQueryParams(['preset' => 'walkaround'])
        ->test('studio.studio');

    $session = StreamSession::where('user_id', $user->id)->latest()->first();
    expect($session->current_scene_index)->toBe(3);
});
