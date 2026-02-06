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

    it('forbids internal user without manage roles permission from roles settings', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('roles.edit'))
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

    it('allows internal user with manage roles permission to access roles settings', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('manage roles');

        $this->actingAs($user)
            ->get(route('roles.edit'))
            ->assertSuccessful();
    });

    it('allows full access user to access all settings routes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user)->get(route('company.edit'))->assertSuccessful();
        $this->actingAs($user)->get(route('packages.edit'))->assertSuccessful();
        $this->actingAs($user)->get(route('allocations.edit'))->assertSuccessful();
        $this->actingAs($user)->get(route('roles.edit'))->assertSuccessful();
    });
});
