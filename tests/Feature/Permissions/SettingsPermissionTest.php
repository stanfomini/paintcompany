<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('Settings Routes - Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $this->get(route('company.edit'))->assertRedirect(route('login'));
        $this->get(route('packages.edit'))->assertRedirect(route('login'));
        $this->get(route('allocations.edit'))->assertRedirect(route('login'));
        $this->get(route('roles.edit'))->assertRedirect(route('login'));
        $this->get(route('service-categories.edit'))->assertRedirect(route('login'));
        $this->get(route('budget-mappings.edit'))->assertRedirect(route('login'));
        $this->get(route('referral.edit'))->assertRedirect(route('login'));
        $this->get(route('contracts.edit'))->assertRedirect(route('login'));
        $this->get(route('payment-integrations.edit'))->assertRedirect(route('login'));
    });
});

describe('Settings Routes - Without Permission', function () {
    it('forbids internal user without settings.company permission from company settings', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('company.edit'))
            ->assertForbidden();
    });

    it('forbids internal user without settings.packages permission from packages settings', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('packages.edit'))
            ->assertForbidden();
    });

    it('forbids internal user without settings.allocations permission from allocations settings', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('allocations.edit'))
            ->assertForbidden();
    });

    it('forbids internal user without team.manage_roles permission from roles settings', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('roles.edit'))
            ->assertForbidden();
    });

    it('forbids internal user without settings.payment_integrations permission', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('payment-integrations.edit'))
            ->assertForbidden();
    });

    it('forbids internal user without settings.service_categories permission', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('service-categories.edit'))
            ->assertForbidden();
    });

    it('forbids internal user without settings.budget_mappings permission', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('budget-mappings.edit'))
            ->assertForbidden();
    });

    it('forbids internal user without settings.referral permission', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('referral.edit'))
            ->assertForbidden();
    });

    it('forbids internal user without settings.contracts permission', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('contracts.edit'))
            ->assertForbidden();
    });
});

describe('Settings Routes - With Permission', function () {
    it('allows internal user with settings.company permission to access company settings', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('settings.company');

        $this->actingAs($user)
            ->get(route('company.edit'))
            ->assertSuccessful();
    });

    it('allows internal user with settings.packages permission to access packages settings', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('settings.packages');

        $this->actingAs($user)
            ->get(route('packages.edit'))
            ->assertSuccessful();
    });

    it('allows internal user with settings.allocations permission to access allocations settings', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('settings.allocations');

        $this->actingAs($user)
            ->get(route('allocations.edit'))
            ->assertSuccessful();
    });

    it('allows internal user with team.manage_roles permission to access roles settings', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('team.manage_roles');

        $this->actingAs($user)
            ->get(route('roles.edit'))
            ->assertSuccessful();
    });

    it('allows internal user with settings.payment_integrations permission', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('settings.payment_integrations');

        $this->actingAs($user)
            ->get(route('payment-integrations.edit'))
            ->assertSuccessful();
    });

    it('allows internal user with settings.service_categories permission', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('settings.service_categories');

        $this->actingAs($user)
            ->get(route('service-categories.edit'))
            ->assertSuccessful();
    });

    it('allows internal user with settings.budget_mappings permission', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('settings.budget_mappings');

        $this->actingAs($user)
            ->get(route('budget-mappings.edit'))
            ->assertSuccessful();
    });

    it('allows internal user with settings.referral permission', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('settings.referral');

        $this->actingAs($user)
            ->get(route('referral.edit'))
            ->assertSuccessful();
    });

    it('allows internal user with settings.contracts permission', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('settings.contracts');

        $this->actingAs($user)
            ->get(route('contracts.edit'))
            ->assertSuccessful();
    });

    it('allows full access user to access all settings routes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user)->get(route('company.edit'))->assertSuccessful();
        $this->actingAs($user)->get(route('packages.edit'))->assertSuccessful();
        $this->actingAs($user)->get(route('allocations.edit'))->assertSuccessful();
        $this->actingAs($user)->get(route('roles.edit'))->assertSuccessful();
        $this->actingAs($user)->get(route('payment-integrations.edit'))->assertSuccessful();
        $this->actingAs($user)->get(route('service-categories.edit'))->assertSuccessful();
        $this->actingAs($user)->get(route('budget-mappings.edit'))->assertSuccessful();
        $this->actingAs($user)->get(route('referral.edit'))->assertSuccessful();
        $this->actingAs($user)->get(route('contracts.edit'))->assertSuccessful();
    });
});

describe('Settings Routes - Granular Access Control', function () {
    it('allows user with only settings.contracts but not settings.company', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('settings.contracts');

        $this->actingAs($user)
            ->get(route('contracts.edit'))
            ->assertSuccessful();

        $this->actingAs($user)
            ->get(route('company.edit'))
            ->assertForbidden();
    });

    it('allows user with only settings.service_categories but not settings.company', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('settings.service_categories');

        $this->actingAs($user)
            ->get(route('service-categories.edit'))
            ->assertSuccessful();

        $this->actingAs($user)
            ->get(route('company.edit'))
            ->assertForbidden();
    });
});
