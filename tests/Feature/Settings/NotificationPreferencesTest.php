<?php

use App\Models\PushSubscription;
use App\Models\User;
use Livewire\Livewire;

it('renders for authenticated users', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('notification-preferences.edit'))
        ->assertOk()
        ->assertSee('Notification Preferences');
});

it('requires authentication', function () {
    $this->get(route('notification-preferences.edit'))
        ->assertRedirect();
});

it('shows the notifications nav link in settings', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertSee('Notifications');
});

it('shows all category labels', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.notification-preferences')
        ->assertSee('Estimates')
        ->assertSee('Chat Messages')
        ->assertSee('Jobs & Scheduling')
        ->assertSee('Tasks')
        ->assertSee('Payments')
        ->assertSee('Documents')
        ->assertSee('General');
});

it('loads default preferences when user has none saved', function () {
    $user = User::factory()->internal()->create(['notification_preferences' => null]);

    $component = Livewire::actingAs($user)
        ->test('pages::settings.notification-preferences');

    foreach (User::defaultNotificationPreferences() as $category => $channels) {
        $component->assertSet("preferences.{$category}.email", true);
        $component->assertSet("preferences.{$category}.push", true);
    }
});

it('loads saved preferences merged with defaults', function () {
    $user = User::factory()->internal()->create([
        'notification_preferences' => [
            'estimates' => ['email' => false, 'push' => true],
            'chat' => ['email' => true, 'push' => false],
        ],
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.notification-preferences')
        ->assertSet('preferences.estimates.email', false)
        ->assertSet('preferences.estimates.push', true)
        ->assertSet('preferences.chat.email', true)
        ->assertSet('preferences.chat.push', false)
        ->assertSet('preferences.jobs.email', true)
        ->assertSet('preferences.jobs.push', true);
});

it('saves preferences correctly', function () {
    $user = User::factory()->internal()->create(['notification_preferences' => null]);

    Livewire::actingAs($user)
        ->test('pages::settings.notification-preferences')
        ->set('preferences.estimates.email', false)
        ->set('preferences.chat.push', false)
        ->call('save');

    $user->refresh();

    expect($user->notification_preferences['estimates']['email'])->toBeFalse();
    expect($user->notification_preferences['chat']['push'])->toBeFalse();
    expect($user->notification_preferences['jobs']['email'])->toBeTrue();
});

it('shows enable push button when no subscriptions exist', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.notification-preferences')
        ->assertSee('Push Notifications')
        ->assertSee('Get notified even when the app is closed.');
});

it('does not show disabled push message when subscriptions exist', function () {
    $user = User::factory()->internal()->create();

    PushSubscription::create([
        'user_id' => $user->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
        'public_key' => 'test-public-key',
        'auth_token' => 'test-auth-token',
        'content_encoding' => 'aes128gcm',
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.notification-preferences')
        ->assertDontSee('Install the app and enable notifications to use push.');
});

it('lists push devices', function () {
    $user = User::factory()->internal()->create();

    PushSubscription::create([
        'user_id' => $user->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
        'public_key' => 'test-public-key',
        'auth_token' => 'test-auth-token',
        'content_encoding' => 'aes128gcm',
        'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)',
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.notification-preferences')
        ->assertSee('Push Devices')
        ->assertSee('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)');
});

it('can remove a push device', function () {
    $user = User::factory()->internal()->create();

    $subscription = PushSubscription::create([
        'user_id' => $user->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/removable',
        'public_key' => 'test-public-key',
        'auth_token' => 'test-auth-token',
        'content_encoding' => 'aes128gcm',
        'user_agent' => 'Test Browser Agent',
    ]);

    expect($user->pushSubscriptions()->count())->toBe(1);

    Livewire::actingAs($user)
        ->test('pages::settings.notification-preferences')
        ->assertSee('Test Browser Agent')
        ->call('removeDevice', $subscription->id)
        ->assertDontSee('Test Browser Agent');

    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('only removes devices belonging to the authenticated user', function () {
    $user = User::factory()->internal()->create();
    $otherUser = User::factory()->internal()->create();

    $otherSubscription = PushSubscription::create([
        'user_id' => $otherUser->id,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/other',
        'public_key' => 'other-key',
        'auth_token' => 'other-token',
        'content_encoding' => 'aes128gcm',
        'user_agent' => 'Other Browser',
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.notification-preferences')
        ->call('removeDevice', $otherSubscription->id);

    expect($otherUser->pushSubscriptions()->count())->toBe(1);
});

it('is accessible to all user types', function (string $type) {
    $user = User::factory()->create(['type' => $type]);

    $this->actingAs($user, $type === 'internal' ? 'web' : $type)
        ->get(route('notification-preferences.edit'))
        ->assertOk();
})->with(['internal', 'agent', 'customer', 'crew']);

it('shows saved confirmation on save', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.notification-preferences')
        ->assertSet('saved', false)
        ->call('save')
        ->assertSet('saved', true)
        ->assertSee('Saved.');
});
