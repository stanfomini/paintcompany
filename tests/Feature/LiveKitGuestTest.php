<?php

use App\Models\LiveEvent;
use App\Models\LiveEventGuest;
use App\Models\User;
use App\Services\LiveKitService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guest token has canPublish false by default', function () {
    config([
        'services.livekit.url' => 'https://livekit.test',
        'services.livekit.api_key' => 'test-api-key',
        'services.livekit.api_secret' => 'test-secret-key-that-is-long-enough',
    ]);

    $event = LiveEvent::factory()->live()->create();
    $guest = LiveEventGuest::factory()->create([
        'live_event_id' => $event->id,
        'livekit_identity' => 'guest-test-1',
    ]);

    $service = app(LiveKitService::class);
    $jwt = $service->generateGuestToken($event, $guest);

    // Decode the JWT payload to verify canPublish is false
    $parts = explode('.', $jwt);
    $payload = json_decode(base64_decode($parts[1]), true);
    $videoGrant = $payload['video'] ?? [];

    expect($videoGrant['roomJoin'])->toBeTrue()
        ->and($videoGrant['room'])->toBe($event->livekit_room_name)
        ->and($videoGrant['canPublish'])->toBeFalse()
        ->and($videoGrant['canSubscribe'])->toBeTrue();
});

test('host token has canPublish true', function () {
    config([
        'services.livekit.url' => 'https://livekit.test',
        'services.livekit.api_key' => 'test-api-key',
        'services.livekit.api_secret' => 'test-secret-key-that-is-long-enough',
    ]);

    $event = LiveEvent::factory()->live()->create();
    $user = User::factory()->internal()->create();

    $service = app(LiveKitService::class);
    $jwt = $service->generateHostToken($event, $user);

    $parts = explode('.', $jwt);
    $payload = json_decode(base64_decode($parts[1]), true);
    $videoGrant = $payload['video'] ?? [];

    expect($videoGrant['roomJoin'])->toBeTrue()
        ->and($videoGrant['room'])->toBe($event->livekit_room_name)
        ->and($videoGrant['canPublish'])->toBeTrue()
        ->and($videoGrant['canSubscribe'])->toBeTrue();
});

test('permission management methods exist on LiveKitService', function () {
    $service = app(LiveKitService::class);

    expect(method_exists($service, 'updateParticipantPermissions'))->toBeTrue()
        ->and(method_exists($service, 'grantPublish'))->toBeTrue()
        ->and(method_exists($service, 'revokePublish'))->toBeTrue();
});
