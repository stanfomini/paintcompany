<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\EmployeeProfile;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\CommissionService;

it('builds attribution chain for a door knock deal', function () {
    $knocker = User::factory()->internal()->create(['name' => 'John Knocker']);
    $knockerProfile = EmployeeProfile::factory()->create(['user_id' => $knocker->id]);

    $territory = SalesTerritory::create([
        'name' => 'Block 1', 'level' => 'block', 'status' => 'active',
    ]);

    $knock = DoorKnock::create([
        'user_id' => $knocker->id,
        'territory_id' => $territory->id,
        'address' => '123 Main St',
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

    CommissionEntry::create([
        'employee_profile_id' => $knockerProfile->id,
        'commissionable_type' => EmployeeProfile::class,
        'commissionable_id' => $knockerProfile->id,
        'source_type' => DoorKnock::class,
        'source_id' => $knock->id,
        'source_label' => 'Door Knock: 123 Main St',
        'commission_type' => 'flat',
        'amount' => 25,
        'status' => 'pending',
        'earned_date' => now(),
    ]);

    $chain = app(CommissionService::class)->getCommissionChainForDeal($deal);

    expect($chain['lead_source'])->toBe('door_knock')
        ->and($chain['knocker'])->not->toBeNull()
        ->and($chain['knocker']['name'])->toBe('John Knocker')
        ->and($chain['territory_path'])->not->toBeNull()
        ->and($chain['entries'])->toHaveCount(1);
});

it('returns minimal chain for non-knock deals', function () {
    $deal = Deal::factory()->create(['lead_source' => 'website', 'origin_door_knock_id' => null]);

    $chain = app(CommissionService::class)->getCommissionChainForDeal($deal);

    expect($chain['lead_source'])->toBe('website')
        ->and($chain['knocker'])->toBeNull()
        ->and($chain['territory_path'])->toBeNull()
        ->and($chain['entries'])->toBe([]);
});

it('includes territory hierarchy path', function () {
    $knocker = User::factory()->internal()->create();

    $region = SalesTerritory::create(['name' => 'North Region', 'level' => 'region', 'status' => 'active']);
    $zone = SalesTerritory::create(['name' => 'Zone A', 'level' => 'zone', 'status' => 'active', 'parent_id' => $region->id]);
    $block = SalesTerritory::create(['name' => 'Block 3', 'level' => 'block', 'status' => 'active', 'parent_id' => $zone->id]);

    $knock = DoorKnock::create([
        'user_id' => $knocker->id,
        'territory_id' => $block->id,
        'address' => '456 Elm St',
        'status' => 'appointment_set',
        'knocked_at' => now(),
        'lat' => 39.7392,
        'lng' => -104.9903,
    ]);

    $deal = Deal::factory()->create([
        'lead_source' => 'door_knock',
        'origin_door_knock_id' => $knock->id,
    ]);

    $chain = app(CommissionService::class)->getCommissionChainForDeal($deal);

    expect($chain['territory_path'])->toContain('North Region')
        ->and($chain['territory_path'])->toContain('Zone A')
        ->and($chain['territory_path'])->toContain('Block 3');
});

it('calculates total commission amount and percentage', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create([
        'lead_source' => 'website',
        'origin_door_knock_id' => null,
        'estimated_value' => 10000,
        'assigned_to' => $user->id,
    ]);

    CommissionEntry::create([
        'employee_profile_id' => $employee->id,
        'commissionable_type' => EmployeeProfile::class,
        'commissionable_id' => $employee->id,
        'source_type' => Deal::class,
        'source_id' => $deal->id,
        'source_label' => 'Lead: Test Deal',
        'commission_type' => 'revenue_percentage',
        'basis_amount' => 10000,
        'rate' => 0.06,
        'amount' => 600,
        'status' => 'pending',
        'earned_date' => now(),
    ]);

    $chain = app(CommissionService::class)->getCommissionChainForDeal($deal);

    expect($chain['entries'])->toHaveCount(1)
        ->and($chain['total_amount'])->toBe(600.0)
        ->and($chain['revenue'])->toBe(10000.0)
        ->and($chain['commission_percent'])->toBe(6.0);
});
