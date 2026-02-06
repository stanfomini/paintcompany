<?php

use App\Models\AgentListing;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Agent Dashboard Access', function () {
    it('redirects guests to agent login page', function () {
        $this->get(route('agent.dashboard'))
            ->assertRedirect(route('agent.login'));
    });

    it('renders dashboard for authenticated agent', function () {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent, 'agent')
            ->get(route('agent.dashboard'))
            ->assertSuccessful()
            ->assertSeeLivewire('agent.dashboard');
    });

    it('forbids internal users from accessing agent dashboard', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();

        $this->actingAs($internalUser, 'agent')
            ->get(route('agent.dashboard'))
            ->assertForbidden();
    });

    it('forbids customer users from accessing agent dashboard', function () {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer, 'agent')
            ->get(route('agent.dashboard'))
            ->assertForbidden();
    });
});

describe('Agent Dashboard Stats', function () {
    it('displays correct total listings count', function () {
        $agent = User::factory()->agent()->create();
        AgentListing::factory()->count(3)->forAgent($agent)->create();

        // Create listings for another agent to ensure they're not counted
        $otherAgent = User::factory()->agent()->create();
        AgentListing::factory()->count(2)->forAgent($otherAgent)->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('Total Listings');

        expect($component->get('totalListings'))->toBe(3);
    });

    it('displays correct active listings count', function () {
        $agent = User::factory()->agent()->create();
        AgentListing::factory()->count(2)->active()->forAgent($agent)->create();
        AgentListing::factory()->pending()->forAgent($agent)->create();
        AgentListing::factory()->sold()->forAgent($agent)->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('Active Listings');

        expect($component->get('activeListings'))->toBe(2);
    });

    it('displays correct referred customers count', function () {
        $agent = User::factory()->agent()->create();
        Customer::factory()->count(4)->referredByAgent($agent)->create();

        // Create customers referred by another agent
        $otherAgent = User::factory()->agent()->create();
        Customer::factory()->count(2)->referredByAgent($otherAgent)->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('Referred Customers');

        expect($component->get('referredCustomers'))->toBe(4);
    });

    it('displays correct upcoming open houses count within 7 days', function () {
        $agent = User::factory()->agent()->create();

        // Open house in 3 days (should be counted)
        AgentListing::factory()->forAgent($agent)->create([
            'open_house_date' => now()->addDays(3),
        ]);

        // Open house in 5 days (should be counted)
        AgentListing::factory()->forAgent($agent)->create([
            'open_house_date' => now()->addDays(5),
        ]);

        // Open house in 10 days (should NOT be counted)
        AgentListing::factory()->forAgent($agent)->create([
            'open_house_date' => now()->addDays(10),
        ]);

        // Past open house (should NOT be counted)
        AgentListing::factory()->forAgent($agent)->create([
            'open_house_date' => now()->subDays(1),
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('Upcoming Open Houses');

        expect($component->get('upcomingOpenHousesCount'))->toBe(2);
    });

    it('displays zero stats for new agent', function () {
        $agent = User::factory()->agent()->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard');

        expect($component->get('totalListings'))->toBe(0);
        expect($component->get('activeListings'))->toBe(0);
        expect($component->get('referredCustomers'))->toBe(0);
        expect($component->get('upcomingOpenHousesCount'))->toBe(0);
    });
});

describe('Agent Dashboard Sections', function () {
    it('displays upcoming open houses list', function () {
        $agent = User::factory()->agent()->create();

        $listing = AgentListing::factory()->forAgent($agent)->create([
            'address' => '123 Main Street',
            'city' => 'Springfield',
            'state' => 'IL',
            'zip' => '62701',
            'open_house_date' => now()->addDays(2),
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('123 Main Street')
            ->assertSee('Springfield');
    });

    it('displays recent customers list', function () {
        $agent = User::factory()->agent()->create();

        $customer = Customer::factory()->referredByAgent($agent)->create([
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'john.smith@example.com',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('John Smith')
            ->assertSee('john.smith@example.com');
    });

    it('limits upcoming open houses to 5', function () {
        $agent = User::factory()->agent()->create();

        // Create 7 listings with open houses
        for ($i = 1; $i <= 7; $i++) {
            AgentListing::factory()->forAgent($agent)->create([
                'address' => "Address $i",
                'open_house_date' => now()->addDays($i),
            ]);
        }

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard');

        expect($component->get('upcomingOpenHouses'))->toHaveCount(5);
    });

    it('limits recent customers to 5', function () {
        $agent = User::factory()->agent()->create();

        // Create 7 customers
        Customer::factory()->count(7)->referredByAgent($agent)->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard');

        expect($component->get('recentCustomers'))->toHaveCount(5);
    });

    it('shows empty state for no upcoming open houses', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('No upcoming open houses scheduled.');
    });

    it('shows empty state for no referred customers', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('No customers referred yet.');
    });
});

describe('Agent Dashboard Quick Actions', function () {
    it('displays add new listing button', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('Add New Listing');
    });

    it('displays view my customers button', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('View My Customers');
    });
});
