<?php

use App\Models\Deal;
use App\Models\EmployeeProfile;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\SalesTerritory;
use App\Models\TerritoryBudget;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\DoorKnockService;
use App\Services\PermissionScopeService;
use App\Services\TerritoryPnlService;

it('completes full territory pipeline: region → knock → job → commission → P&L', function () {
    // === 1. SET UP HIERARCHY ===
    $owner = User::factory()->internal()->withRole('owner')->create();
    $regionManager = User::factory()->internal()->withRole('manager')->create();
    $estimator = User::factory()->internal()->withRole('estimator')->create();
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();

    // Employee profiles with commission rules
    EmployeeProfile::factory()->for($knocker, 'user')
        ->withDoorKnockCommission('flat', 25)->create();
    EmployeeProfile::factory()->for($estimator, 'user')->create();
    EmployeeProfile::factory()->for($regionManager, 'user')
        ->withTerritoryOverrideCommission(0.02)->create();

    // Territory hierarchy
    $region = SalesTerritory::factory()->region()
        ->assignedTo($regionManager)->managedBy($regionManager)->create(['name' => 'East Region']);
    $zone = SalesTerritory::factory()->zone()
        ->childOf($region)->assignedTo($estimator)->create(['name' => 'Zone 1']);
    $block = SalesTerritory::factory()->block()
        ->childOf($zone)->assignedTo($knocker)->create([
            'name' => 'Block 101',
            'boundary' => ['type' => 'Polygon', 'coordinates' => [[[-90.05, 34.95], [-89.95, 34.95], [-89.95, 35.05], [-90.05, 35.05], [-90.05, 34.95]]]],
        ]);

    // Budget
    TerritoryBudget::factory()->monthly()->create([
        'territory_id' => $region->id,
        'commission_budget_pct' => 12,
        'marketing_budget' => 1000,
    ]);

    // === 2. VERIFY PERMISSIONS ===
    $scopeService = app(PermissionScopeService::class);

    $scopeService->grantPermission($regionManager, 'territories.view_own',
        ['territory_ids' => [$region->id, $zone->id, $block->id]], $owner);
    $scopeService->grantPermission($knocker, 'territories.view_own',
        ['territory_ids' => [$block->id]], $owner);

    // Region manager sees their tree
    $visibleToManager = $scopeService->getVisibleTerritoryIds($regionManager);
    expect($visibleToManager)->toContain($region->id);
    expect($visibleToManager)->toContain($zone->id);
    expect($visibleToManager)->toContain($block->id);

    // Knocker sees only their block
    $visibleToKnocker = $scopeService->getVisibleTerritoryIds($knocker);
    expect($visibleToKnocker)->toContain($block->id);
    expect($visibleToKnocker)->not->toContain($zone->id);

    // === 3. DOOR KNOCK ===
    $this->actingAs($knocker);
    $doorKnockService = app(DoorKnockService::class);

    $knock = $doorKnockService->recordKnock($knocker, [
        'territory_id' => $block->id,
        'address' => '456 Oak St',
        'city' => 'Memphis',
        'state' => 'TN',
        'zip' => '38101',
        'lat' => 35.0,
        'lng' => -90.0,
        'status' => 'appointment_set',
        'customer_name' => 'John Doe',
        'email' => 'johndoe-pipeline-test@example.com',
        'phone' => '555-111-2222',
    ]);

    expect($knock->territory_id)->toBe($block->id);
    expect($knock->status)->toBe('appointment_set');

    // === 4. CONVERT TO LEAD ===
    $deal = $doorKnockService->convertToLead($knock);

    expect($deal)->toBeInstanceOf(Deal::class);
    expect($deal->origin_door_knock_id)->toBe($knock->id);
    expect($deal->stage)->toBe('lead');

    // === 5. KNOCKER COMMISSION ===
    $commissionService = app(CommissionService::class);
    $knockerCommissions = $commissionService->createFromDoorKnock($knock);

    expect($knockerCommissions)->toHaveCount(1);
    expect((float) $knockerCommissions[0]->amount)->toBe(25.0);

    // === 6. SIMULATE JOB COMPLETION ===
    $deal->update(['actual_value' => 10000, 'stage' => 'completed']);

    $estimate = Estimate::factory()->forDeal($deal)->create([
        'total' => 10000,
    ]);
    $job = Job::factory()->for($deal)->create([
        'estimate_id' => $estimate->id,
        'stage' => 'completed',
    ]);

    // === 7. TERRITORY OVERRIDE COMMISSION ===
    $overrideCommissions = $commissionService->createTerritoryOverrideCommissions($job);

    // Region manager should get 2% of $10,000 = $200
    expect($overrideCommissions)->toHaveCount(1);
    expect((float) $overrideCommissions[0]->amount)->toBe(200.0);

    // === 8. P&L CHECK ===
    $pnlService = app(TerritoryPnlService::class);

    // Block-level P&L
    $blockPnl = $pnlService->calculatePnl($block, now()->startOfMonth(), now()->endOfMonth());
    expect($blockPnl['revenue'])->toBe(10000.0);
    expect($blockPnl['commission_costs'])->toBe(25.0);

    // Region rollup P&L (includes all children)
    $regionPnl = $pnlService->calculatePnlRollup($region, now()->startOfMonth(), now()->endOfMonth());
    expect($regionPnl['revenue'])->toBe(10000.0);

    // === 9. BUDGET CHECK ===
    expect($blockPnl['within_budget'])->toBeTrue();
});

it('hierarchy relationships are consistent', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    $block = SalesTerritory::factory()->block()->childOf($zone)->create();

    // Parent chain
    expect($block->parent->id)->toBe($zone->id);
    expect($zone->parent->id)->toBe($region->id);
    expect($region->parent)->toBeNull();

    // Children chain
    expect($region->children)->toHaveCount(1);
    expect($region->children->first()->id)->toBe($zone->id);
    expect($zone->children)->toHaveCount(1);
    expect($zone->children->first()->id)->toBe($block->id);

    // Level validation
    expect($region->level)->toBe('region');
    expect($zone->level)->toBe('zone');
    expect($block->level)->toBe('block');

    // Ancestor/descendant methods
    $ancestors = $block->getAncestors();
    expect($ancestors)->toHaveCount(2);
    expect($ancestors->pluck('id')->toArray())->toContain($zone->id, $region->id);

    $descendants = $region->getDescendantIds();
    expect($descendants)->toContain($zone->id, $block->id);
});

it('prevents orphaned territory hierarchy', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    $block = SalesTerritory::factory()->block()->childOf($zone)->create();

    expect($zone->parent_id)->toBe($region->id);
    expect($block->parent_id)->toBe($zone->id);
    expect($region->parent_id)->toBeNull();
});
