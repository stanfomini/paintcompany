<?php

use App\Models\User;
use Livewire\Livewire;

it('renders the compensation planner for owners', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.compensation-planner')
        ->assertSee('Compensation Planner')
        ->assertSee('Commission Only')
        ->assertSee('Flat + Commission')
        ->assertSee('Flat Only');
});

it('denies access to non-owners', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();

    Livewire::actingAs($user)
        ->test('sales.compensation-planner')
        ->assertForbidden();
});

it('calculates forward projection', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.compensation-planner')
        ->set('avgJobSize', 10000)
        ->set('knockToApptRate', 10)
        ->set('apptToSoldRate', 40)
        ->set('workDaysPerWeek', 5)
        ->set('workWeeksPerYear', 50)
        ->set('knocksPerDay', 30)
        ->set('knockerCommissionPct', 4)
        ->assertSet('avgJobSize', 10000);
});

it('saves a named scenario', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.compensation-planner')
        ->set('scenarioName', 'Conservative Plan')
        ->set('avgJobSize', 8000)
        ->call('saveScenario')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('compensation_scenarios', [
        'name' => 'Conservative Plan',
        'created_by' => $user->id,
    ]);
});
