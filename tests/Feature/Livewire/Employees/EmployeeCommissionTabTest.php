<?php

use App\Models\CompanySetting;
use App\Models\EmployeeProfile;
use App\Models\SalesTerritory;
use App\Models\User;
use Livewire\Livewire;

it('shows territory role on commission tab', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $employee = EmployeeProfile::factory()->create();

    $territory = SalesTerritory::create(['name' => 'North Block', 'level' => 'block', 'status' => 'active']);
    $territory->teamMembers()->attach($employee->user_id, ['role' => 'knocker', 'added_by' => $admin->id]);

    Livewire::actingAs($admin)
        ->test('employees.show', ['employeeProfile' => $employee])
        ->call('setTab', 'commission')
        ->assertSee('Territory Role')
        ->assertSee('Knocker')
        ->assertSee('North Block');
});

it('shows company defaults as inherited rules', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $employee = EmployeeProfile::factory()->create();

    $territory = SalesTerritory::create(['name' => 'Test Block', 'level' => 'block', 'status' => 'active']);
    $territory->teamMembers()->attach($employee->user_id, ['role' => 'knocker', 'added_by' => $admin->id]);

    CompanySetting::getInstance()->update(['commission_defaults' => [
        'knocker' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 25, 'auto_trigger' => true],
        ],
    ]]);

    Livewire::actingAs($admin)
        ->test('employees.show', ['employeeProfile' => $employee])
        ->call('setTab', 'commission')
        ->assertSee('Company Defaults (Inherited)')
        ->assertSee('Door knock');
});

it('shows effective merged rules', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $employee = EmployeeProfile::factory()->create([
        'commission_rules' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 50, 'active' => true, 'auto_trigger' => true],
        ],
    ]);

    $territory = SalesTerritory::create(['name' => 'Test Block', 'level' => 'block', 'status' => 'active']);
    $territory->teamMembers()->attach($employee->user_id, ['role' => 'knocker', 'added_by' => $admin->id]);

    CompanySetting::getInstance()->update(['commission_defaults' => [
        'knocker' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 25, 'auto_trigger' => true],
            ['source' => 'job', 'type' => 'revenue_percentage', 'rate' => 0.04, 'auto_trigger' => true],
        ],
    ]]);

    Livewire::actingAs($admin)
        ->test('employees.show', ['employeeProfile' => $employee])
        ->call('setTab', 'commission')
        ->assertSee('Effective Rules')
        ->assertSee('Override')
        ->assertSee('Default');
});

it('allows door_knock and territory_override sources', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $employee = EmployeeProfile::factory()->create();

    Livewire::actingAs($admin)
        ->test('employees.show', ['employeeProfile' => $employee])
        ->call('setTab', 'commission')
        ->call('addCommissionRule')
        ->set('commissionRules.0.source', 'door_knock')
        ->set('commissionRules.0.type', 'flat')
        ->set('commissionRules.0.amount', '30')
        ->call('saveCommissionRules')
        ->assertHasNoErrors();

    expect($employee->fresh()->commission_rules[0]['source'])->toBe('door_knock');
});

it('saves override that replaces default for same source', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $employee = EmployeeProfile::factory()->create();

    $territory = SalesTerritory::create(['name' => 'Test Block', 'level' => 'block', 'status' => 'active']);
    $territory->teamMembers()->attach($employee->user_id, ['role' => 'knocker', 'added_by' => $admin->id]);

    CompanySetting::getInstance()->update(['commission_defaults' => [
        'knocker' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 25, 'auto_trigger' => true],
        ],
    ]]);

    $component = Livewire::actingAs($admin)
        ->test('employees.show', ['employeeProfile' => $employee])
        ->call('setTab', 'commission')
        ->call('addCommissionRule')
        ->set('commissionRules.0.source', 'door_knock')
        ->set('commissionRules.0.type', 'flat')
        ->set('commissionRules.0.amount', '50')
        ->call('saveCommissionRules')
        ->assertHasNoErrors();

    $savedRules = $employee->fresh()->commission_rules;
    expect($savedRules)->toHaveCount(1)
        ->and($savedRules[0]['amount'])->toBe(50);
});
