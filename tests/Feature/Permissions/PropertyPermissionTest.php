<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\User;

it('redirects unauthenticated users to login for properties index', function () {
    $this->get(route('properties.index'))
        ->assertRedirect(route('login'));
});

it('redirects unauthenticated users to login for properties show', function () {
    $property = Property::factory()->create();

    $this->get(route('properties.show', $property))
        ->assertRedirect(route('login'));
});

it('forbids internal user without properties.view permission from accessing index', function () {
    $user = User::factory()->internal()->create();

    $seeder = new \Database\Seeders\RolesAndPermissionsSeeder;
    $seeder->run();

    $this->actingAs($user)
        ->get(route('properties.index'))
        ->assertForbidden();
});

it('forbids internal user without properties.view permission from accessing show', function () {
    $user = User::factory()->internal()->create();
    $property = Property::factory()->create();

    $seeder = new \Database\Seeders\RolesAndPermissionsSeeder;
    $seeder->run();

    $this->actingAs($user)
        ->get(route('properties.show', $property))
        ->assertForbidden();
});

it('allows internal user with properties.view permission to access index', function () {
    $user = User::factory()->internal()->create();

    $seeder = new \Database\Seeders\RolesAndPermissionsSeeder;
    $seeder->run();

    $user->givePermissionTo('properties.view');

    $this->actingAs($user)
        ->get(route('properties.index'))
        ->assertOk();
});

it('allows internal user with properties.view permission to access show', function () {
    $user = User::factory()->internal()->create();

    $seeder = new \Database\Seeders\RolesAndPermissionsSeeder;
    $seeder->run();

    $user->givePermissionTo('properties.view');

    $customer = Customer::factory()->createdBy($user)->create();
    $property = Property::factory()->forCustomer($customer)->create();

    $this->actingAs($user)
        ->get(route('properties.show', $property))
        ->assertOk();
});
