<?php

use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\EmployeeProfile;
use App\Models\SalesTerritory;
use App\Models\User;
use Livewire\Livewire;

it('shows P&L cards on analytics tab', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    $region = SalesTerritory::factory()->region()->create(['name' => 'West Region']);
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();

    $knocker = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->for($knocker, 'user')->create();

    $knock = DoorKnock::factory()->sold()->forUser($knocker)->create([
        'territory_id' => $zone->id,
        'knocked_at' => now(),
    ]);
    $deal = Deal::factory()->create([
        'origin_door_knock_id' => $knock->id,
        'actual_value' => 10000,
        'stage' => 'completed',
    ]);
    $knock->update(['deal_id' => $deal->id]);

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->set('activeTab', 'analytics')
        ->assertSee('West Region')
        ->assertSee('Revenue');
});
