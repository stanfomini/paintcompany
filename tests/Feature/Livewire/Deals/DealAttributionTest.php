<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\EmployeeProfile;
use App\Models\SalesTerritory;
use App\Models\User;
use Livewire\Livewire;

it('shows attribution section for door knock deals', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $knocker = User::factory()->internal()->create(['name' => 'Jane Knocker']);

    $territory = SalesTerritory::create(['name' => 'West Block', 'level' => 'block', 'status' => 'active']);

    $knock = DoorKnock::create([
        'user_id' => $knocker->id,
        'territory_id' => $territory->id,
        'address' => '456 Elm St',
        'status' => 'appointment_set',
        'knocked_at' => now(),
        'lat' => 39.7392,
        'lng' => -104.9903,
    ]);

    $deal = Deal::factory()->create([
        'lead_source' => 'door_knock',
        'origin_door_knock_id' => $knock->id,
        'assigned_to' => $knocker->id,
    ]);

    Livewire::actingAs($admin)
        ->test('deals.show', ['deal' => $deal])
        ->assertSee('Attribution')
        ->assertSee('Door knock')
        ->assertSee('Jane Knocker')
        ->assertSee('456 Elm St')
        ->assertSee('West Block');
});

it('shows commission entries in the chain', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $knocker = User::factory()->internal()->create(['name' => 'Bob Sales']);
    $knockerProfile = EmployeeProfile::factory()->create(['user_id' => $knocker->id]);

    $deal = Deal::factory()->create([
        'lead_source' => 'website',
        'assigned_to' => $knocker->id,
    ]);

    CommissionEntry::create([
        'employee_profile_id' => $knockerProfile->id,
        'commissionable_type' => EmployeeProfile::class,
        'commissionable_id' => $knockerProfile->id,
        'source_type' => Deal::class,
        'source_id' => $deal->id,
        'source_label' => 'Lead: Website',
        'commission_type' => 'flat',
        'amount' => 100,
        'status' => 'pending',
        'earned_date' => now(),
    ]);

    Livewire::actingAs($admin)
        ->test('deals.show', ['deal' => $deal])
        ->assertSee('Bob Sales')
        ->assertSee('$100.00');
});

it('hides attribution from non-payroll users', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('deals.view');

    $deal = Deal::factory()->create(['lead_source' => 'website']);

    Livewire::actingAs($user)
        ->test('deals.show', ['deal' => $deal])
        ->assertDontSee('Attribution');
});

it('handles deals with no commissions', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    $deal = Deal::factory()->create(['lead_source' => 'website']);

    Livewire::actingAs($admin)
        ->test('deals.show', ['deal' => $deal])
        ->assertSee('No commission entries recorded');
});
