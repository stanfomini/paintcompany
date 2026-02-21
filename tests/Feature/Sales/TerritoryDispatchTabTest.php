<?php

use App\Models\SalesTerritory;
use App\Models\User;
use Livewire\Livewire;

it('shows dispatch tab with blocks for zone manager', function () {
    $manager = User::factory()->internal()->withRole('manager')->create();
    $region = SalesTerritory::factory()->region()->managedBy($manager)->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->assignedTo($manager)->create(['name' => 'Zone Alpha']);
    $block1 = SalesTerritory::factory()->block()->childOf($zone)->create(['name' => 'Block 1']);
    $block2 = SalesTerritory::factory()->block()->childOf($zone)->create(['name' => 'Block 2']);

    Livewire::actingAs($manager)
        ->test('sales.territory-manager')
        ->set('activeTab', 'dispatch')
        ->assertSee('Block 1')
        ->assertSee('Block 2');
});
