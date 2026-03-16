<?php

use App\Models\CallLog;
use App\Models\Estimate;
use App\Models\User;
use App\Notifications\EstimateAccepted;
use Livewire\Livewire;

it('renders for internal users', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertStatus(200)
        ->assertSee(__('Notifications'))
        ->assertSee(__('Messages'));
});

it('denies access to non-internal users', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'customer');

    Livewire::test('layout.footer-bar')
        ->assertForbidden();
});

it('shows notification count when there are unread notifications', function () {
    $user = User::factory()->internal()->create();

    $user->notify(new EstimateAccepted(
        Estimate::factory()->create()
    ));

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertSee('1');
});

it('shows zero badges when no unread items', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertDontSee('99+');
});

it('shows record button for users with media.record permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.record');

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertSee(__('Record'));
});

it('hides record button for users without media.record permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertDontSee(__('Record'));
});

it('links to communications hub', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertSeeHtml(route('communications.index'));
});

it('shows phone button for users with phone.make_calls permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.make_calls');

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertSee(__('Phone'));
});

it('hides phone button for users without phone.make_calls permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertDontSee(__('Phone'));
});

it('shows missed call count badge', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.make_calls');

    CallLog::factory()->inbound()->missed()->create([
        'created_at' => now(),
    ]);
    CallLog::factory()->inbound()->missed()->create([
        'created_at' => now(),
    ]);

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertSee('2');
});

it('does not show missed call badge when no missed calls', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.make_calls');

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertSee(__('Phone'))
        ->assertDontSeeHtml('bg-red-500 text-white text-[10px]');
});

it('excludes yesterday missed calls from count', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.make_calls');

    CallLog::factory()->inbound()->missed()->create([
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($user);

    // Yesterday's missed calls should not produce a badge count
    $count = CallLog::inbound()->missed()
        ->where('created_at', '>=', now()->startOfDay())
        ->count();

    expect($count)->toBe(0);
});

it('excludes completed inbound calls from missed count', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.make_calls');

    CallLog::factory()->inbound()->completed()->create([
        'created_at' => now(),
    ]);

    $this->actingAs($user);

    $count = CallLog::inbound()->missed()
        ->where('created_at', '>=', now()->startOfDay())
        ->count();

    expect($count)->toBe(0);
});

it('counts only today missed inbound calls', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.make_calls');

    // Today's missed calls
    CallLog::factory()->inbound()->missed()->create(['created_at' => now()]);
    CallLog::factory()->inbound()->voicemail()->create(['created_at' => now()]);

    // Yesterday (should not count)
    CallLog::factory()->inbound()->missed()->create(['created_at' => now()->subDay()]);

    // Completed today (should not count)
    CallLog::factory()->inbound()->completed()->create(['created_at' => now()]);

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertSee('2');
});
