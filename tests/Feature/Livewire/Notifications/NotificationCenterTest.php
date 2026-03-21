<?php

use App\Models\Estimate;
use App\Models\User;
use App\Notifications\EstimateAccepted;
use Livewire\Livewire;

it('renders notification bell for internal users', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('notifications.notification-center')
        ->assertStatus(200)
        ->call('loadPanel')
        ->assertSee(__('Notifications'));
});

it('denies access to non-internal users', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'customer');

    Livewire::test('notifications.notification-center')
        ->assertForbidden();
});

it('shows unread count when there are unread notifications', function () {
    $user = User::factory()->internal()->create();

    $user->notify(new EstimateAccepted(
        Estimate::factory()->create()
    ));

    $this->actingAs($user);

    Livewire::test('notifications.notification-center')
        ->call('loadPanel')
        ->assertSee('1');
});

it('hides count badge when no unread notifications', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    $component = Livewire::test('notifications.notification-center');

    expect($component->get('unreadCount'))->toBe(0);
});

it('can mark all notifications as read', function () {
    $user = User::factory()->internal()->create();

    $user->notify(new EstimateAccepted(
        Estimate::factory()->create()
    ));
    $user->notify(new EstimateAccepted(
        Estimate::factory()->create()
    ));

    expect($user->unreadNotifications()->count())->toBe(2);

    $this->actingAs($user);

    Livewire::test('notifications.notification-center')
        ->call('loadPanel')
        ->call('markAllAsRead');

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('can mark individual notification as read via button', function () {
    $user = User::factory()->internal()->create();

    $user->notify(new EstimateAccepted(
        Estimate::factory()->create()
    ));

    $notification = $user->unreadNotifications()->first();

    $this->actingAs($user);

    Livewire::test('notifications.notification-center')
        ->call('markAsRead', $notification->id);

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('can mark as read and navigate', function () {
    $user = User::factory()->internal()->create();

    $user->notify(new EstimateAccepted(
        Estimate::factory()->create()
    ));

    $notification = $user->unreadNotifications()->first();

    $this->actingAs($user);

    Livewire::test('notifications.notification-center')
        ->call('markAsReadAndNavigate', $notification->id, null);

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('shows unread notifications when panel is loaded', function () {
    $user = User::factory()->internal()->create();

    $estimate = Estimate::factory()->create();
    $user->notify(new EstimateAccepted($estimate));

    $this->actingAs($user);

    Livewire::test('notifications.notification-center')
        ->call('loadPanel')
        ->assertSee(__('Estimate Accepted'));
});

it('shows empty state when no unread notifications', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('notifications.notification-center')
        ->call('loadPanel')
        ->assertSee(__('All caught up!'));
});

it('only shows unread notifications in main list', function () {
    $user = User::factory()->internal()->create();

    $user->notify(new EstimateAccepted(Estimate::factory()->create()));
    $user->notify(new EstimateAccepted(Estimate::factory()->create()));

    // Mark first notification as read
    $user->unreadNotifications()->first()->markAsRead();

    $this->actingAs($user);

    $component = Livewire::test('notifications.notification-center');

    expect($component->get('unreadNotifications'))->toHaveCount(1);
    expect($component->get('unreadCount'))->toBe(1);
});

it('loadPanel sets loaded to true and loads data', function () {
    $user = User::factory()->internal()->create();

    $user->notify(new EstimateAccepted(Estimate::factory()->create()));
    $user->unreadNotifications()->first()->markAsRead();

    $this->actingAs($user);

    Livewire::test('notifications.notification-center')
        ->assertSet('loaded', false)
        ->call('loadPanel')
        ->assertSet('loaded', true)
        ->assertSee(__('History'));
});

it('shows read notifications in history', function () {
    $user = User::factory()->internal()->create();

    $user->notify(new EstimateAccepted(Estimate::factory()->create()));
    $user->unreadNotifications()->first()->markAsRead();

    $this->actingAs($user);

    $component = Livewire::test('notifications.notification-center');

    expect($component->get('historyNotifications'))->toHaveCount(1);
});

it('limits history to 15 notifications', function () {
    $user = User::factory()->internal()->create();

    // Create 20 notifications and mark all as read
    for ($i = 0; $i < 20; $i++) {
        $user->notify(new EstimateAccepted(Estimate::factory()->create()));
    }
    $user->unreadNotifications->markAsRead();

    $this->actingAs($user);

    $component = Livewire::test('notifications.notification-center');

    expect($component->get('historyNotifications'))->toHaveCount(15);
});

it('marking a notification as read moves it from unread to history', function () {
    $user = User::factory()->internal()->create();

    $user->notify(new EstimateAccepted(Estimate::factory()->create()));

    $notification = $user->unreadNotifications()->first();

    $this->actingAs($user);

    $component = Livewire::test('notifications.notification-center');

    expect($component->get('unreadNotifications'))->toHaveCount(1);
    expect($component->get('historyNotifications'))->toHaveCount(0);

    $component->call('markAsRead', $notification->id);

    expect($component->get('unreadNotifications'))->toHaveCount(0);
    expect($component->get('historyNotifications'))->toHaveCount(1);
});
