<?php

use App\Models\Customer;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('People Routes - Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $customer = Customer::factory()->create();
        $agent = User::factory()->agent()->create();
        $crew = User::factory()->crew()->create();

        $this->get(route('people.index'))->assertRedirect(route('login'));
        $this->get(route('customers.index'))->assertRedirect(route('login'));
        $this->get(route('customers.show', $customer))->assertRedirect(route('login'));
        $this->get(route('agents.index'))->assertRedirect(route('login'));
        $this->get(route('agents.show', $agent))->assertRedirect(route('login'));
        $this->get(route('crews.index'))->assertRedirect(route('login'));
        $this->get(route('crews.show', $crew))->assertRedirect(route('login'));
    });
});

describe('People Routes - Without Permission', function () {
    it('forbids internal user without people.view permission from people index', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('people.index'))
            ->assertForbidden();
    });

    it('forbids internal user without people.view permission from customers index', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('customers.index'))
            ->assertForbidden();
    });

    it('forbids internal user without people.view permission from customer show', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        $this->actingAs($user)
            ->get(route('customers.show', $customer))
            ->assertForbidden();
    });

    it('forbids internal user without people.view permission from agents index', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('agents.index'))
            ->assertForbidden();
    });

    it('forbids internal user without people.view permission from agent show', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();

        $this->actingAs($user)
            ->get(route('agents.show', $agent))
            ->assertForbidden();
    });

    it('forbids internal user without people.view permission from crews index', function () {
        $user = User::factory()->internal()->create();

        $this->actingAs($user)
            ->get(route('crews.index'))
            ->assertForbidden();
    });

    it('forbids internal user without people.view permission from crew show', function () {
        $user = User::factory()->internal()->create();
        $crew = User::factory()->crew()->create();

        $this->actingAs($user)
            ->get(route('crews.show', $crew))
            ->assertForbidden();
    });
});

describe('People Routes - With Permission', function () {
    it('allows internal user with people.view permission to access people index', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('people.view');

        $this->actingAs($user)
            ->get(route('people.index'))
            ->assertSuccessful();
    });

    it('allows internal user with people.view permission to access customers index', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('people.view');

        $this->actingAs($user)
            ->get(route('customers.index'))
            ->assertSuccessful();
    });

    it('allows internal user with people.view permission to access customer show', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('people.view');
        $customer = Customer::factory()->create();

        $this->actingAs($user)
            ->get(route('customers.show', $customer))
            ->assertSuccessful();
    });

    it('allows internal user with people.view permission to access agents index', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('people.view');

        $this->actingAs($user)
            ->get(route('agents.index'))
            ->assertSuccessful();
    });

    it('allows internal user with people.view permission to access agent show', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('people.view');
        $agent = User::factory()->agent()->create();

        $this->actingAs($user)
            ->get(route('agents.show', $agent))
            ->assertSuccessful();
    });

    it('allows internal user with people.view permission to access crews index', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('people.view');

        $this->actingAs($user)
            ->get(route('crews.index'))
            ->assertSuccessful();
    });

    it('allows internal user with people.view permission to access crew show', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('people.view');
        $crew = User::factory()->crew()->create();

        $this->actingAs($user)
            ->get(route('crews.show', $crew))
            ->assertSuccessful();
    });

    it('allows full access user to access all people routes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $agent = User::factory()->agent()->create();
        $crew = User::factory()->crew()->create();

        $this->actingAs($user)->get(route('people.index'))->assertSuccessful();
        $this->actingAs($user)->get(route('customers.index'))->assertSuccessful();
        $this->actingAs($user)->get(route('customers.show', $customer))->assertSuccessful();
        $this->actingAs($user)->get(route('agents.index'))->assertSuccessful();
        $this->actingAs($user)->get(route('agents.show', $agent))->assertSuccessful();
        $this->actingAs($user)->get(route('crews.index'))->assertSuccessful();
        $this->actingAs($user)->get(route('crews.show', $crew))->assertSuccessful();
    });
});
