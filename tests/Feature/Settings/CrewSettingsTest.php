<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('crew can access profile settings page', function () {
    $user = User::factory()->crew()->create();

    $this->actingAs($user, 'crew')
        ->get(route('crew.settings.profile'))
        ->assertOk();
});

test('crew can update profile information', function () {
    $user = User::factory()->crew()->create();

    $this->actingAs($user, 'crew');

    Livewire::test('crew.settings.profile')
        ->set('name', 'Updated Crew Name')
        ->set('email', 'updated-crew@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('Updated Crew Name');
    expect($user->email)->toBe('updated-crew@example.com');
});

test('crew can access password settings page', function () {
    $user = User::factory()->crew()->create();

    $this->actingAs($user, 'crew')
        ->get(route('crew.settings.password'))
        ->assertOk();
});

test('crew can update password', function () {
    $user = User::factory()->crew()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user, 'crew');

    Livewire::test('crew.settings.password')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('crew password update fails with wrong current password', function () {
    $user = User::factory()->crew()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user, 'crew');

    Livewire::test('crew.settings.password')
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword')
        ->assertHasErrors(['current_password']);
});

test('crew can access appearance settings page', function () {
    $user = User::factory()->crew()->create();

    $this->actingAs($user, 'crew')
        ->get(route('crew.settings.appearance'))
        ->assertOk();
});

test('crew can access two-factor settings page', function () {
    $user = User::factory()->crew()->create();

    $this->actingAs($user, 'crew')
        ->get(route('crew.settings.two-factor'))
        ->assertOk();
});

test('crew can still access crew profile settings page', function () {
    $user = User::factory()->crew()->create();

    $this->actingAs($user, 'crew')
        ->get(route('crew.settings'))
        ->assertOk();
});

test('crew settings redirect works', function () {
    $user = User::factory()->crew()->create();

    $this->actingAs($user, 'crew')
        ->get('/crew/settings')
        ->assertRedirect('/crew/settings/profile');
});
