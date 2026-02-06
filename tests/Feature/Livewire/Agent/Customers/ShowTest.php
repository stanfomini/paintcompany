<?php

use App\Models\AgentListing;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

describe('Agent Customer Show component', function () {
    it('renders the customer show component for authorized agent', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.show', ['customer' => $customer])
            ->assertStatus(200)
            ->assertSee('John Doe');
    });

    it('displays customer contact information', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '555-123-4567',
            'address' => '456 Oak Ave',
            'city' => 'Chicago',
            'state' => 'IL',
            'zip' => '60601',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.show', ['customer' => $customer])
            ->assertSee('john@example.com')
            ->assertSee('555-123-4567')
            ->assertSee('456 Oak Ave');
    });

    it('displays the listing the customer was referred from', function () {
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
            ->test('agent.customers.show', ['customer' => $customer])
            ->assertSee('123 Main St, Springfield, IL 62701');
    });

    it('displays customer status badge', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'status' => 'active',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.show', ['customer' => $customer])
            ->assertSee('Active');
    });

    it('displays customer notes when present', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'notes' => 'This is a test note about the customer.',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.show', ['customer' => $customer])
            ->assertSee('This is a test note about the customer.');
    });

    it('displays the referred date', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'created_at' => now()->subDays(10),
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.show', ['customer' => $customer])
            ->assertSee(now()->subDays(10)->format('M d, Y'));
    });

    it('returns 403 when agent tries to view another agents customer', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();

        $customer = Customer::factory()->referredByAgent($otherAgent)->create([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.show', ['customer' => $customer])
            ->assertForbidden();
    });

    it('returns 403 when customer has no referring agent', function () {
        $agent = User::factory()->agent()->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create();

        $customer = Customer::factory()->createdBy($internalUser)->create([
            'first_name' => 'Orphan',
            'last_name' => 'Customer',
            'referred_by' => null,
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.show', ['customer' => $customer])
            ->assertForbidden();
    });

    it('does not show edit functionality', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.show', ['customer' => $customer])
            ->assertDontSee('Edit')
            ->assertDontSee('Delete');
    });

    it('has back button linking to index page', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.customers.show', ['customer' => $customer])
            ->assertSee('Back');
    });
});
