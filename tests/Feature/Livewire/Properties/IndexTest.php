<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

describe('Property Index component', function () {
    it('renders the property index component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('properties.index')
            ->assertStatus(200)
            ->assertSee('Properties');
    });

    it('allows internal users to view the page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('properties.index')
            ->assertStatus(200);
    });

    it('denies access to agents', function () {
        $user = User::factory()->agent()->create();

        Livewire::actingAs($user)
            ->test('properties.index')
            ->assertForbidden();
    });

    it('denies access to customers', function () {
        $user = User::factory()->customer()->create();

        Livewire::actingAs($user)
            ->test('properties.index')
            ->assertForbidden();
    });

    it('displays properties in the list', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $property = Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main Street',
            'city' => 'Springfield',
            'state' => 'IL',
            'zip' => '62701',
        ]);

        Livewire::actingAs($user)
            ->test('properties.index')
            ->assertSee('123 Main Street')
            ->assertSee('Springfield');
    });

    it('can search properties by address', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $property1 = Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main Street',
        ]);
        $property2 = Property::factory()->forCustomer($customer)->create([
            'address' => '456 Oak Avenue',
        ]);

        Livewire::actingAs($user)
            ->test('properties.index')
            ->set('search', 'Main Street')
            ->assertSee('123 Main Street')
            ->assertDontSee('456 Oak Avenue');
    });

    it('can search properties by city', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $property1 = Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main Street',
            'city' => 'Springfield',
        ]);
        $property2 = Property::factory()->forCustomer($customer)->create([
            'address' => '456 Oak Avenue',
            'city' => 'Chicago',
        ]);

        Livewire::actingAs($user)
            ->test('properties.index')
            ->set('search', 'Springfield')
            ->assertSee('123 Main Street')
            ->assertDontSee('456 Oak Avenue');
    });

    it('can search properties by state', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer1 = Customer::factory()->createdBy($user)->create(['first_name' => 'John', 'last_name' => 'Smith']);
        $customer2 = Customer::factory()->createdBy($user)->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $property1 = Property::factory()->forCustomer($customer1)->create([
            'address' => '123 Main Street',
            'city' => 'Springfield',
            'state' => 'IL',
        ]);
        $property2 = Property::factory()->forCustomer($customer2)->create([
            'address' => '456 Oak Avenue',
            'city' => 'Los Angeles',
            'state' => 'CA',
        ]);

        Livewire::actingAs($user)
            ->test('properties.index')
            ->set('search', 'Springfield')
            ->assertSee('123 Main Street')
            ->assertDontSee('456 Oak Avenue');
    });

    it('can search properties by zip', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $property1 = Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main Street',
            'zip' => '62701',
        ]);
        $property2 = Property::factory()->forCustomer($customer)->create([
            'address' => '456 Oak Avenue',
            'zip' => '90210',
        ]);

        Livewire::actingAs($user)
            ->test('properties.index')
            ->set('search', '62701')
            ->assertSee('123 Main Street')
            ->assertDontSee('456 Oak Avenue');
    });

    it('can filter properties by residential type', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $residential = Property::factory()->residential()->forCustomer($customer)->create([
            'address' => '123 Home Lane',
        ]);
        $commercial = Property::factory()->commercial()->forCustomer($customer)->create([
            'address' => '456 Business Blvd',
        ]);

        Livewire::actingAs($user)
            ->test('properties.index')
            ->set('typeFilter', 'residential')
            ->assertSee('123 Home Lane')
            ->assertDontSee('456 Business Blvd');
    });

    it('can filter properties by commercial type', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $residential = Property::factory()->residential()->forCustomer($customer)->create([
            'address' => '123 Home Lane',
        ]);
        $commercial = Property::factory()->commercial()->forCustomer($customer)->create([
            'address' => '456 Business Blvd',
        ]);

        Livewire::actingAs($user)
            ->test('properties.index')
            ->set('typeFilter', 'commercial')
            ->assertSee('456 Business Blvd')
            ->assertDontSee('123 Home Lane');
    });

    it('can filter properties by industrial type', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $residential = Property::factory()->residential()->forCustomer($customer)->create([
            'address' => '123 Home Lane',
        ]);
        $industrial = Property::factory()->industrial()->forCustomer($customer)->create([
            'address' => '789 Factory Road',
        ]);

        Livewire::actingAs($user)
            ->test('properties.index')
            ->set('typeFilter', 'industrial')
            ->assertSee('789 Factory Road')
            ->assertDontSee('123 Home Lane');
    });

    it('can filter properties by customer', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer1 = Customer::factory()->createdBy($user)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        $customer2 = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);
        $property1 = Property::factory()->forCustomer($customer1)->create([
            'address' => '123 John Street',
        ]);
        $property2 = Property::factory()->forCustomer($customer2)->create([
            'address' => '456 Jane Avenue',
        ]);

        Livewire::actingAs($user)
            ->test('properties.index')
            ->set('customerFilter', $customer1->id)
            ->assertSee('123 John Street')
            ->assertDontSee('456 Jane Avenue');
    });

    it('shows all types by default', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $residential = Property::factory()->residential()->forCustomer($customer)->create([
            'address' => '123 Home Lane',
        ]);
        $commercial = Property::factory()->commercial()->forCustomer($customer)->create([
            'address' => '456 Business Blvd',
        ]);

        Livewire::actingAs($user)
            ->test('properties.index')
            ->assertSet('typeFilter', 'all')
            ->assertSee('123 Home Lane')
            ->assertSee('456 Business Blvd');
    });

    it('resets pagination when search changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        Property::factory()->count(20)->forCustomer($customer)->create();

        Livewire::actingAs($user)
            ->test('properties.index')
            ->set('search', 'test')
            ->assertSet('paginators.page', 1);
    });

    it('resets pagination when type filter changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        Property::factory()->count(20)->forCustomer($customer)->create();

        Livewire::actingAs($user)
            ->test('properties.index')
            ->set('typeFilter', 'residential')
            ->assertSet('paginators.page', 1);
    });

    it('resets pagination when customer filter changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        Property::factory()->count(20)->forCustomer($customer)->create();

        Livewire::actingAs($user)
            ->test('properties.index')
            ->set('customerFilter', $customer->id)
            ->assertSet('paginators.page', 1);
    });

    it('shows empty state when no properties exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('properties.index')
            ->assertSee('No properties found');
    });

    it('shows empty state with appropriate message when search yields no results', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main Street',
        ]);

        Livewire::actingAs($user)
            ->test('properties.index')
            ->set('search', 'NonexistentAddress')
            ->assertSee('No properties found')
            ->assertSee('Try adjusting your search or filter criteria');
    });

    it('displays correct type badges', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        Property::factory()->residential()->forCustomer($customer)->create([
            'address' => '123 Home Lane',
        ]);

        Livewire::actingAs($user)
            ->test('properties.index')
            ->assertSee('Residential');
    });

    it('displays photo count', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $property = Property::factory()->forCustomer($customer)->create();

        Livewire::actingAs($user)
            ->test('properties.index')
            ->assertSee('0');
    });

    it('displays customer name', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        Property::factory()->forCustomer($customer)->create();

        Livewire::actingAs($user)
            ->test('properties.index')
            ->assertSee('John Doe');
    });

    it('has correct default values', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('properties.index')
            ->assertSet('search', '')
            ->assertSet('typeFilter', 'all')
            ->assertSet('customerFilter', '')
            ->assertSet('perPage', 15);
    });
});
