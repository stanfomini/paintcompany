<?php

use App\Models\StreamSession;
use App\Models\User;

it('creates a stream session with default values', function () {
    $user = User::factory()->internal()->create();

    $session = StreamSession::create([
        'user_id' => $user->id,
    ]);

    $session->refresh();

    expect($session->room_id)->toStartWith('studio-');
    expect($session->status)->toBe('preparing');
    expect($session->scenes)->toBeArray();
    expect($session->scenes)->toHaveCount(4);
    expect($session->current_scene_index)->toBe(0);
});

it('auto-generates room id on creation', function () {
    $session = StreamSession::factory()->create();

    expect($session->room_id)->toStartWith('studio-');
    expect(strlen($session->room_id))->toBeGreaterThan(10);
});

it('enforces unique room ids', function () {
    $session1 = StreamSession::factory()->create(['room_id' => 'studio-unique123']);

    expect(fn () => StreamSession::factory()->create(['room_id' => 'studio-unique123']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('belongs to a user', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->for($user)->create();

    expect($session->user->id)->toBe($user->id);
});

it('casts json columns to arrays', function () {
    $session = StreamSession::factory()->create([
        'scenes' => [['name' => 'Test', 'layout' => 'solo']],
        'destinations' => [['id' => 'yt-1', 'platform' => 'youtube']],
        'overlays' => [['id' => 'o-1', 'type' => 'text']],
    ]);

    $session->refresh();

    expect($session->scenes)->toBeArray();
    expect($session->destinations)->toBeArray();
    expect($session->overlays)->toBeArray();
});

it('casts datetime columns', function () {
    $session = StreamSession::factory()->live()->create();
    $session->refresh();

    expect($session->started_at)->toBeInstanceOf(\Carbon\CarbonInterface::class);
});

it('has status helper methods', function () {
    $preparing = StreamSession::factory()->create(['status' => 'preparing']);
    $live = StreamSession::factory()->live()->create();
    $ended = StreamSession::factory()->ended()->create();

    expect($preparing->isPreparing())->toBeTrue();
    expect($preparing->isLive())->toBeFalse();
    expect($preparing->isEnded())->toBeFalse();

    expect($live->isLive())->toBeTrue();
    expect($live->isPreparing())->toBeFalse();

    expect($ended->isEnded())->toBeTrue();
    expect($ended->isLive())->toBeFalse();
});

it('generates guest invite link', function () {
    $session = StreamSession::factory()->create();

    $link = $session->generateGuestLink();

    expect($link)->toContain('/studio/join/');
    expect($link)->toContain($session->room_id);
    expect($link)->toContain('signature=');
});

it('provides default scene presets', function () {
    $scenes = StreamSession::defaultScenes();

    expect($scenes)->toHaveCount(4);
    expect($scenes[0]['name'])->toBe('Solo');
    expect($scenes[1]['name'])->toBe('Split Screen');
    expect($scenes[2]['name'])->toBe('Picture-in-Picture');
    expect($scenes[3]['name'])->toBe('Walkaround');
});

it('includes solo scene with full-screen local source', function () {
    $scenes = StreamSession::defaultScenes();
    $solo = $scenes[0];

    expect($solo['layout'])->toBe('solo');
    expect($solo['slots'])->toHaveCount(1);
    expect($solo['slots'][0]['source'])->toBe('local');
    expect($solo['slots'][0]['w'])->toBe(1);
    expect($solo['slots'][0]['h'])->toBe(1);
});

it('uses factory live state correctly', function () {
    $session = StreamSession::factory()->live()->create();

    expect($session->status)->toBe('live');
    expect($session->started_at)->not->toBeNull();
    expect($session->ended_at)->toBeNull();
});

it('uses factory ended state correctly', function () {
    $session = StreamSession::factory()->ended()->create();

    expect($session->status)->toBe('ended');
    expect($session->started_at)->not->toBeNull();
    expect($session->ended_at)->not->toBeNull();
    expect($session->duration_seconds)->toBeGreaterThan(0);
});

it('uses factory withDestinations state correctly', function () {
    $session = StreamSession::factory()->withDestinations()->create();

    expect($session->destinations)->toBeArray();
    expect($session->destinations)->toHaveCount(1);
    expect($session->destinations[0]['platform'])->toBe('youtube');
    expect($session->destinations[0]['rtmp_url'])->toContain('rtmp://');
});

it('cascades delete when user is deleted', function () {
    $user = User::factory()->internal()->create();
    $session = StreamSession::factory()->for($user)->create();
    $sessionId = $session->id;

    $user->delete();

    $this->assertDatabaseMissing('stream_sessions', ['id' => $sessionId]);
});
