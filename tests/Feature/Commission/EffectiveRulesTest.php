<?php

use App\Models\CompanySetting;
use App\Models\EmployeeProfile;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\CommissionService;

beforeEach(function () {
    $this->service = app(CommissionService::class);

    CompanySetting::getInstance()->update(['commission_defaults' => [
        'knocker' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 25, 'auto_trigger' => true],
            ['source' => 'job', 'type' => 'revenue_percentage', 'rate' => 0.04, 'auto_trigger' => true],
        ],
        'estimator' => [
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.06, 'auto_trigger' => true],
        ],
        'manager_override' => [
            ['source' => 'territory_override', 'type' => 'revenue_percentage', 'rate' => 0.02, 'auto_trigger' => true],
        ],
    ]]);
});

it('returns company defaults when employee has no overrides', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id, 'commission_rules' => null]);

    $territory = SalesTerritory::create(['name' => 'Test Block', 'level' => 'block', 'status' => 'active']);
    $territory->teamMembers()->attach($user->id, ['role' => 'knocker', 'added_by' => $user->id]);

    $rules = $this->service->getEffectiveRulesForEmployee($employee);

    expect($rules)->toHaveCount(2)
        ->and($rules[0]['source'])->toBe('door_knock')
        ->and($rules[1]['source'])->toBe('job');
});

it('uses employee overrides instead of defaults for matching source', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'commission_rules' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 50, 'active' => true, 'auto_trigger' => true],
        ],
    ]);

    $territory = SalesTerritory::create(['name' => 'Test Block', 'level' => 'block', 'status' => 'active']);
    $territory->teamMembers()->attach($user->id, ['role' => 'knocker', 'added_by' => $user->id]);

    $rules = $this->service->getEffectiveRulesForEmployee($employee);

    $knockRule = collect($rules)->firstWhere('source', 'door_knock');
    $jobRule = collect($rules)->firstWhere('source', 'job');

    expect($knockRule['amount'])->toBe(50)
        ->and($jobRule['rate'])->toBe(0.04);
});

it('adds additional employee rules not in defaults', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'commission_rules' => [
            ['source' => 'lead', 'type' => 'flat', 'amount' => 10, 'active' => true, 'auto_trigger' => true],
        ],
    ]);

    $territory = SalesTerritory::create(['name' => 'Test Block', 'level' => 'block', 'status' => 'active']);
    $territory->teamMembers()->attach($user->id, ['role' => 'knocker', 'added_by' => $user->id]);

    $rules = $this->service->getEffectiveRulesForEmployee($employee);

    expect($rules)->toHaveCount(3);
    $leadRule = collect($rules)->firstWhere('source', 'lead');
    expect($leadRule['amount'])->toBe(10);
});

it('returns empty array when no role and no overrides', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id, 'commission_rules' => null]);

    $rules = $this->service->getEffectiveRulesForEmployee($employee);

    expect($rules)->toBe([]);
});

it('allows explicit role override parameter', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id, 'commission_rules' => null]);

    $rules = $this->service->getEffectiveRulesForEmployee($employee, 'estimator');

    expect($rules)->toHaveCount(1)
        ->and($rules[0]['source'])->toBe('estimate');
});

it('resolves manager_override role from territory manager', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id, 'commission_rules' => null]);

    SalesTerritory::create(['name' => 'Region A', 'level' => 'region', 'status' => 'active', 'manager_id' => $user->id]);

    $rules = $this->service->getEffectiveRulesForEmployee($employee);

    expect($rules)->toHaveCount(1)
        ->and($rules[0]['source'])->toBe('territory_override');
});

it('filters out inactive employee overrides', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'commission_rules' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 50, 'active' => false, 'auto_trigger' => true],
        ],
    ]);

    $territory = SalesTerritory::create(['name' => 'Test Block', 'level' => 'block', 'status' => 'active']);
    $territory->teamMembers()->attach($user->id, ['role' => 'knocker', 'added_by' => $user->id]);

    $rules = $this->service->getEffectiveRulesForEmployee($employee);

    // Inactive override is filtered, so company default remains
    $knockRule = collect($rules)->firstWhere('source', 'door_knock');
    expect($knockRule['amount'])->toBe(25);
});
