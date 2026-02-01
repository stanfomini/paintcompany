<?php

use App\Livewire\Customers\Index;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

describe('Customer Index component', function () {
    it('renders the customer index component', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertStatus(200)
            ->assertSee('Customers');
    });

    it('displays customers in the list', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->createdBy($user)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('John Doe')
            ->assertSee('john.doe@example.com');
    });

    it('can search customers by name', function () {
        $user = User::factory()->internal()->create();
        $john = Customer::factory()->createdBy($user)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
        $jane = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'John')
            ->assertSee('John Doe')
            ->assertDontSee('Jane Smith');
    });

    it('can search customers by email', function () {
        $user = User::factory()->internal()->create();
        $john = Customer::factory()->createdBy($user)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ]);
        $jane = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'john@example')
            ->assertSee('John Doe')
            ->assertDontSee('Jane Smith');
    });

    it('can filter customers by active status', function () {
        $user = User::factory()->internal()->create();
        $activeCustomer = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Active',
            'last_name' => 'Customer',
            'status' => 'active',
        ]);
        $inactiveCustomer = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Inactive',
            'last_name' => 'Customer',
            'status' => 'inactive',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('statusFilter', 'active')
            ->assertSee('Active Customer')
            ->assertDontSee('Inactive Customer');
    });

    it('can filter customers by inactive status', function () {
        $user = User::factory()->internal()->create();
        $activeCustomer = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Active',
            'last_name' => 'Customer',
            'status' => 'active',
        ]);
        $inactiveCustomer = Customer::factory()->inactive()->createdBy($user)->create([
            'first_name' => 'Inactive',
            'last_name' => 'Customer',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('statusFilter', 'inactive')
            ->assertSee('Inactive Customer')
            ->assertDontSee('Active Customer');
    });

    it('shows all statuses by default', function () {
        $user = User::factory()->internal()->create();
        $activeCustomer = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Active',
            'last_name' => 'Customer',
            'status' => 'active',
        ]);
        $inactiveCustomer = Customer::factory()->inactive()->createdBy($user)->create([
            'first_name' => 'Inactive',
            'last_name' => 'Customer',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSet('statusFilter', 'all')
            ->assertSee('Active Customer')
            ->assertSee('Inactive Customer');
    });

    it('resets pagination when search changes', function () {
        $user = User::factory()->internal()->create();
        Customer::factory()->count(20)->createdBy($user)->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'test')
            ->assertSet('paginators.page', 1);
    });

    it('resets pagination when status filter changes', function () {
        $user = User::factory()->internal()->create();
        Customer::factory()->count(20)->createdBy($user)->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('statusFilter', 'active')
            ->assertSet('paginators.page', 1);
    });

    it('shows empty state when no customers exist', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('No customers found');
    });

    it('shows empty state with appropriate message when search yields no results', function () {
        $user = User::factory()->internal()->create();
        Customer::factory()->createdBy($user)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'NonexistentCustomer')
            ->assertSee('No customers found')
            ->assertSee('Try adjusting your search or filter criteria');
    });

    it('displays correct status badges', function () {
        $user = User::factory()->internal()->create();
        $activeCustomer = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Active',
            'last_name' => 'User',
            'status' => 'active',
        ]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSee('Active');
    });

    it('has correct default values', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->assertSet('search', '')
            ->assertSet('statusFilter', 'all')
            ->assertSet('perPage', 15);
    });
});
