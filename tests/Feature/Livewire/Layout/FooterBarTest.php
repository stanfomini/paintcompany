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

it('shows missed call count badge for unhandled calls', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.make_calls');

    // Assign calls to user so they're visible via visibleTo scope
    CallLog::factory()->inbound()->missed()->forUser($user)->create();
    CallLog::factory()->inbound()->missed()->forUser($user)->create();

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

it('includes unhandled missed calls from previous days in count', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.make_calls');

    // Yesterday's unhandled missed call should still count (needs attention)
    CallLog::factory()->inbound()->missed()->forUser($user)->create([
        'created_at' => now()->subDay(),
    ]);

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertSee('1');
});

it('excludes completed calls from missed count', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.make_calls');

    CallLog::factory()->inbound()->completed()->forUser($user)->create();

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertDontSeeHtml('bg-red-500 text-white text-[10px]');
});

it('counts all unhandled missed calls and voicemails', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.make_calls');

    // Unhandled missed calls (should count)
    CallLog::factory()->inbound()->missed()->forUser($user)->create();
    CallLog::factory()->inbound()->voicemail()->forUser($user)->create();

    // Older unhandled (should still count)
    CallLog::factory()->inbound()->missed()->forUser($user)->create(['created_at' => now()->subDay()]);

    // Completed (should not count)
    CallLog::factory()->inbound()->completed()->forUser($user)->create();

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertSee('3');
});

it('excludes handled missed calls from badge count', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.make_calls');

    $call = CallLog::factory()->inbound()->missed()->forUser($user)->create();

    // Mark as handled
    $call->markHandledBy($user, 'called_back');

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertDontSeeHtml('bg-red-500 text-white text-[10px]');
});

it('shows company-wide missed call count for user with receive_calls permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.make_calls');
    $user->givePermissionTo('phone.receive_calls');

    // Company-wide missed calls (voicemail_for_user_id = null)
    CallLog::factory()->inbound()->missed()->companyWide()->create();
    CallLog::factory()->inbound()->missed()->companyWide()->create();

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertSee('2');
});

it('does not show company-wide missed calls for user with only make_calls', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.make_calls');

    // Company-wide missed calls — user has no receive_calls or view permissions
    CallLog::factory()->inbound()->missed()->companyWide()->create();

    $this->actingAs($user);

    Livewire::test('layout.footer-bar')
        ->assertDontSeeHtml('bg-red-500 text-white text-[10px]');
});
