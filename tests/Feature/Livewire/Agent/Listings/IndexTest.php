<?php

use App\Models\AgentListing;
use App\Models\User;
use Livewire\Livewire;

describe('Agent Listings Index component', function () {
    it('renders the listings index component for agent', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->assertStatus(200)
            ->assertSee('My Listings');
    });

    it('displays only the authenticated agent\'s listings', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();

        $ownListing = AgentListing::factory()->forAgent($agent)->create([
            'address' => '123 Own Street',
        ]);
        $otherListing = AgentListing::factory()->forAgent($otherAgent)->create([
            'address' => '456 Other Street',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->assertSee('123 Own Street')
            ->assertDontSee('456 Other Street');
    });

    it('can search listings by address', function () {
        $agent = User::factory()->agent()->create();

        $listing1 = AgentListing::factory()->forAgent($agent)->create([
            'address' => '123 Main Street',
        ]);
        $listing2 = AgentListing::factory()->forAgent($agent)->create([
            'address' => '456 Oak Avenue',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->set('search', 'Main')
            ->assertSee('123 Main Street')
            ->assertDontSee('456 Oak Avenue');
    });

    it('can search listings by city', function () {
        $agent = User::factory()->agent()->create();

        $listing1 = AgentListing::factory()->forAgent($agent)->create([
            'address' => '123 Main Street',
            'city' => 'Los Angeles',
        ]);
        $listing2 = AgentListing::factory()->forAgent($agent)->create([
            'address' => '456 Oak Avenue',
            'city' => 'San Francisco',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->set('search', 'Los Angeles')
            ->assertSee('123 Main Street')
            ->assertDontSee('456 Oak Avenue');
    });

    it('can filter listings by status', function () {
        $agent = User::factory()->agent()->create();

        $activeListing = AgentListing::factory()->forAgent($agent)->active()->create([
            'address' => '123 Active Street',
        ]);
        $soldListing = AgentListing::factory()->forAgent($agent)->sold()->create([
            'address' => '456 Sold Avenue',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->set('statusFilter', 'active')
            ->assertSee('123 Active Street')
            ->assertDontSee('456 Sold Avenue');
    });

    it('shows all statuses by default', function () {
        $agent = User::factory()->agent()->create();

        $activeListing = AgentListing::factory()->forAgent($agent)->active()->create([
            'address' => '123 Active Street',
        ]);
        $soldListing = AgentListing::factory()->forAgent($agent)->sold()->create([
            'address' => '456 Sold Avenue',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->assertSet('statusFilter', 'all')
            ->assertSee('123 Active Street')
            ->assertSee('456 Sold Avenue');
    });

    it('resets pagination when search changes', function () {
        $agent = User::factory()->agent()->create();
        AgentListing::factory()->count(20)->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->set('search', 'test')
            ->assertSet('paginators.page', 1);
    });

    it('resets pagination when status filter changes', function () {
        $agent = User::factory()->agent()->create();
        AgentListing::factory()->count(20)->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->set('statusFilter', 'active')
            ->assertSet('paginators.page', 1);
    });

    it('shows empty state when no listings exist', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->assertSee('No listings found')
            ->assertSee('Get started by adding your first property listing.');
    });

    it('shows empty state with appropriate message when search yields no results', function () {
        $agent = User::factory()->agent()->create();
        AgentListing::factory()->forAgent($agent)->create([
            'address' => '123 Main Street',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->set('search', 'NonexistentAddress')
            ->assertSee('No listings found')
            ->assertSee('Try adjusting your search or filter criteria.');
    });

    it('can delete own listing', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'address' => '123 Delete Street',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->call('delete', $listing->id)
            ->assertSee('Listing archived successfully.');

        $this->assertSoftDeleted('agent_listings', [
            'id' => $listing->id,
        ]);
    });

    it('cannot delete another agent\'s listing', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();
        $otherListing = AgentListing::factory()->forAgent($otherAgent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->call('delete', $otherListing->id)
            ->assertForbidden();
    });

    it('agent cannot see other agent listings in their own list', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();

        // Create listings for both agents
        AgentListing::factory()->forAgent($agent)->create(['address' => 'My Listing']);
        AgentListing::factory()->forAgent($otherAgent)->create(['address' => 'Other Agent Listing']);

        // Agent should only see their own listing
        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->assertSee('My Listing')
            ->assertDontSee('Other Agent Listing');
    });

    it('displays correct status badges', function () {
        $agent = User::factory()->agent()->create();

        AgentListing::factory()->forAgent($agent)->active()->create([
            'address' => '123 Active St',
        ]);
        AgentListing::factory()->forAgent($agent)->pending()->create([
            'address' => '456 Pending St',
        ]);
        AgentListing::factory()->forAgent($agent)->sold()->create([
            'address' => '789 Sold St',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->assertSee('Active')
            ->assertSee('Pending')
            ->assertSee('Sold');
    });

    it('displays listing price when set', function () {
        $agent = User::factory()->agent()->create();

        AgentListing::factory()->forAgent($agent)->create([
            'address' => '123 Price St',
            'listing_price' => 500000,
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->assertSee('$500,000');
    });

    it('has correct default values', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.index')
            ->assertSet('search', '')
            ->assertSet('statusFilter', 'all')
            ->assertSet('perPage', 15);
    });
});
