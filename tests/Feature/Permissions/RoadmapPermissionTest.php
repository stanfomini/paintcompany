<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('Roadmap Routes - Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $this->get(route('roadmap.index'))->assertRedirect(route('login'));
    });
});

describe('Roadmap Routes - Without Permission', function () {
    it('forbids internal user without view roadmap permission from roadmap index', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('roadmap.index'))
            ->assertForbidden();
    });
});

describe('Roadmap Routes - With Permission', function () {
    it('allows internal user with view roadmap permission to access roadmap index', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('view roadmap');

        $this->actingAs($user)
            ->get(route('roadmap.index'))
            ->assertSuccessful();
    });

    it('allows full access user to access roadmap', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user)->get(route('roadmap.index'))->assertSuccessful();
    });
});
