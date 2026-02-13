<?php

use App\Models\StreamSession;
use App\Models\User;
use Livewire\Livewire;

it('can generate a guest invite link', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->for($user)->create();

    $link = $session->generateGuestLink();

    expect($link)->toContain('/studio/join/');
    expect($link)->toContain($session->room_id);

    // Verify the Livewire method delegates to the model
    Livewire::actingAs($user)
        ->test('studio.studio', ['session' => $session])
        ->call('generateGuestLink');
});

it('allows guest access with valid signed url', function () {
    $session = StreamSession::factory()->create();
    $link = $session->generateGuestLink();

    // Extract the path and query from the signed URL
    $parsed = parse_url($link);
    $path = $parsed['path'].'?'.$parsed['query'];

    $this->get($path)
        ->assertOk();
});

it('denies guest access with invalid signature', function () {
    $session = StreamSession::factory()->create();

    $this->get("/studio/join/{$session->room_id}")
        ->assertForbidden();
});

it('shows 410 for ended sessions', function () {
    $session = StreamSession::factory()->ended()->create();
    $link = $session->generateGuestLink();

    $parsed = parse_url($link);
    $path = $parsed['path'].'?'.$parsed['query'];

    $this->get($path)
        ->assertGone();
});

it('requires a name to join session', function () {
    $session = StreamSession::factory()->create();

    Livewire::test('studio.guest-view', ['token' => $session->room_id])
        ->set('guestName', '')
        ->call('joinSession')
        ->assertHasErrors(['guestName']);
});

it('can join session with valid name', function () {
    $session = StreamSession::factory()->create();

    Livewire::test('studio.guest-view', ['token' => $session->room_id])
        ->set('guestName', 'John Doe')
        ->call('joinSession')
        ->assertSet('joined', true)
        ->assertDispatched('guest-joined');
});

it('shows session title and host name on guest page', function () {
    $user = User::factory()->internal()->create(['name' => 'Test Host']);
    $session = StreamSession::factory()->for($user)->create(['title' => 'My Stream']);

    Livewire::test('studio.guest-view', ['token' => $session->room_id])
        ->assertSee('My Stream')
        ->assertSee('Test Host');
});
