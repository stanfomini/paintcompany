<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\EmployeeProfile;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\CommissionService;

it('creates full commission chain from door knock to job completion', function () {
    $service = app(CommissionService::class);

    $knocker = User::factory()->internal()->create(['name' => 'Sam Knocker']);
    $knockerProfile = EmployeeProfile::factory()->create([
        'user_id' => $knocker->id,
        'commission_rules' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 30, 'active' => true, 'auto_trigger' => true],
        ],
    ]);

    $territory = SalesTerritory::create(['name' => 'Block A', 'level' => 'block', 'status' => 'active']);
    $territory->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $knocker->id]);

    $knock = DoorKnock::create([
        'user_id' => $knocker->id,
        'territory_id' => $territory->id,
        'address' => '789 Oak Ave',
        'status' => 'appointment_set',
        'knocked_at' => now(),
        'lat' => 39.7392,
        'lng' => -104.9903,
    ]);

    $deal = Deal::factory()->create([
        'lead_source' => 'door_knock',
        'origin_door_knock_id' => $knock->id,
        'assigned_to' => $knocker->id,
        'estimated_value' => 5000,
    ]);

    // Step 1: Door knock triggers flat commission
    $knockEntries = $service->createFromDoorKnock($knock);
    expect($knockEntries)->toHaveCount(1)
        ->and((float) $knockEntries[0]->amount)->toBe(30.0)
        ->and($knockEntries[0]->commission_type)->toBe('flat');

    // Step 2: Verify attribution chain shows the knocker
    $chain = $service->getCommissionChainForDeal($deal);
    expect($chain['lead_source'])->toBe('door_knock')
        ->and($chain['knocker']['name'])->toBe('Sam Knocker')
        ->and($chain['knocker']['knock_address'])->toBe('789 Oak Ave')
        ->and($chain['entries'])->toHaveCount(1)
        ->and($chain['total_amount'])->toBe(30.0);
});

it('applies employee override instead of company default', function () {
    $service = app(CommissionService::class);

    // Set company defaults
    CompanySetting::getInstance()->update(['commission_defaults' => [
        'knocker' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 25, 'auto_trigger' => true],
        ],
    ]]);

    // Employee has a personal override for door_knock at $50
    $knocker = User::factory()->internal()->create();
    $knockerProfile = EmployeeProfile::factory()->create([
        'user_id' => $knocker->id,
        'commission_rules' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 50, 'active' => true, 'auto_trigger' => true],
        ],
    ]);

    $territory = SalesTerritory::create(['name' => 'Block B', 'level' => 'block', 'status' => 'active']);
    $territory->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $knocker->id]);

    // Verify effective rules show the override, not the default
    $effectiveRules = $service->getEffectiveRulesForEmployee($knockerProfile);
    $knockRule = collect($effectiveRules)->firstWhere('source', 'door_knock');

    expect($knockRule['amount'])->toBe(50)
        ->and($knockRule['_origin'])->toBe('override');

    // The existing createFromDoorKnock uses employee's personal rules
    $knock = DoorKnock::create([
        'user_id' => $knocker->id,
        'territory_id' => $territory->id,
        'address' => '100 Main St',
        'status' => 'appointment_set',
        'knocked_at' => now(),
        'lat' => 39.7392,
        'lng' => -104.9903,
    ]);

    $entries = $service->createFromDoorKnock($knock);
    expect($entries)->toHaveCount(1)
        ->and((float) $entries[0]->amount)->toBe(50.0);
});

it('walks territory hierarchy for manager override commissions', function () {
    $service = app(CommissionService::class);

    $knocker = User::factory()->internal()->create(['name' => 'Field Knocker']);
    $knockerProfile = EmployeeProfile::factory()->create([
        'user_id' => $knocker->id,
        'commission_rules' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 25, 'active' => true, 'auto_trigger' => true],
        ],
    ]);

    $manager = User::factory()->internal()->create(['name' => 'Zone Manager']);
    $managerProfile = EmployeeProfile::factory()->create([
        'user_id' => $manager->id,
        'commission_rules' => [
            ['source' => 'territory_override', 'type' => 'revenue_percentage', 'rate' => 0.02, 'active' => true, 'auto_trigger' => true],
        ],
    ]);

    // Create zone (parent) and block (child) territories
    $zone = SalesTerritory::create([
        'name' => 'Zone Alpha',
        'level' => 'zone',
        'status' => 'active',
        'manager_id' => $manager->id,
    ]);

    $block = SalesTerritory::create([
        'name' => 'Block 1',
        'level' => 'block',
        'status' => 'active',
        'parent_id' => $zone->id,
    ]);

    $block->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $knocker->id]);

    $knock = DoorKnock::create([
        'user_id' => $knocker->id,
        'territory_id' => $block->id,
        'address' => '200 Elm St',
        'status' => 'appointment_set',
        'knocked_at' => now(),
        'lat' => 39.7392,
        'lng' => -104.9903,
    ]);

    $deal = Deal::factory()->create([
        'lead_source' => 'door_knock',
        'origin_door_knock_id' => $knock->id,
        'assigned_to' => $knocker->id,
        'estimated_value' => 10000,
    ]);

    // Create a job linked to the deal
    $estimate = \App\Models\Estimate::factory()->create([
        'deal_id' => $deal->id,
        'total' => 10000,
    ]);

    $job = \App\Models\Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    // Territory override should fire for zone manager
    $overrideEntries = $service->createTerritoryOverrideCommissions($job);

    expect($overrideEntries)->toHaveCount(1)
        ->and((float) $overrideEntries[0]->amount)->toBe(200.0) // 2% of $10,000
        ->and($overrideEntries[0]->commission_type)->toBe('revenue_percentage');

    // Verify chain shows both knocker commission and manager override
    $knockEntries = $service->createFromDoorKnock($knock);
    $chain = $service->getCommissionChainForDeal($deal);

    expect($chain['entries'])->toHaveCount(2)
        ->and($chain['territory_path'])->toContain('Zone Alpha');
});
