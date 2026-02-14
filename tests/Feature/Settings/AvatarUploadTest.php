<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('user can upload avatar', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->image('avatar.jpg', 200, 200))
        ->set('name', $user->name)
        ->set('email', $user->email)
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->avatar_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->avatar_path);
});

test('user can remove avatar', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $path = UploadedFile::fake()->image('avatar.jpg')->store('avatars', 'public');
    $user->update(['avatar_path' => $path]);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->call('removeAvatar')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->avatar_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('avatar upload rejects non-image files', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->create('document.pdf', 100))
        ->assertHasErrors(['avatar']);
});

test('avatar upload rejects oversized files', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->image('large.jpg')->size(3000))
        ->assertHasErrors(['avatar']);
});

test('hasAvatar returns correct value', function () {
    $userWithout = User::factory()->create(['avatar_path' => null]);
    $userWith = User::factory()->create(['avatar_path' => 'avatars/test.jpg']);

    expect($userWithout->hasAvatar())->toBeFalse();
    expect($userWith->hasAvatar())->toBeTrue();
});

test('getAvatarUrl returns null when no avatar', function () {
    $user = User::factory()->create(['avatar_path' => null]);

    expect($user->getAvatarUrl())->toBeNull();
});

test('getAvatarUrl returns url when avatar exists', function () {
    Storage::fake('public');

    $user = User::factory()->create(['avatar_path' => 'avatars/test.jpg']);

    expect($user->getAvatarUrl())->toContain('avatars/test.jpg');
});

test('agent can upload avatar', function () {
    Storage::fake('public');

    $user = User::factory()->agent()->create();

    $this->actingAs($user, 'agent');

    Livewire::test('agent.settings.profile')
        ->set('avatar', UploadedFile::fake()->image('avatar.jpg', 200, 200))
        ->set('name', $user->name)
        ->set('email', $user->email)
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->avatar_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->avatar_path);
});

test('customer can upload avatar', function () {
    Storage::fake('public');

    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'customer');

    Livewire::test('customer.settings.profile')
        ->set('avatar', UploadedFile::fake()->image('avatar.jpg', 200, 200))
        ->set('name', $user->name)
        ->set('email', $user->email)
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->avatar_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->avatar_path);
});

test('crew can upload avatar', function () {
    Storage::fake('public');

    $user = User::factory()->crew()->create();

    $this->actingAs($user, 'crew');

    Livewire::test('crew.settings.profile')
        ->set('avatar', UploadedFile::fake()->image('avatar.jpg', 200, 200))
        ->set('name', $user->name)
        ->set('email', $user->email)
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->avatar_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->avatar_path);
});

test('deleteAvatar cleans up file and nulls column', function () {
    Storage::fake('public');

    $path = UploadedFile::fake()->image('avatar.jpg')->store('avatars', 'public');
    $user = User::factory()->create(['avatar_path' => $path]);

    Storage::disk('public')->assertExists($path);

    $user->deleteAvatar();

    $user->refresh();

    expect($user->avatar_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});
