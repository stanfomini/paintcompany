<?php

use App\Models\Deal;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('Deals Routes - Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $deal = Deal::factory()->create();

        $this->get(route('deals.index'))->assertRedirect(route('login'));
        $this->get(route('deals.show', $deal))->assertRedirect(route('login'));
    });
});

describe('Deals Routes - Without Permission', function () {
    it('forbids internal user without deals.view permission from deals index', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('deals.index'))
            ->assertForbidden();
    });

    it('forbids internal user without deals.view permission from deal show', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $this->actingAs($user)
            ->get(route('deals.show', $deal))
            ->assertForbidden();
    });
});

describe('Deals Routes - With Permission', function () {
    it('allows internal user with deals.view permission to access deals index', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('deals.view');

        $this->actingAs($user)
            ->get(route('deals.index'))
            ->assertSuccessful();
    });

    it('allows internal user with deals.view permission to access deal show', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('deals.view');
        $deal = Deal::factory()->create();

        $this->actingAs($user)
            ->get(route('deals.show', $deal))
            ->assertSuccessful();
    });

    it('allows full access user to access all deals routes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $this->actingAs($user)->get(route('deals.index'))->assertSuccessful();
        $this->actingAs($user)->get(route('deals.show', $deal))->assertSuccessful();
    });
});
