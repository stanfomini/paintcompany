<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('Invitations Route - Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $this->get(route('invitations'))->assertRedirect(route('login'));
    });
});

describe('Invitations Route - Without Permission', function () {
    it('forbids internal user without team.manage_invitations permission', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('invitations'))
            ->assertForbidden();
    });

    it('redirects non-internal users', function () {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer)
            ->get(route('invitations'))
            ->assertRedirect();
    });
});

describe('Invitations Route - With Permission', function () {
    it('allows internal user with team.manage_invitations permission', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('team.manage_invitations');

        $this->actingAs($user)
            ->get(route('invitations'))
            ->assertSuccessful();
    });

    it('allows full access user to access invitations', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user)
            ->get(route('invitations'))
            ->assertSuccessful();
    });
});

describe('Agent Invitations - Independent Access', function () {
    it('allows agents to access their own invitations page', function () {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent, 'agent')
            ->get(route('agent.invitations'))
            ->assertSuccessful();
    });
});
