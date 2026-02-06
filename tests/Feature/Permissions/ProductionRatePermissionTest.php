<?php

use App\Models\EstimateTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('Production Rate Routes - Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $template = EstimateTemplate::factory()->create();

        $this->get(route('production-rates.index'))->assertRedirect(route('login'));
        $this->get(route('area-templates.index'))->assertRedirect(route('login'));
        $this->get(route('area-templates.create'))->assertRedirect(route('login'));
        $this->get(route('area-templates.edit', $template))->assertRedirect(route('login'));
    });
});

describe('Production Rate Routes - Without Permission', function () {
    it('forbids internal user without production_rates.view permission from production rates index', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('production-rates.index'))
            ->assertForbidden();
    });

    it('forbids internal user without production_rates.view permission from area templates index', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('area-templates.index'))
            ->assertForbidden();
    });

    it('forbids internal user without production_rates.view permission from area templates create', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('area-templates.create'))
            ->assertForbidden();
    });

    it('forbids internal user without production_rates.view permission from area templates edit', function () {
        $user = User::factory()->internal()->create();
        $template = EstimateTemplate::factory()->create();

        $this->actingAs($user)
            ->get(route('area-templates.edit', $template))
            ->assertForbidden();
    });
});

describe('Production Rate Routes - With View Only Permission', function () {
    it('allows internal user with production_rates.view permission to access production rates index', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('production_rates.view');

        $this->actingAs($user)
            ->get(route('production-rates.index'))
            ->assertSuccessful();
    });

    it('allows internal user with production_rates.view permission to access area templates index', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('production_rates.view');

        $this->actingAs($user)
            ->get(route('area-templates.index'))
            ->assertSuccessful();
    });

    it('forbids internal user with production_rates.view but without production_rates.manage from area templates create', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('production_rates.view');

        $this->actingAs($user)
            ->get(route('area-templates.create'))
            ->assertForbidden();
    });

    it('forbids internal user with production_rates.view but without production_rates.manage from area templates edit', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('production_rates.view');
        $template = EstimateTemplate::factory()->create();

        $this->actingAs($user)
            ->get(route('area-templates.edit', $template))
            ->assertForbidden();
    });
});

describe('Production Rate Routes - With View and Manage Permissions', function () {
    it('allows internal user with both permissions to access area templates create', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('production_rates.view');
        $user->givePermissionTo('production_rates.manage');

        $this->actingAs($user)
            ->get(route('area-templates.create'))
            ->assertSuccessful();
    });

    it('allows internal user with both permissions to access area templates edit', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('production_rates.view');
        $user->givePermissionTo('production_rates.manage');
        $template = EstimateTemplate::factory()->create();

        $this->actingAs($user)
            ->get(route('area-templates.edit', $template))
            ->assertSuccessful();
    });

    it('allows full access user to access all production rate routes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $template = EstimateTemplate::factory()->create();

        $this->actingAs($user)->get(route('production-rates.index'))->assertSuccessful();
        $this->actingAs($user)->get(route('area-templates.index'))->assertSuccessful();
        $this->actingAs($user)->get(route('area-templates.create'))->assertSuccessful();
        $this->actingAs($user)->get(route('area-templates.edit', $template))->assertSuccessful();
    });
});
