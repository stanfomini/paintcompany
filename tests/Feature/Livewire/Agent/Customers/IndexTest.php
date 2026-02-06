<?php

use App\Models\AgentListing;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

describe('Agent Customer Index component', function () {
    it('renders the customer index component for authenticated agents', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.index')
            ->assertStatus(200)
            ->assertSee('My Customers');
    });

    it('displays customers referred by the authenticated agent', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.index')
            ->assertSee('John Doe')
            ->assertSee('john.doe@example.com');
    });

    it('does not display customers referred by other agents', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();

        $ownCustomer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $otherCustomer = Customer::factory()->referredByAgent($otherAgent)->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.index')
            ->assertSee('John Doe')
            ->assertDontSee('Jane Smith');
    });

    it('can search customers by name', function () {
        $agent = User::factory()->agent()->create();

        $john = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $jane = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.index')
            ->set('search', 'John')
            ->assertSee('John Doe')
            ->assertDontSee('Jane Smith');
    });

    it('can search customers by email', function () {
        $agent = User::factory()->agent()->create();

        $john = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
        ]);

        $jane = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.index')
            ->set('search', 'john@example')
            ->assertSee('John Doe')
            ->assertDontSee('Jane Smith');
    });

    it('displays listing address when customer was referred from a listing', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'address' => '123 Main St',
            'city' => 'Springfield',
            'state' => 'IL',
            'zip' => '62701',
        ]);

        $customer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'listing_id' => $listing->id,
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.index')
            ->assertSee('123 Main St');
    });

    it('resets pagination when search changes', function () {
        $agent = User::factory()->agent()->create();
        Customer::factory()->count(20)->referredByAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.index')
            ->set('search', 'test')
            ->assertSet('paginators.page', 1);
    });

    it('shows empty state when no customers exist', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.index')
            ->assertSee('No customers found')
            ->assertSee('Customers referred through your listings will appear here.');
    });

    it('shows empty state with appropriate message when search yields no results', function () {
        $agent = User::factory()->agent()->create();
        Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.index')
            ->set('search', 'NonexistentCustomer')
            ->assertSee('No customers found')
            ->assertSee('Try adjusting your search criteria');
    });

    it('displays the referred date', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'created_at' => now()->subDays(5),
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.index')
            ->assertSee(now()->subDays(5)->format('M d, Y'));
    });

    it('has correct default values', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.index')
            ->assertSet('search', '')
            ->assertSet('perPage', 15);
    });
});
