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

describe('KPI Routes - Legacy Redirects', function () {
    it('redirects legacy sales KPI URL to business plan', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('kpis.sales'))
            ->assertRedirect(route('business-plan.index', ['tab' => 'sales-plan']));
    });

    it('redirects legacy production KPI URL to business plan', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('kpis.production'))
            ->assertRedirect(route('business-plan.index', ['tab' => 'production-plan']));
    });
});

describe('KPI Routes - With Permission', function () {
    it('redirects legacy sales KPI URL for authorized users', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('view kpis');

        $this->actingAs($user)
            ->get(route('kpis.sales'))
            ->assertRedirect(route('business-plan.index', ['tab' => 'sales-plan']));
    });

    it('redirects legacy production KPI URL for authorized users', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('view kpis');

        $this->actingAs($user)
            ->get(route('kpis.production'))
            ->assertRedirect(route('business-plan.index', ['tab' => 'production-plan']));
    });

    it('redirects all legacy KPI routes for full access user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($user)->get(route('kpis.sales'))->assertRedirect(route('business-plan.index', ['tab' => 'sales-plan']));
        $this->actingAs($user)->get(route('kpis.production'))->assertRedirect(route('business-plan.index', ['tab' => 'production-plan']));
    });
});
