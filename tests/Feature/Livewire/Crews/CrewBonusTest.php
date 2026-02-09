<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

describe('Crew Show Bonus Display', function () {
    it('displays bonus percent on overview tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crewUser = User::factory()->crew()->create();
        $crewUser->crewProfile->update(['bonus_percent' => 5.0]);

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crewUser])
            ->assertSee('5.0% of job price');
    });

    it('shows not set when no bonus configured', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crewUser = User::factory()->crew()->create();

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crewUser])
            ->assertSee('Not set');
    });
});

describe('Crew Show Bonus Editing', function () {
    it('can update bonus percent via edit modal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crewUser = User::factory()->crew()->create();

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crewUser])
            ->call('openEditProfileModal')
            ->set('editBonusPercent', 7.5)
            ->call('saveProfile')
            ->assertHasNoErrors();

        expect((float) $crewUser->crewProfile->fresh()->bonus_percent)->toBe(7.50);
    });

    it('validates bonus percent range', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $crewUser = User::factory()->crew()->create();

        Livewire::actingAs($user)
            ->test('crews.show', ['user' => $crewUser])
            ->call('openEditProfileModal')
            ->set('editBonusPercent', 150)
            ->call('saveProfile')
            ->assertHasErrors('editBonusPercent');
    });
});
