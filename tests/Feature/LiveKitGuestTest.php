<?php

use Agence104\LiveKit\RoomServiceClient;
use App\Models\LiveEvent;
use App\Models\LiveEventGuest;
use App\Services\LiveKitService;
use Livekit\ParticipantPermission;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ============================================================
// LiveKitService Permission Management
// ============================================================

test('updateParticipantPermissions calls room client with correct permission object', function () {
    $mockRoomClient = Mockery::mock(RoomServiceClient::class);
    $mockRoomClient->shouldReceive('updateParticipant')
        ->once()
        ->withArgs(function (string $room, string $identity, ?string $metadata, ?ParticipantPermission $permission) {
            return $room === 'test-room'
                && $identity === 'guest-abc123'
                && $permission->getCanPublish() === true
                && $permission->getCanSubscribe() === true
                && $permission->getCanPublishData() === true;
        });

    $service = Mockery::mock(LiveKitService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('getRoomClient')->andReturn($mockRoomClient);

    $service->updateParticipantPermissions('test-room', 'guest-abc123', canPublish: true);
});

test('updateParticipantPermissions can revoke publish', function () {
    $mockRoomClient = Mockery::mock(RoomServiceClient::class);
    $mockRoomClient->shouldReceive('updateParticipant')
        ->once()
        ->withArgs(function (string $room, string $identity, ?string $metadata, ?ParticipantPermission $permission) {
            return $permission->getCanPublish() === false
                && $permission->getCanSubscribe() === true
                && $permission->getCanPublishData() === false;
        });

    $service = Mockery::mock(LiveKitService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('getRoomClient')->andReturn($mockRoomClient);

    $service->updateParticipantPermissions('test-room', 'guest-abc123', canPublish: false);
});

test('updateParticipantPermissions can disable subscribe', function () {
    $mockRoomClient = Mockery::mock(RoomServiceClient::class);
    $mockRoomClient->shouldReceive('updateParticipant')
        ->once()
        ->withArgs(function (string $room, string $identity, ?string $metadata, ?ParticipantPermission $permission) {
            return $permission->getCanPublish() === true
                && $permission->getCanSubscribe() === false;
        });

    $service = Mockery::mock(LiveKitService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('getRoomClient')->andReturn($mockRoomClient);

    $service->updateParticipantPermissions('test-room', 'guest-abc123', canPublish: true, canSubscribe: false);
});

test('grantPublish calls updateParticipantPermissions with canPublish true', function () {
    $event = LiveEvent::factory()->create(['livekit_room_name' => 'event-room-42']);
    $guest = LiveEventGuest::factory()->create([
        'live_event_id' => $event->id,
        'livekit_identity' => 'guest-xyz789',
    ]);

    $mockRoomClient = Mockery::mock(RoomServiceClient::class);
    $mockRoomClient->shouldReceive('updateParticipant')
        ->once()
        ->withArgs(function (string $room, string $identity, ?string $metadata, ?ParticipantPermission $permission) {
            return $room === 'event-room-42'
                && $identity === 'guest-xyz789'
                && $permission->getCanPublish() === true;
        });

    $service = Mockery::mock(LiveKitService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('getRoomClient')->andReturn($mockRoomClient);

    $service->grantPublish($event, $guest);
});

test('revokePublish calls updateParticipantPermissions with canPublish false', function () {
    $event = LiveEvent::factory()->create(['livekit_room_name' => 'event-room-42']);
    $guest = LiveEventGuest::factory()->create([
        'live_event_id' => $event->id,
        'livekit_identity' => 'guest-xyz789',
    ]);

    $mockRoomClient = Mockery::mock(RoomServiceClient::class);
    $mockRoomClient->shouldReceive('updateParticipant')
        ->once()
        ->withArgs(function (string $room, string $identity, ?string $metadata, ?ParticipantPermission $permission) {
            return $room === 'event-room-42'
                && $identity === 'guest-xyz789'
                && $permission->getCanPublish() === false;
        });

    $service = Mockery::mock(LiveKitService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('getRoomClient')->andReturn($mockRoomClient);

    $service->revokePublish($event, $guest);
});

test('grantPublish uses guest getLivekitIdentity for identity', function () {
    $event = LiveEvent::factory()->create();
    $guest = LiveEventGuest::factory()->create([
        'live_event_id' => $event->id,
    ]);

    $expectedIdentity = $guest->getLivekitIdentity();

    $mockRoomClient = Mockery::mock(RoomServiceClient::class);
    $mockRoomClient->shouldReceive('updateParticipant')
        ->once()
        ->withArgs(function (string $room, string $identity) use ($expectedIdentity) {
            return $identity === $expectedIdentity;
        });

    $service = Mockery::mock(LiveKitService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('getRoomClient')->andReturn($mockRoomClient);

    $service->grantPublish($event, $guest);
});

test('updateParticipantPermissions logs the permission change', function () {
    $mockRoomClient = Mockery::mock(RoomServiceClient::class);
    $mockRoomClient->shouldReceive('updateParticipant')->once();

    $service = Mockery::mock(LiveKitService::class)->makePartial();
    $service->shouldAllowMockingProtectedMethods();
    $service->shouldReceive('getRoomClient')->andReturn($mockRoomClient);

    Illuminate\Support\Facades\Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'LiveKit: Updated participant permissions'
                && $context['room'] === 'test-room'
                && $context['identity'] === 'guest-abc'
                && $context['canPublish'] === true;
        });

    $service->updateParticipantPermissions('test-room', 'guest-abc', canPublish: true);
});
