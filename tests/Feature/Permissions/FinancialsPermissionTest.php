<?php

use App\Models\Estimate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('Financials Routes - Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $estimate = Estimate::factory()->create();

        $this->get(route('estimates.breakdown', $estimate))->assertRedirect(route('login'));
        $this->get(route('estimates.breakdown.pdf', $estimate))->assertRedirect(route('login'));
        $this->get(route('estimates.breakdown.csv', $estimate))->assertRedirect(route('login'));
        $this->get(route('estimates.painter-breakdown', $estimate))->assertRedirect(route('login'));
        $this->get(route('estimates.painter-breakdown.pdf.work-order', $estimate))->assertRedirect(route('login'));
        $this->get(route('estimates.painter-breakdown.pdf.financials', $estimate))->assertRedirect(route('login'));
    });
});

describe('Financials Routes - Without Permission', function () {
    it('forbids internal user without financials.view_cost_breakdown from cost breakdown routes', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('estimates.view');
        $estimate = Estimate::factory()->create();

        $this->actingAs($user)
            ->get(route('estimates.breakdown', $estimate))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('estimates.breakdown.pdf', $estimate))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('estimates.breakdown.csv', $estimate))
            ->assertForbidden();
    });

    it('forbids internal user without financials.view_painter_breakdown from painter breakdown routes', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('estimates.view');
        $estimate = Estimate::factory()->create();

        $this->actingAs($user)
            ->get(route('estimates.painter-breakdown', $estimate))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('estimates.painter-breakdown.pdf.work-order', $estimate))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('estimates.painter-breakdown.pdf.financials', $estimate))
            ->assertForbidden();
    });
});

describe('Financials Routes - With Permission', function () {
    it('allows internal user with financials.view_cost_breakdown to access cost breakdown', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('estimates.view', 'financials.view_cost_breakdown');
        $estimate = Estimate::factory()->create();

        $this->actingAs($user)
            ->get(route('estimates.breakdown', $estimate))
            ->assertSuccessful();
    });

    it('allows internal user with financials.view_painter_breakdown to access painter breakdown', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('estimates.view', 'financials.view_painter_breakdown');
        $estimate = Estimate::factory()->create();

        $this->actingAs($user)
            ->get(route('estimates.painter-breakdown', $estimate))
            ->assertSuccessful();
    });

    it('allows full access user to access all financials routes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        $this->actingAs($user)->get(route('estimates.breakdown', $estimate))->assertSuccessful();
        $this->actingAs($user)->get(route('estimates.painter-breakdown', $estimate))->assertSuccessful();
    });
});
