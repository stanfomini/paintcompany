<?php

use App\Models\PushSubscription;
use App\Models\User;

it('can subscribe to push notifications', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/push/subscribe', [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-123',
        'keys' => [
            'p256dh' => 'test-public-key-abc123',
            'auth' => 'test-auth-token-xyz789',
        ],
    ]);

    $response->assertStatus(201);
    expect($user->pushSubscriptions)->toHaveCount(1);
    expect($user->pushSubscriptions->first()->endpoint)->toBe('https://fcm.googleapis.com/fcm/send/test-endpoint-123');
});

it('is idempotent on duplicate subscribe', function () {
    $user = User::factory()->create();
    $payload = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-123',
        'keys' => ['p256dh' => 'key1', 'auth' => 'auth1'],
    ];

    $this->actingAs($user)->postJson('/push/subscribe', $payload)->assertStatus(201);
    $this->actingAs($user)->postJson('/push/subscribe', $payload)->assertStatus(201);

    expect(PushSubscription::where('user_id', $user->id)->count())->toBe(1);
});

it('can unsubscribe from push notifications', function () {
    $user = User::factory()->create();
    PushSubscription::create([
        'user_id' => $user->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-123',
        'public_key' => 'key1',
        'auth_token' => 'auth1',
    ]);

    $response = $this->actingAs($user)->deleteJson('/push/unsubscribe', [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-123',
    ]);

    $response->assertStatus(204);
    expect(PushSubscription::where('user_id', $user->id)->count())->toBe(0);
});

it('requires authentication for subscribe', function () {
    $this->postJson('/push/subscribe', [
        'endpoint' => 'https://example.com/push',
        'keys' => ['p256dh' => 'key', 'auth' => 'auth'],
    ])->assertUnauthorized();
});

it('validates required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/push/subscribe', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
});
