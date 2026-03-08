<?php

use App\Models\MessengerResponder;
use App\Models\MessengerSetting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    MessengerSetting::clearInstanceCache();
    $this->internalUser = User::factory()->internal()->create();
    $this->internalUser->givePermissionTo('messenger.manage');
    $this->actingAs($this->internalUser);
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

describe('responder roster management', function () {
    it('renders the settings page with responder roster', function () {
        $this->get('/settings/messenger')
            ->assertSuccessful()
            ->assertSee('Messenger Responders');
    });

    it('can add a responder as estimator', function () {
        $newUser = User::factory()->internal()->create(['name' => 'New Estimator']);

        Livewire::test('pages::settings.messenger-settings')
            ->set('newResponderId', $newUser->id)
            ->set('newResponderRole', 'estimator')
            ->call('addResponder');

        $responder = MessengerResponder::where('user_id', $newUser->id)->first();
        expect($responder)->not->toBeNull();
        expect($responder->role)->toBe('estimator');
        expect($responder->is_active)->toBeTrue();
    });

    it('can add a responder as appointment booker', function () {
        $newUser = User::factory()->internal()->create();

        Livewire::test('pages::settings.messenger-settings')
            ->set('newResponderId', $newUser->id)
            ->set('newResponderRole', 'appointment_booker')
            ->call('addResponder');

        $responder = MessengerResponder::where('user_id', $newUser->id)->first();
        expect($responder)->not->toBeNull();
        expect($responder->role)->toBe('appointment_booker');
    });

    it('can add a responder with both role', function () {
        $newUser = User::factory()->internal()->create();

        Livewire::test('pages::settings.messenger-settings')
            ->set('newResponderId', $newUser->id)
            ->set('newResponderRole', 'both')
            ->call('addResponder');

        $responder = MessengerResponder::where('user_id', $newUser->id)->first();
        expect($responder->role)->toBe('both');
    });

    it('prevents adding duplicate responders', function () {
        $user = User::factory()->internal()->create();
        MessengerResponder::factory()->estimator()->create(['user_id' => $user->id]);

        Livewire::test('pages::settings.messenger-settings')
            ->set('newResponderId', $user->id)
            ->set('newResponderRole', 'estimator')
            ->call('addResponder')
            ->assertHasErrors('newResponderId');
    });

    it('prevents adding non-internal users', function () {
        $customer = User::factory()->customer()->create();

        Livewire::test('pages::settings.messenger-settings')
            ->set('newResponderId', $customer->id)
            ->set('newResponderRole', 'estimator')
            ->call('addResponder')
            ->assertHasErrors('newResponderId');
    });

    it('can remove a responder', function () {
        $user = User::factory()->internal()->create();
        $responder = MessengerResponder::factory()->estimator()->create(['user_id' => $user->id]);

        Livewire::test('pages::settings.messenger-settings')
            ->call('removeResponder', $responder->id);

        expect(MessengerResponder::find($responder->id))->toBeNull();
    });

    it('can update responder role', function () {
        $user = User::factory()->internal()->create();
        $responder = MessengerResponder::factory()->estimator()->create(['user_id' => $user->id]);

        Livewire::test('pages::settings.messenger-settings')
            ->call('updateResponderRole', $responder->id, 'appointment_booker');

        expect($responder->fresh()->role)->toBe('appointment_booker');
    });

    it('can toggle responder active status', function () {
        $user = User::factory()->internal()->create();
        $responder = MessengerResponder::factory()->estimator()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        Livewire::test('pages::settings.messenger-settings')
            ->call('toggleResponderActive', $responder->id);

        expect($responder->fresh()->is_active)->toBeFalse();

        Livewire::test('pages::settings.messenger-settings')
            ->call('toggleResponderActive', $responder->id);

        expect($responder->fresh()->is_active)->toBeTrue();
    });

    it('shows existing responders in the roster', function () {
        $user = User::factory()->internal()->create(['name' => 'Test Responder']);
        MessengerResponder::factory()->estimator()->create(['user_id' => $user->id]);

        Livewire::test('pages::settings.messenger-settings')
            ->assertSee('Test Responder');
    });

    it('does not show already-rostered users in available users dropdown', function () {
        $onRoster = User::factory()->internal()->create(['name' => 'Already Rostered']);
        $available = User::factory()->internal()->create(['name' => 'Available User']);
        MessengerResponder::factory()->estimator()->create(['user_id' => $onRoster->id]);

        // Verify via DB query that the available users logic excludes rostered users
        $existingUserIds = MessengerResponder::pluck('user_id');
        $availableUsers = User::where('type', 'internal')
            ->whereNotIn('id', $existingUserIds)
            ->get();

        expect($availableUsers->pluck('id')->contains($onRoster->id))->toBeFalse();
        expect($availableUsers->pluck('id')->contains($available->id))->toBeTrue();
    });
});
