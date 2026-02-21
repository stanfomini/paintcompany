<?php

use App\Models\User;
use Livewire\Livewire;

it('shows schedule tab instead of dispatch for zone managers', function () {
    $manager = User::factory()->internal()->withRole('manager')->create();

    Livewire::actingAs($manager)
        ->test('sales.territory-manager')
        ->assertDontSee('Dispatch')
        ->assertSee('Schedule');
});
