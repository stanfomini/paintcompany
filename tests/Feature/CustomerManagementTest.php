<?php

use App\Livewire\Customers\CreateModal;
use App\Livewire\Customers\EditModal;
use App\Livewire\Customers\Index;
use App\Livewire\Customers\Show;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Customer list access', function () {
    it('allows internal user to view customer list', function () {
        $user = User::factory()->internal()->create();

        $response = $this->actingAs($user)
            ->get(route('customers.index'));

        $response->assertOk();
        $response->assertSeeLivewire(Index::class);
    });

    it('denies agent access to customer list', function () {
        $user = User::factory()->agent()->create();

        $response = $this->actingAs($user)
            ->get(route('customers.index'));

        $response->assertForbidden();
    });

    it('denies customer access to customer list', function () {
        $user = User::factory()->customer()->create();

        $response = $this->actingAs($user)
            ->get(route('customers.index'));

        $response->assertForbidden();
    });

    it('redirects unauthenticated users to login', function () {
        $response = $this->get(route('customers.index'));

        $response->assertRedirect(route('login'));
    });
});

describe('Customer detail access', function () {
    it('allows internal user to view customer detail', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('customers.show', $customer));

        $response->assertOk();
        $response->assertSeeLivewire(Show::class);
    });

    it('denies agent access to customer detail', function () {
        $user = User::factory()->agent()->create();
        $customer = Customer::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('customers.show', $customer));

        $response->assertForbidden();
    });

    it('denies customer access to customer detail', function () {
        $user = User::factory()->customer()->create();
        $customer = Customer::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('customers.show', $customer));

        $response->assertForbidden();
    });
});

describe('Customer creation', function () {
    it('allows internal user to create customer', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'john.doe@example.com')
            ->set('phone', '555-123-4567')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '555-123-4567',
            'created_by' => $user->id,
        ]);
    });

    it('validates required fields on create', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('firstName', '')
            ->set('lastName', '')
            ->set('email', '')
            ->call('save')
            ->assertHasErrors(['firstName', 'lastName', 'email']);
    });

    it('validates email format on create', function () {
        $user = User::factory()->internal()->create();

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'invalid-email')
            ->call('save')
            ->assertHasErrors(['email']);
    });

    it('validates email uniqueness on create', function () {
        $user = User::factory()->internal()->create();
        Customer::factory()->create(['email' => 'existing@example.com']);

        Livewire::actingAs($user)
            ->test(CreateModal::class)
            ->set('firstName', 'John')
            ->set('lastName', 'Doe')
            ->set('email', 'existing@example.com')
            ->call('save')
            ->assertHasErrors(['email']);
    });
});

describe('Customer editing', function () {
    it('allows internal user to edit customer', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
        ]);

        Livewire::actingAs($user)
            ->test(EditModal::class, ['customerId' => $customer->id])
            ->set('firstName', 'Janet')
            ->set('lastName', 'Johnson')
            ->set('email', 'janet.johnson@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'first_name' => 'Janet',
            'last_name' => 'Johnson',
            'email' => 'janet.johnson@example.com',
        ]);
    });

    it('allows same email when editing same customer', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create([
            'email' => 'same@example.com',
        ]);

        Livewire::actingAs($user)
            ->test(EditModal::class, ['customerId' => $customer->id])
            ->set('firstName', 'Updated')
            ->call('save')
            ->assertHasNoErrors();
    });
});

describe('Customer search functionality', function () {
    it('filters customers by search term', function () {
        $user = User::factory()->internal()->create();

        Customer::factory()->create(['first_name' => 'Alice', 'last_name' => 'Anderson']);
        Customer::factory()->create(['first_name' => 'Bob', 'last_name' => 'Baker']);
        Customer::factory()->create(['first_name' => 'Charlie', 'last_name' => 'Cooper']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'Alice')
            ->assertSee('Alice Anderson')
            ->assertDontSee('Bob Baker')
            ->assertDontSee('Charlie Cooper');
    });

    it('searches by email', function () {
        $user = User::factory()->internal()->create();

        Customer::factory()->create(['first_name' => 'Alice', 'email' => 'alice@test.com']);
        Customer::factory()->create(['first_name' => 'Bob', 'email' => 'bob@other.com']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('search', 'alice@test.com')
            ->assertSee('Alice')
            ->assertDontSee('Bob');
    });
});

describe('Customer status filter', function () {
    it('filters customers by active status', function () {
        $user = User::factory()->internal()->create();

        Customer::factory()->create(['first_name' => 'ActiveUser', 'status' => 'active']);
        Customer::factory()->create(['first_name' => 'InactiveUser', 'status' => 'inactive']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('statusFilter', 'active')
            ->assertSee('ActiveUser')
            ->assertDontSee('InactiveUser');
    });

    it('filters customers by inactive status', function () {
        $user = User::factory()->internal()->create();

        Customer::factory()->create(['first_name' => 'ActiveUser', 'status' => 'active']);
        Customer::factory()->create(['first_name' => 'InactiveUser', 'status' => 'inactive']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('statusFilter', 'inactive')
            ->assertDontSee('ActiveUser')
            ->assertSee('InactiveUser');
    });

    it('shows all customers when filter is all', function () {
        $user = User::factory()->internal()->create();

        Customer::factory()->create(['first_name' => 'ActiveUser', 'status' => 'active']);
        Customer::factory()->create(['first_name' => 'InactiveUser', 'status' => 'inactive']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('statusFilter', 'all')
            ->assertSee('ActiveUser')
            ->assertSee('InactiveUser');
    });
});
