<?php

use App\Models\EmployeeProfile;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    if (Permission::count() === 0) {
        (new \Database\Seeders\RolesAndPermissionsSeeder)->run();
    }

    $this->hrManager = User::factory()->internal()->withRole('hr_manager')->create();
});

// ──────────────────────────────────────────────────
// HR Manager role exists with correct permissions
// ──────────────────────────────────────────────────

it('has hr_manager role in the database', function () {
    $role = Role::findByName('hr_manager', 'web');

    expect($role)->not->toBeNull();
});

describe('hr_manager role has correct permissions', function () {
    it('has employee management permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('employees.view'))->toBeTrue()
            ->and($role->hasPermissionTo('employees.create'))->toBeTrue()
            ->and($role->hasPermissionTo('employees.edit'))->toBeTrue()
            ->and($role->hasPermissionTo('employees.delete'))->toBeTrue()
            ->and($role->hasPermissionTo('employees.view_sensitive'))->toBeTrue();
    });

    it('has hiring permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('hiring.view'))->toBeTrue()
            ->and($role->hasPermissionTo('hiring.manage'))->toBeTrue();
    });

    it('has time tracking permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('time_tracking.view'))->toBeTrue()
            ->and($role->hasPermissionTo('time_tracking.view_all'))->toBeTrue()
            ->and($role->hasPermissionTo('time_tracking.manage'))->toBeTrue()
            ->and($role->hasPermissionTo('time_tracking.clock'))->toBeTrue();
    });

    it('has scheduling permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('scheduling.view'))->toBeTrue()
            ->and($role->hasPermissionTo('scheduling.view_all'))->toBeTrue()
            ->and($role->hasPermissionTo('scheduling.manage'))->toBeTrue();
    });

    it('has payroll permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('payroll.view'))->toBeTrue()
            ->and($role->hasPermissionTo('payroll.manage'))->toBeTrue();
    });

    it('has onboarding permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('onboarding.view'))->toBeTrue()
            ->and($role->hasPermissionTo('onboarding.manage'))->toBeTrue();
    });

    it('has contract settings permission', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('settings.contracts'))->toBeTrue();
    });

    it('has team management permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('team.manage_users'))->toBeTrue()
            ->and($role->hasPermissionTo('team.manage_invitations'))->toBeTrue();
    });

    it('has chat permissions for internal and crew', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('chat.access'))->toBeTrue()
            ->and($role->hasPermissionTo('chat.with_internal'))->toBeTrue()
            ->and($role->hasPermissionTo('chat.with_crew'))->toBeTrue();
    });

    it('has planner permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('planner.view'))->toBeTrue()
            ->and($role->hasPermissionTo('planner.manage'))->toBeTrue();
    });
});

// ──────────────────────────────────────────────────
// HR Manager does NOT have restricted permissions
// ──────────────────────────────────────────────────

describe('hr_manager role lacks restricted permissions', function () {
    it('does not have estimate permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('estimates.view'))->toBeFalse()
            ->and($role->hasPermissionTo('estimates.create'))->toBeFalse()
            ->and($role->hasPermissionTo('estimates.edit'))->toBeFalse()
            ->and($role->hasPermissionTo('estimates.delete'))->toBeFalse()
            ->and($role->hasPermissionTo('estimates.send'))->toBeFalse();
    });

    it('does not have financial permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('financials.view_cost_breakdown'))->toBeFalse()
            ->and($role->hasPermissionTo('financials.view_painter_breakdown'))->toBeFalse()
            ->and($role->hasPermissionTo('financials.manage_pricing'))->toBeFalse();
    });

    it('does not have company settings permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('settings.company'))->toBeFalse()
            ->and($role->hasPermissionTo('settings.packages'))->toBeFalse()
            ->and($role->hasPermissionTo('settings.allocations'))->toBeFalse()
            ->and($role->hasPermissionTo('settings.payment_integrations'))->toBeFalse();
    });

    it('does not have role management permission', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('team.manage_roles'))->toBeFalse();
    });

    it('does not have production rates permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('production_rates.view'))->toBeFalse()
            ->and($role->hasPermissionTo('production_rates.manage'))->toBeFalse();
    });

    it('does not have business plan permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('business_plan.view'))->toBeFalse()
            ->and($role->hasPermissionTo('business_plan.manage'))->toBeFalse();
    });

    it('does not have kpi management permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('kpis.edit_targets'))->toBeFalse()
            ->and($role->hasPermissionTo('kpis.manage'))->toBeFalse();
    });

    it('does not have roadmap management permissions', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('roadmap.edit'))->toBeFalse()
            ->and($role->hasPermissionTo('roadmap.manage'))->toBeFalse();
    });

    it('does not have deals delete permission', function () {
        $role = Role::findByName('hr_manager', 'web');

        expect($role->hasPermissionTo('deals.delete'))->toBeFalse();
    });
});

// ──────────────────────────────────────────────────
// HR Manager route access - allowed
// ──────────────────────────────────────────────────

describe('hr_manager can access allowed routes', function () {
    it('can access employees index', function () {
        $this->actingAs($this->hrManager)
            ->get(route('employees.index'))
            ->assertOk();
    });

    it('can access employee show page', function () {
        $employee = EmployeeProfile::factory()->create();

        $this->actingAs($this->hrManager)
            ->get(route('employees.show', $employee))
            ->assertOk();
    });

    it('can access hiring index', function () {
        $this->actingAs($this->hrManager)
            ->get(route('hiring.index'))
            ->assertOk();
    });

    it('can access time tracking index', function () {
        $this->actingAs($this->hrManager)
            ->get(route('time-tracking.index'))
            ->assertOk();
    });

    it('can access scheduling index', function () {
        $this->actingAs($this->hrManager)
            ->get(route('scheduling.index'))
            ->assertOk();
    });

    it('can access payroll index', function () {
        $this->actingAs($this->hrManager)
            ->get(route('payroll.index'))
            ->assertOk();
    });

    it('can access document center', function () {
        $this->actingAs($this->hrManager)
            ->get(route('document-center'))
            ->assertOk();
    });

    it('can access onboarding index', function () {
        $this->actingAs($this->hrManager)
            ->get(route('onboarding.index'))
            ->assertOk();
    });

    it('can access contract templates', function () {
        $this->actingAs($this->hrManager)
            ->get(route('contracts.templates'))
            ->assertOk();
    });
});

// ──────────────────────────────────────────────────
// HR Manager route access - denied
// ──────────────────────────────────────────────────

describe('hr_manager cannot access restricted routes', function () {
    it('cannot access estimates index', function () {
        $this->actingAs($this->hrManager)
            ->get(route('estimates.index'))
            ->assertForbidden();
    });

    it('cannot access company settings', function () {
        $this->actingAs($this->hrManager)
            ->get(route('company.edit'))
            ->assertForbidden();
    });

    it('cannot access roles settings', function () {
        $this->actingAs($this->hrManager)
            ->get(route('roles.edit'))
            ->assertForbidden();
    });

    it('cannot access production rates', function () {
        $this->actingAs($this->hrManager)
            ->get(route('production-rates.index'))
            ->assertForbidden();
    });
});
