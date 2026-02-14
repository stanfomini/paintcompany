<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('customer can access profile settings page', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'customer')
        ->get(route('customer.settings.profile'))
        ->assertOk();
});

test('customer can update profile information', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'customer');

    Livewire::test('customer.settings.profile')
        ->set('name', 'Updated Customer Name')
        ->set('email', 'updated-customer@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('Updated Customer Name');
    expect($user->email)->toBe('updated-customer@example.com');
});

test('customer can access password settings page', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'customer')
        ->get(route('customer.settings.password'))
        ->assertOk();
});

test('customer can update password', function () {
    $user = User::factory()->customer()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user, 'customer');

    Livewire::test('customer.settings.password')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('customer password update fails with wrong current password', function () {
    $user = User::factory()->customer()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user, 'customer');

    Livewire::test('customer.settings.password')
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword')
        ->assertHasErrors(['current_password']);
});

test('customer can access appearance settings page', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'customer')
        ->get(route('customer.settings.appearance'))
        ->assertOk();
});

test('customer can access two-factor settings page', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'customer')
        ->get(route('customer.settings.two-factor'))
        ->assertOk();
});

test('customer settings redirect works', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'customer')
        ->get('/customer/settings')
        ->assertRedirect('/customer/settings/profile');
});
