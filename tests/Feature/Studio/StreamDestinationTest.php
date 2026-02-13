<?php

use App\Models\StreamSession;
use App\Models\User;
use Livewire\Livewire;

it('can add a destination', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('studio.studio', ['session' => $session])
        ->set('destPlatform', 'youtube')
        ->set('destRtmpUrl', 'rtmp://a.rtmp.youtube.com/live2')
        ->set('destStreamKey', 'test-stream-key-123')
        ->call('addDestination')
        ->assertDispatched('destinations-updated');

    $session->refresh();
    expect($session->destinations)->toHaveCount(1);
    expect($session->destinations[0]['platform'])->toBe('youtube');
    expect($session->destinations[0]['stream_key'])->toBe('test-stream-key-123');
});

it('requires stream key to add destination', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('studio.studio', ['session' => $session])
        ->set('destPlatform', 'youtube')
        ->set('destRtmpUrl', 'rtmp://a.rtmp.youtube.com/live2')
        ->set('destStreamKey', '')
        ->call('addDestination')
        ->assertHasErrors(['destStreamKey']);
});

it('can remove a destination', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->withDestinations()->for($user)->create();
    $destId = $session->destinations[0]['id'];

    Livewire::actingAs($user)
        ->test('studio.studio', ['session' => $session])
        ->call('removeDestination', $destId)
        ->assertDispatched('destinations-updated');

    $session->refresh();
    expect($session->destinations)->toHaveCount(0);
});

it('can toggle a destination on and off', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->withDestinations()->for($user)->create();
    $destId = $session->destinations[0]['id'];

    expect($session->destinations[0]['enabled'])->toBeTrue();

    Livewire::actingAs($user)
        ->test('studio.studio', ['session' => $session])
        ->call('toggleDestination', $destId);

    $session->refresh();
    expect($session->destinations[0]['enabled'])->toBeFalse();
});

it('sets default rtmp url based on platform', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('studio.studio', ['session' => $session])
        ->set('destPlatform', 'tiktok')
        ->assertSet('destRtmpUrl', 'rtmp://push.rtmp.tiktok.com/live/');
});

it('can go live with destinations', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->withDestinations()->for($user)->create();

    Livewire::actingAs($user)
        ->test('studio.studio', ['session' => $session])
        ->call('goLive')
        ->assertDispatched('go-live');

    $session->refresh();
    expect($session->status)->toBe('live');
    expect($session->started_at)->not->toBeNull();
});

it('cannot go live without destinations', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('studio.studio', ['session' => $session])
        ->call('goLive')
        ->assertHasErrors(['destinations']);

    $session->refresh();
    expect($session->status)->toBe('preparing');
});

it('can stop stream', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->withDestinations()->live()->for($user)->create();

    Livewire::actingAs($user)
        ->test('studio.studio', ['session' => $session])
        ->call('stopStream')
        ->assertDispatched('stream-stopped');

    $session->refresh();
    expect($session->status)->toBe('ended');
    expect($session->ended_at)->not->toBeNull();
    expect($session->duration_seconds)->not->toBeNull();
});

it('clears stream key after adding destination', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->for($user)->create();

    Livewire::actingAs($user)
        ->test('studio.studio', ['session' => $session])
        ->set('destPlatform', 'youtube')
        ->set('destRtmpUrl', 'rtmp://a.rtmp.youtube.com/live2')
        ->set('destStreamKey', 'secret-key')
        ->call('addDestination')
        ->assertSet('destStreamKey', '');
});

it('can add multiple destinations', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->for($user)->create();

    $component = Livewire::actingAs($user)
        ->test('studio.studio', ['session' => $session]);

    // Add YouTube
    $component
        ->set('destPlatform', 'youtube')
        ->set('destRtmpUrl', 'rtmp://a.rtmp.youtube.com/live2')
        ->set('destStreamKey', 'yt-key')
        ->call('addDestination');

    // Add TikTok
    $component
        ->set('destPlatform', 'tiktok')
        ->set('destRtmpUrl', 'rtmp://push.rtmp.tiktok.com/live/')
        ->set('destStreamKey', 'tt-key')
        ->call('addDestination');

    $session->refresh();
    expect($session->destinations)->toHaveCount(2);
});
