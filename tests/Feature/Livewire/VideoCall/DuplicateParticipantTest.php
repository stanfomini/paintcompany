<?php

use App\Models\User;
use App\Models\VideoCall;

function createRoom(string $roomId): VideoCall
{
    return VideoCall::factory()->active()->create(['room_id' => $roomId]);
}

it('renders the video call page for an authenticated user', function () {
    $user = User::factory()->internal()->create();
    createRoom('test');

    $this->actingAs($user)
        ->get(route('video-call.test', 'test'))
        ->assertSuccessful();
});

it('renders for guests without auth (public page)', function () {
    createRoom('public-room');

    $this->get(route('video-call.test', 'public-room'))
        ->assertSuccessful()
        ->assertSee('isGuest: true', false);
});

it('returns 404 for non-existent rooms', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('video-call.test', 'non-existent-room'))
        ->assertNotFound();
});

it('sets myPresenceId from odentity in init', function () {
    $user = User::factory()->internal()->create();
    createRoom('test');

    $content = $this->actingAs($user)
        ->get(route('video-call.test', 'test'))
        ->getContent();

    expect(str_contains($content, 'this.myPresenceId = this.odentity'))->toBeTrue();
});

it('uses myPresenceId when filtering lobby peers', function () {
    $user = User::factory()->internal()->create();
    createRoom('test');

    $this->actingAs($user)
        ->get(route('video-call.test', 'test'))
        ->assertSuccessful()
        ->assertSee('String(u.id) !== this.myPresenceId', false);
});

it('uses peerId in whisper payloads', function () {
    $user = User::factory()->internal()->create();
    createRoom('test');

    $content = $this->actingAs($user)
        ->get(route('video-call.test', 'test'))
        ->getContent();

    expect(str_contains($content, 'peerId: this.myPresenceId'))->toBeTrue();
});

it('auto-removes peers stuck in connecting state after timeout', function () {
    $user = User::factory()->internal()->create();
    createRoom('test');

    $this->actingAs($user)
        ->get(route('video-call.test', 'test'))
        ->assertSuccessful()
        ->assertSee('timed out (stuck connecting)', false);
});

it('re-offers to peers when they announce their role while we are in the call', function () {
    $user = User::factory()->internal()->create();
    createRoom('test');

    $this->actingAs($user)
        ->get(route('video-call.test', 'test'))
        ->assertSuccessful()
        ->assertSee('they just joined the call', false);
});

it('shows guest name input for unauthenticated users', function () {
    createRoom('guest-room');

    $this->get(route('video-call.test', 'guest-room'))
        ->assertSuccessful()
        ->assertSee('Enter your name to join', false);
});

it('creates Echo for guests with guest auth endpoint', function () {
    createRoom('guest-room');

    $content = $this->get(route('video-call.test', 'guest-room'))->getContent();

    expect(str_contains($content, 'video-call-auth'))->toBeTrue();
});
