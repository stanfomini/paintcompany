<?php

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
