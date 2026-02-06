<?php

use App\Models\Property;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('Properties Routes - Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $property = Property::factory()->create();

        $this->get(route('properties.index'))->assertRedirect(route('login'));
        $this->get(route('properties.show', $property))->assertRedirect(route('login'));
    });
});

describe('Properties Routes - Without Permission', function () {
    it('forbids internal user without properties.view permission from properties index', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('properties.index'))
            ->assertForbidden();
    });

    it('forbids internal user without properties.view permission from property show', function () {
        $user = User::factory()->internal()->create();
        $property = Property::factory()->create();

        $this->actingAs($user)
            ->get(route('properties.show', $property))
            ->assertForbidden();
    });
});

describe('Properties Routes - With Permission', function () {
    it('allows internal user with properties.view permission to access properties index', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('properties.view');

        $this->actingAs($user)
            ->get(route('properties.index'))
            ->assertSuccessful();
    });

    it('allows internal user with properties.view permission to access property show', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('properties.view');
        $property = Property::factory()->create();

        $this->actingAs($user)
            ->get(route('properties.show', $property))
            ->assertSuccessful();
    });

    it('allows full access user to access all properties routes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $property = Property::factory()->create();

        $this->actingAs($user)->get(route('properties.index'))->assertSuccessful();
        $this->actingAs($user)->get(route('properties.show', $property))->assertSuccessful();
    });
});
