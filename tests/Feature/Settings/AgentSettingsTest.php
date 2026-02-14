<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('agent can access profile settings page', function () {
    $user = User::factory()->agent()->create();

    $this->actingAs($user, 'agent')
        ->get(route('agent.settings.profile'))
        ->assertOk();
});

test('agent can update profile information', function () {
    $user = User::factory()->agent()->create();

    $this->actingAs($user, 'agent');

    Livewire::test('agent.settings.profile')
        ->set('name', 'Updated Agent Name')
        ->set('email', 'updated-agent@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('Updated Agent Name');
    expect($user->email)->toBe('updated-agent@example.com');
});

test('agent can access password settings page', function () {
    $user = User::factory()->agent()->create();

    $this->actingAs($user, 'agent')
        ->get(route('agent.settings.password'))
        ->assertOk();
});

test('agent can update password', function () {
    $user = User::factory()->agent()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user, 'agent');

    Livewire::test('agent.settings.password')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('agent password update fails with wrong current password', function () {
    $user = User::factory()->agent()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user, 'agent');

    Livewire::test('agent.settings.password')
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword')
        ->assertHasErrors(['current_password']);
});

test('agent can access appearance settings page', function () {
    $user = User::factory()->agent()->create();

    $this->actingAs($user, 'agent')
        ->get(route('agent.settings.appearance'))
        ->assertOk();
});

test('agent can access two-factor settings page', function () {
    $user = User::factory()->agent()->create();

    $this->actingAs($user, 'agent')
        ->get(route('agent.settings.two-factor'))
        ->assertOk();
});

test('agent settings redirect works', function () {
    $user = User::factory()->agent()->create();

    $this->actingAs($user, 'agent')
        ->get('/agent/settings')
        ->assertRedirect('/agent/settings/profile');
});
