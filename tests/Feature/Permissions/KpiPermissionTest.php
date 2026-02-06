<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('KPI Routes - Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $this->get(route('kpis.sales'))->assertRedirect(route('login'));
        $this->get(route('kpis.production'))->assertRedirect(route('login'));
    });
});

describe('KPI Routes - Without Permission', function () {
    it('forbids internal user without view kpis permission from sales KPIs', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('kpis.sales'))
            ->assertForbidden();
    });

    it('forbids internal user without view kpis permission from production KPIs', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('kpis.production'))
            ->assertForbidden();
    });
});

describe('KPI Routes - With Permission', function () {
    it('allows internal user with view kpis permission to access sales KPIs', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('view kpis');

        $this->actingAs($user)
            ->get(route('kpis.sales'))
            ->assertSuccessful();
    });

    it('allows internal user with view kpis permission to access production KPIs', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('view kpis');

        $this->actingAs($user)
            ->get(route('kpis.production'))
            ->assertSuccessful();
    });

    it('allows full access user to access all KPI routes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user)->get(route('kpis.sales'))->assertSuccessful();
        $this->actingAs($user)->get(route('kpis.production'))->assertSuccessful();
    });
});
