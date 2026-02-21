<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\EmployeeProfile;
use App\Models\SalesTerritory;
use App\Models\TerritoryBudget;
use App\Models\User;
use App\Services\TerritoryPnlService;

it('calculates P&L for a territory with revenue and commissions', function () {
    $territory = SalesTerritory::factory()->zone()->create();
    $knocker = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->for($knocker, 'user')->create();

    // Create completed deals in this territory
    $knock = DoorKnock::factory()->sold()->forUser($knocker)->create([
        'territory_id' => $territory->id,
        'knocked_at' => now(),
    ]);
    $deal = Deal::factory()->create([
        'origin_door_knock_id' => $knock->id,
        'actual_value' => 8000,
        'stage' => 'completed',
    ]);
    $knock->update(['deal_id' => $deal->id]);

    // Create commission entries
    CommissionEntry::factory()->flat(50)->create([
        'employee_profile_id' => $employee->id,
        'source_type' => DoorKnock::class,
        'source_id' => $knock->id,
        'earned_date' => now(),
    ]);

    $service = app(TerritoryPnlService::class);
    $pnl = $service->calculatePnl($territory, now()->startOfMonth(), now()->endOfMonth());

    expect($pnl['revenue'])->toBe(8000.0);
    expect($pnl['commission_costs'])->toBe(50.0);
    expect($pnl['margin'])->toBe(7950.0);
});

it('calculates budget percentage against target', function () {
    $territory = SalesTerritory::factory()->zone()->create();
    TerritoryBudget::factory()->monthly()->create([
        'territory_id' => $territory->id,
        'commission_budget_pct' => 12,
        'period_start' => now()->startOfMonth(),
    ]);

    $knocker = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->for($knocker, 'user')->create();

    $knock = DoorKnock::factory()->sold()->forUser($knocker)->create([
        'territory_id' => $territory->id,
        'knocked_at' => now(),
    ]);
    $deal = Deal::factory()->create([
        'origin_door_knock_id' => $knock->id,
        'actual_value' => 10000,
        'stage' => 'completed',
    ]);
    $knock->update(['deal_id' => $deal->id]);

    CommissionEntry::factory()->flat(800)->create([
        'employee_profile_id' => $employee->id,
        'source_type' => DoorKnock::class,
        'source_id' => $knock->id,
        'earned_date' => now(),
    ]);

    $service = app(TerritoryPnlService::class);
    $pnl = $service->calculatePnl($territory, now()->startOfMonth(), now()->endOfMonth());

    expect($pnl['budget_pct'])->toBe(8.0); // 800/10000 = 8%
    expect($pnl['budget_target_pct'])->toBe(12.0);
    expect($pnl['within_budget'])->toBeTrue();
});

it('rolls up P&L from children', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zone1 = SalesTerritory::factory()->zone()->childOf($region)->create();
    $zone2 = SalesTerritory::factory()->zone()->childOf($region)->create();

    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->for($user, 'user')->create();

    // Revenue in zone1
    $knock1 = DoorKnock::factory()->sold()->forUser($user)->create([
        'territory_id' => $zone1->id,
        'knocked_at' => now(),
    ]);
    $deal1 = Deal::factory()->create([
        'origin_door_knock_id' => $knock1->id,
        'actual_value' => 5000,
        'stage' => 'completed',
    ]);
    $knock1->update(['deal_id' => $deal1->id]);

    // Revenue in zone2
    $knock2 = DoorKnock::factory()->sold()->forUser($user)->create([
        'territory_id' => $zone2->id,
        'knocked_at' => now(),
    ]);
    $deal2 = Deal::factory()->create([
        'origin_door_knock_id' => $knock2->id,
        'actual_value' => 7000,
        'stage' => 'completed',
    ]);
    $knock2->update(['deal_id' => $deal2->id]);

    $service = app(TerritoryPnlService::class);
    $pnl = $service->calculatePnlRollup($region, now()->startOfMonth(), now()->endOfMonth());

    expect($pnl['revenue'])->toBe(12000.0);
});
