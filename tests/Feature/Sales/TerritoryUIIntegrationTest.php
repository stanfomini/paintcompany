<?php

use App\Models\SalesTerritory;
use App\Models\User;
use Livewire\Livewire;

it('owner can access all 4 tabs', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertSee('Map')
        ->assertSee('Hierarchy')
        ->assertSee('Dispatch')
        ->assertSee('Analytics')
        ->set('activeTab', 'hierarchy')
        ->assertSuccessful()
        ->set('activeTab', 'dispatch')
        ->assertSuccessful()
        ->set('activeTab', 'analytics')
        ->assertSuccessful()
        ->set('activeTab', 'map')
        ->assertSuccessful();
});

it('door knocker cannot access territory manager page', function () {
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();

    $this->actingAs($knocker)
        ->get('/territories')
        ->assertForbidden();
});

it('door knocker can access door knock page', function () {
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();

    $this->actingAs($knocker)
        ->get('/door-knocks')
        ->assertSuccessful();
});

it('compensation planner page is owner-only', function () {
    $owner = User::factory()->internal()->withRole('owner')->create();
    $manager = User::factory()->internal()->withRole('manager')->create();

    $this->actingAs($owner)
        ->get('/compensation-planner')
        ->assertSuccessful();

    $this->actingAs($manager)
        ->get('/compensation-planner')
        ->assertForbidden();
});

it('hierarchy shows correct structure when creating zone under region', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $region = SalesTerritory::factory()->region()->create(['name' => 'Test Region']);

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->assertSee('Test Region')
        ->call('startCreateChild', $region->id, 'zone')
        ->assertSet('creatingChildOfId', $region->id)
        ->set('childName', 'Test Zone')
        ->call('saveChild')
        ->assertSee('Test Zone');
});

it('analytics tab shows P&L data with correct period filtering', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    SalesTerritory::factory()->region()->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->set('activeTab', 'analytics')
        ->assertSee('Territory P&L', escape: false)
        ->set('pnlPeriod', 'quarter')
        ->assertSet('pnlPeriod', 'quarter');
});
