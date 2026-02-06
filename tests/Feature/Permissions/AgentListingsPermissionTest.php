<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('Agent Listings Routes - Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $this->get(route('agent-listings.index'))
            ->assertRedirect(route('login'));
    });
});

describe('Agent Listings Routes - Without Permission', function () {
    it('forbids internal user without agent_listings.view permission', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('agent-listings.index'))
            ->assertForbidden();
    });
});

describe('Agent Listings Routes - With Permission', function () {
    it('allows internal user with agent_listings.view permission to access agent listings', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('agent_listings.view');

        $this->actingAs($user)
            ->get(route('agent-listings.index'))
            ->assertSuccessful();
    });

    it('allows full access user to access agent listings', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user)
            ->get(route('agent-listings.index'))
            ->assertSuccessful();
    });
});
