<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use App\Models\VideoCall;

it('generates a personal_room_slug for internal users on creation', function () {
    $user = User::factory()->internal()->create(['name' => 'Matt Stanford']);

    expect($user->personal_room_slug)->not->toBeNull()
        ->and($user->personal_room_slug)->toStartWith('matt-stanford-');
});

it('does not generate personal_room_slug for non-internal users', function () {
    $customer = User::factory()->customer()->create();
    $agent = User::factory()->agent()->create();

    expect($customer->personal_room_slug)->toBeNull()
        ->and($agent->personal_room_slug)->toBeNull();
});

it('generates unique slugs for users with the same name', function () {
    $user1 = User::factory()->internal()->create(['name' => 'John Smith']);
    $user2 = User::factory()->internal()->create(['name' => 'John Smith']);

    expect($user1->personal_room_slug)->not->toBe($user2->personal_room_slug);
});

it('produces a consistent room ID for the same estimator and deal', function () {
    $user = User::factory()->internal()->create();

    $roomId1 = $user->videoCallRoomId(42);
    $roomId2 = $user->videoCallRoomId(42);

    expect($roomId1)->toBe($roomId2)
        ->and($roomId1)->toContain($user->personal_room_slug)
        ->and($roomId1)->toEndWith('-deal-42');
});

it('produces different room IDs for different deals', function () {
    $user = User::factory()->internal()->create();

    $roomId1 = $user->videoCallRoomId(1);
    $roomId2 = $user->videoCallRoomId(2);

    expect($roomId1)->not->toBe($roomId2);
});

it('getOrCreatePersonalRoomSlug creates slug on demand for existing users', function () {
    // Create a non-internal user, then change type to internal without slug
    $user = User::factory()->customer()->create();
    expect($user->personal_room_slug)->toBeNull();

    // Manually update type (simulating an upgrade)
    $user->update(['type' => 'internal']);

    $slug = $user->getOrCreatePersonalRoomSlug();
    expect($slug)->not->toBeNull()
        ->and($user->fresh()->personal_room_slug)->toBe($slug);
});

it('returns userId as the presence member ID in channel auth', function () {
    $user = User::factory()->internal()->create();
    $videoCall = VideoCall::factory()->create(['room_id' => 'test-room']);

    // Simulate the channel auth request
    $response = $this->actingAs($user)
        ->post('/broadcasting/auth', [
            'socket_id' => '12345.67890',
            'channel_name' => 'presence-video-call.test-room',
        ]);

    $response->assertSuccessful();
    $data = json_decode($response->getContent(), true);

    // The channel_data should contain the user's ID as the presence member ID
    if (isset($data['channel_data'])) {
        $channelData = json_decode($data['channel_data'], true);
        expect($channelData['user_id'])->toBe($user->id);
    }
});

it('reuses existing video call with same room_id instead of creating new one', function () {
    $estimator = User::factory()->internal()->withFullAccess()->create();
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create([
        'customer_id' => $customer->id,
        'assigned_to' => $estimator->id,
    ]);

    $roomId = $estimator->videoCallRoomId($deal->id);

    // Create an ended video call with this room ID
    $oldCall = VideoCall::factory()->create([
        'room_id' => $roomId,
        'callable_type' => Deal::class,
        'callable_id' => $deal->id,
        'initiated_by' => $estimator->id,
        'status' => 'ended',
        'ended_at' => now()->subHour(),
    ]);

    // Starting a new call for the same deal should reuse the room_id
    \Livewire\Livewire::actingAs($estimator)
        ->test('customers.show', ['customer' => $customer])
        ->call('startVideoCall');

    // Should still be only one VideoCall with this room_id
    expect(VideoCall::where('room_id', $roomId)->count())->toBe(1);

    // The existing call should now be active
    $oldCall->refresh();
    expect($oldCall->status)->toBe('active')
        ->and($oldCall->ended_at)->toBeNull();
});
