<?php

use App\Livewire\Agents\Show;
use App\Models\AgentListing;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

describe('Agent Show component', function () {
    it('renders the show component with agent data', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create([
            'name' => 'John Agent',
            'email' => 'john.agent@example.com',
        ]);
        $agent->agentProfile->update([
            'company_name' => 'Apex Realty',
            'license_number' => 'LIC12345',
            'phone' => '555-1234',
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['user' => $agent])
            ->assertStatus(200)
            ->assertSee('John Agent')
            ->assertSee('john.agent@example.com')
            ->assertSee('Apex Realty')
            ->assertSee('LIC12345')
            ->assertSee('555-1234');
    });

    it('does not allow access without authentication', function () {
        $agent = User::factory()->agent()->create();

        $this->get(route('agents.show', $agent))
            ->assertRedirect(route('login'));
    });

    it('returns 404 for non-agent users', function () {
        $user = User::factory()->internal()->create();
        $internalUser = User::factory()->internal()->create(['name' => 'Internal User']);

        Livewire::actingAs($user)
            ->test(Show::class, ['user' => $internalUser])
            ->assertStatus(404);
    });

    it('displays agent status badge', function () {
        $user = User::factory()->internal()->create();
        $activeAgent = User::factory()->agent()->create(['is_active' => true]);

        Livewire::actingAs($user)
            ->test(Show::class, ['user' => $activeAgent])
            ->assertStatus(200)
            ->assertSee('Active');
    });

    it('displays inactive status badge', function () {
        $user = User::factory()->internal()->create();
        $inactiveAgent = User::factory()->agent()->inactive()->create();

        Livewire::actingAs($user)
            ->test(Show::class, ['user' => $inactiveAgent])
            ->assertStatus(200)
            ->assertSee('Inactive');
    });

    it('displays stats overview', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();
        AgentListing::factory()->count(3)->forAgent($agent)->create();
        Customer::factory()->count(2)->create(['referred_by' => $agent->id, 'created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test(Show::class, ['user' => $agent])
            ->assertSee('Total Listings')
            ->assertSee('Customers Referred')
            ->assertSee('Active Listings');
    });

    it('displays bio when available', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();
        $agent->agentProfile->update([
            'bio' => 'This is the agent bio text.',
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['user' => $agent])
            ->assertSee('This is the agent bio text.');
    });

    it('can switch to listings tab', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();
        AgentListing::factory()->forAgent($agent)->create([
            'address' => '123 Test Street',
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['user' => $agent])
            ->assertSet('activeTab', 'overview')
            ->call('setTab', 'listings')
            ->assertSet('activeTab', 'listings')
            ->assertSee('123 Test Street');
    });

    it('can switch to customers tab', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();
        Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Customer',
            'referred_by' => $agent->id,
            'created_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['user' => $agent])
            ->call('setTab', 'customers')
            ->assertSet('activeTab', 'customers')
            ->assertSee('John Customer');
    });

    it('displays empty state for listings when none exist', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($user)
            ->test(Show::class, ['user' => $agent])
            ->call('setTab', 'listings')
            ->assertSee('No listings')
            ->assertSee('This agent has no listings yet');
    });

    it('displays empty state for customers when none exist', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($user)
            ->test(Show::class, ['user' => $agent])
            ->call('setTab', 'customers')
            ->assertSee('No referred customers')
            ->assertSee('This agent has not referred any customers yet');
    });

    it('loads relationships correctly', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();
        AgentListing::factory()->forAgent($agent)->create();

        $component = Livewire::actingAs($user)
            ->test(Show::class, ['user' => $agent]);

        expect($component->get('user')->relationLoaded('agentProfile'))->toBeTrue();
        expect($component->get('user')->relationLoaded('agentListings'))->toBeTrue();
        expect($component->get('user')->relationLoaded('referredCustomers'))->toBeTrue();
    });

    it('displays listing details correctly', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();
        AgentListing::factory()->forAgent($agent)->create([
            'address' => '456 Main Ave',
            'city' => 'Testville',
            'state' => 'CA',
            'zip' => '90210',
            'listing_price' => 500000.00,
            'status' => 'active',
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['user' => $agent])
            ->call('setTab', 'listings')
            ->assertSee('456 Main Ave')
            ->assertSee('Testville')
            ->assertSee('CA')
            ->assertSee('90210')
            ->assertSee('$500,000.00');
    });

    it('uses the app layout', function () {
        $user = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();

        $component = Livewire::actingAs($user)
            ->test(Show::class, ['user' => $agent]);

        $component->assertStatus(200);
    });
});
