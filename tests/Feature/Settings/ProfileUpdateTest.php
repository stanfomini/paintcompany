<?php

use App\Models\User;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-form')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});

test('internal user can save phone settings', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('forwarding_number', '3035551234')
        ->set('extension', '101')
        ->call('savePhoneSettings')
        ->assertHasNoErrors()
        ->assertDispatched('phone-settings-saved');

    $user->refresh();

    expect($user->forwarding_number)->toBe('3035551234');
    expect($user->extension)->toBe('101');
});

test('phone settings validates extension format', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('extension', '12')
        ->call('savePhoneSettings')
        ->assertHasErrors(['extension']);
});

test('phone settings validates extension uniqueness', function () {
    User::factory()->internal()->create(['extension' => '101']);
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('extension', '101')
        ->call('savePhoneSettings')
        ->assertHasErrors(['extension']);
});

test('phone settings validates forwarding number format', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('forwarding_number', 'not-a-phone')
        ->call('savePhoneSettings')
        ->assertHasErrors(['forwarding_number']);
});

test('non-internal user cannot save phone settings', function () {
    $user = User::factory()->customer()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('forwarding_number', '3035551234')
        ->call('savePhoneSettings')
        ->assertHasNoErrors();

    expect($user->refresh()->forwarding_number)->toBeNull();
});

test('phone settings section is visible for internal users', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->assertSee('Phone Settings');
});

test('phone settings allows nullable values', function () {
    $user = User::factory()->internal()->create([
        'forwarding_number' => '3035551234',
        'extension' => '101',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('forwarding_number', '')
        ->set('extension', '')
        ->call('savePhoneSettings')
        ->assertHasNoErrors()
        ->assertDispatched('phone-settings-saved');

    $user->refresh();

    expect($user->forwarding_number)->toBeNull();
    expect($user->extension)->toBeNull();
});
