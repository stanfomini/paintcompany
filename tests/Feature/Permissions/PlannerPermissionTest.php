<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('Planner Routes - Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $this->get(route('planner.index'))
            ->assertRedirect(route('login'));
    });
});

describe('Planner Routes - Without Permission', function () {
    it('forbids internal user without planner.view permission', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('planner.index'))
            ->assertForbidden();
    });
});

describe('Planner Routes - With Permission', function () {
    it('allows internal user with planner.view permission to access planner', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('planner.view');

        $this->actingAs($user)
            ->get(route('planner.index'))
            ->assertSuccessful();
    });

    it('allows full access user to access planner', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user)
            ->get(route('planner.index'))
            ->assertSuccessful();
    });
});
