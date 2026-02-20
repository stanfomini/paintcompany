<?php

use App\Models\EmployeeProfile;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    // Seed all permissions and roles
    if (Permission::count() === 0) {
        (new \Database\Seeders\RolesAndPermissionsSeeder)->run();
    }
});

// ──────────────────────────────────────────────────
// Onboarding permissions exist
// ──────────────────────────────────────────────────

it('has onboarding.view permission in the database', function () {
    expect(Permission::where('name', 'onboarding.view')->exists())->toBeTrue();
});

it('has onboarding.manage permission in the database', function () {
    expect(Permission::where('name', 'onboarding.manage')->exists())->toBeTrue();
});

// ──────────────────────────────────────────────────
// Onboarding route access
// ──────────────────────────────────────────────────

it('requires authentication to access onboarding index', function () {
    $this->get(route('onboarding.index'))
        ->assertRedirect(route('login'));
});

it('allows users with onboarding.manage to access onboarding index', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo(['onboarding.view', 'onboarding.manage']);

    $this->actingAs($user)
        ->get(route('onboarding.index'))
        ->assertOk();
});

it('forbids users without onboarding.view from accessing onboarding index', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('onboarding.index'))
        ->assertForbidden();
});

it('allows users with onboarding.view to access employee onboarding progress', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('onboarding.view');

    $employee = EmployeeProfile::factory()->create();

    $this->actingAs($user)
        ->get(route('onboarding.progress', $employee))
        ->assertOk();
});

it('forbids users without onboarding.view from employee onboarding progress', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create();

    $this->actingAs($user)
        ->get(route('onboarding.progress', $employee))
        ->assertForbidden();
});

// ──────────────────────────────────────────────────
// Role-based permission grants
// ──────────────────────────────────────────────────

describe('admin role', function () {
    it('has onboarding.view permission', function () {
        $role = Role::findByName('admin', 'web');

        expect($role->hasPermissionTo('onboarding.view'))->toBeTrue();
    });

    it('has onboarding.manage permission', function () {
        $role = Role::findByName('admin', 'web');

        expect($role->hasPermissionTo('onboarding.manage'))->toBeTrue();
    });
});

describe('manager role', function () {
    it('has onboarding.view permission', function () {
        $role = Role::findByName('manager', 'web');

        expect($role->hasPermissionTo('onboarding.view'))->toBeTrue();
    });

    it('has onboarding.manage permission', function () {
        $role = Role::findByName('manager', 'web');

        expect($role->hasPermissionTo('onboarding.manage'))->toBeTrue();
    });
});

describe('owner role', function () {
    it('has onboarding.view permission', function () {
        $role = Role::findByName('owner', 'web');

        expect($role->hasPermissionTo('onboarding.view'))->toBeTrue();
    });

    it('has onboarding.manage permission', function () {
        $role = Role::findByName('owner', 'web');

        expect($role->hasPermissionTo('onboarding.manage'))->toBeTrue();
    });
});

// ──────────────────────────────────────────────────
// Estimator role - tightened permissions
// ──────────────────────────────────────────────────

describe('estimator role', function () {
    it('does not have employees.view permission', function () {
        $role = Role::findByName('estimator', 'web');

        expect($role->hasPermissionTo('employees.view'))->toBeFalse();
    });

    it('does not have time_tracking.view permission', function () {
        $role = Role::findByName('estimator', 'web');

        expect($role->hasPermissionTo('time_tracking.view'))->toBeFalse();
    });

    it('does not have time_tracking.clock permission', function () {
        $role = Role::findByName('estimator', 'web');

        expect($role->hasPermissionTo('time_tracking.clock'))->toBeFalse();
    });

    it('does not have scheduling.view permission', function () {
        $role = Role::findByName('estimator', 'web');

        expect($role->hasPermissionTo('scheduling.view'))->toBeFalse();
    });

    it('cannot access employees index', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('estimator');

        $this->actingAs($user)
            ->get(route('employees.index'))
            ->assertForbidden();
    });

    it('cannot access time tracking dashboard', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('estimator');

        $this->actingAs($user)
            ->get(route('time-tracking.index'))
            ->assertForbidden();
    });

    it('cannot access scheduling page', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('estimator');

        $this->actingAs($user)
            ->get(route('scheduling.index'))
            ->assertForbidden();
    });
});

// ──────────────────────────────────────────────────
// Self-service routes (no permission required, just auth)
// ──────────────────────────────────────────────────

describe('self-service routes', function () {
    it('allows estimator to access /my/timesheet', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('estimator');
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('my.timesheet'))
            ->assertOk();
    });

    it('allows estimator to access /my/schedule', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('estimator');
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('my.schedule'))
            ->assertOk();
    });

    it('allows manager to access /my/timesheet', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('manager');
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('my.timesheet'))
            ->assertOk();
    });

    it('allows manager to access /my/schedule', function () {
        $user = User::factory()->internal()->create();
        $user->assignRole('manager');
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('my.schedule'))
            ->assertOk();
    });

    it('allows any internal user to access /my/onboarding', function () {
        $user = User::factory()->internal()->create();
        EmployeeProfile::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('my.onboarding'))
            ->assertOk();
    });
});

// ──────────────────────────────────────────────────
// Permission groups in roles page
// ──────────────────────────────────────────────────

it('includes Onboarding group in roles page permission matrix', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $user->assignRole('owner');

    Livewire::actingAs($user)
        ->test('pages::settings.roles')
        ->set('activeTab', 'matrix')
        ->assertSee('Onboarding')
        ->assertSee('onboarding.view')
        ->assertSee('onboarding.manage');
});
