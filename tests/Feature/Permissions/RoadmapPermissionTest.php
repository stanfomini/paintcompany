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

describe('Roadmap Routes - Legacy Redirects', function () {
    it('redirects legacy roadmap URL to business plan', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('roadmap.index'))
            ->assertRedirect(route('business-plan.index', ['tab' => 'roadmap']));
    });
});

describe('Roadmap Routes - With Permission', function () {
    it('redirects legacy roadmap URL for authorized users', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('roadmap.view');

        $this->actingAs($user)
            ->get(route('roadmap.index'))
            ->assertRedirect(route('business-plan.index', ['tab' => 'roadmap']));
    });

    it('redirects legacy roadmap URL for full access user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user)->get(route('roadmap.index'))->assertRedirect(route('business-plan.index', ['tab' => 'roadmap']));
    });
});
