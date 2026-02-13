<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('Referrals Route - Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $this->get(route('referrals.index'))->assertRedirect(route('login'));
    });
});

describe('Referrals Route - Without Permission', function () {
    it('forbids internal user without referrals.view permission', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('referrals.index'))
            ->assertForbidden();
    });
});

describe('Referrals Route - With Permission', function () {
    it('allows internal user with referrals.view permission', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('referrals.view');

        $this->actingAs($user)
            ->get(route('referrals.index'))
            ->assertSuccessful();
    });

    it('allows full access user to access referrals', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user)
            ->get(route('referrals.index'))
            ->assertSuccessful();
    });
});
