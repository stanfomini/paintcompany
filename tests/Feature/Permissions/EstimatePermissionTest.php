<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

it('redirects unauthenticated users away from estimate routes', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create(['created_by' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $user->id,
    ]);

    $this->get(route('estimates.index'))->assertRedirect(route('login'));
    $this->get(route('estimates.show', $estimate))->assertRedirect(route('login'));
    $this->get(route('estimates.pdf', $estimate))->assertRedirect(route('login'));
    $this->get(route('estimates.create', $deal))->assertRedirect(route('login'));
    $this->get(route('estimates.breakdown', $estimate))->assertRedirect(route('login'));
    $this->get(route('estimates.breakdown.pdf', $estimate))->assertRedirect(route('login'));
    $this->get(route('estimates.breakdown.csv', $estimate))->assertRedirect(route('login'));
    $this->get(route('estimates.painter-breakdown', $estimate))->assertRedirect(route('login'));
});

it('returns 403 for internal user without estimates.view on index', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('estimates.index'))
        ->assertForbidden();
});

it('allows user with estimates.view to access index', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('estimates.view');

    $this->actingAs($user)
        ->get(route('estimates.index'))
        ->assertOk();
});

it('allows user with estimates.view to access show', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('estimates.view');

    $deal = Deal::factory()->create(['created_by' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('estimates.show', $estimate))
        ->assertOk();
});

it('returns 403 for user with estimates.view but not estimates.create on create route', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('estimates.view');

    $deal = Deal::factory()->create(['created_by' => $user->id]);

    $this->actingAs($user)
        ->get(route('estimates.create', $deal))
        ->assertForbidden();
});

it('allows user with estimates.view and estimates.create to access create route', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo(['estimates.view', 'estimates.create']);

    $deal = Deal::factory()->create(['created_by' => $user->id]);

    $this->actingAs($user)
        ->get(route('estimates.create', $deal))
        ->assertOk();
});

it('returns 403 for user with estimates.view but not financials.view_cost_breakdown on breakdown routes', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('estimates.view');

    $deal = Deal::factory()->create(['created_by' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $user->id,
    ]);

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

it('returns 403 for user with estimates.view but not financials.view_painter_breakdown on painter breakdown route', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('estimates.view');

    $deal = Deal::factory()->create(['created_by' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('estimates.painter-breakdown', $estimate))
        ->assertForbidden();
});

it('allows user with estimates.view and financials.view_cost_breakdown to access breakdown routes', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo(['estimates.view', 'financials.view_cost_breakdown']);

    $deal = Deal::factory()->create(['created_by' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('estimates.breakdown', $estimate))
        ->assertOk();
});

it('allows user with all permissions to access every estimate route', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $deal = Deal::factory()->create(['created_by' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('estimates.index'))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('estimates.show', $estimate))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('estimates.create', $deal))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('estimates.breakdown', $estimate))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('estimates.painter-breakdown', $estimate))
        ->assertOk();
});
