<?php

use App\Models\PushSubscription;
use App\Models\User;

it('returns default notification preferences when none are set', function () {
    $user = User::factory()->create(['notification_preferences' => null]);

    expect($user->getNotificationPreference('estimates', 'email'))->toBeTrue();
    expect($user->getNotificationPreference('estimates', 'push'))->toBeTrue();
    expect($user->getNotificationPreference('chat', 'email'))->toBeTrue();
    expect($user->getNotificationPreference('jobs', 'push'))->toBeTrue();
});

it('respects user overrides for notification preferences', function () {
    $user = User::factory()->create([
        'notification_preferences' => [
            'estimates' => ['email' => false, 'push' => true],
            'chat' => ['email' => true, 'push' => false],
        ],
    ]);

    expect($user->getNotificationPreference('estimates', 'email'))->toBeFalse();
    expect($user->getNotificationPreference('estimates', 'push'))->toBeTrue();
    expect($user->getNotificationPreference('chat', 'email'))->toBeTrue();
    expect($user->getNotificationPreference('chat', 'push'))->toBeFalse();
    // Falls back to default for unset categories
    expect($user->getNotificationPreference('jobs', 'email'))->toBeTrue();
});

it('wantsEmail returns correct value based on preferences', function () {
    $user = User::factory()->create([
        'notification_preferences' => [
            'estimates' => ['email' => false, 'push' => true],
        ],
    ]);

    expect($user->wantsEmail('estimates'))->toBeFalse();
    expect($user->wantsEmail('chat'))->toBeTrue(); // default
});

it('wantsPush returns false when no push subscriptions exist', function () {
    $user = User::factory()->create(['notification_preferences' => null]);

    expect($user->wantsPush('estimates'))->toBeFalse();
});

it('wantsPush returns true when push is enabled and subscription exists', function () {
    $user = User::factory()->create(['notification_preferences' => null]);

    PushSubscription::create([
        'user_id' => $user->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
        'public_key' => 'test-public-key',
        'auth_token' => 'test-auth-token',
        'content_encoding' => 'aes128gcm',
    ]);

    expect($user->wantsPush('estimates'))->toBeTrue();
});

it('wantsPush returns false when push is disabled even with subscription', function () {
    $user = User::factory()->create([
        'notification_preferences' => [
            'estimates' => ['email' => true, 'push' => false],
        ],
    ]);

    PushSubscription::create([
        'user_id' => $user->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
        'public_key' => 'test-public-key',
        'auth_token' => 'test-auth-token',
        'content_encoding' => 'aes128gcm',
    ]);

    expect($user->wantsPush('estimates'))->toBeFalse();
});

it('casts notification_preferences as array', function () {
    $prefs = ['estimates' => ['email' => true, 'push' => false]];
    $user = User::factory()->create(['notification_preferences' => $prefs]);
    $user->refresh();

    expect($user->notification_preferences)->toBeArray();
    expect($user->notification_preferences['estimates']['push'])->toBeFalse();
});

it('returns all default categories', function () {
    $defaults = User::defaultNotificationPreferences();

    expect($defaults)->toHaveKeys([
        'estimates', 'chat', 'messenger', 'email_messages', 'jobs',
        'tasks', 'payments', 'phone_calls', 'documents', 'general',
    ]);

    foreach ($defaults as $category => $channels) {
        expect($channels)->toHaveKeys(['email', 'push']);
    }

    // phone_calls defaults to email off, push on
    expect($defaults['phone_calls']['email'])->toBeFalse();
    expect($defaults['phone_calls']['push'])->toBeTrue();
});
