<?php

use App\Livewire\Agent\Listings\Show;
use App\Models\AgentListing;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

describe('Agent Listings Show component', function () {
    it('renders the show component for own listing', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'address' => '123 Test Street',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test(Show::class, ['agentListing' => $listing])
            ->assertStatus(200)
            ->assertSee('123 Test Street');
    });

    it('displays listing details', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'address' => '456 Oak Avenue',
            'city' => 'Los Angeles',
            'state' => 'CA',
            'zip' => '90001',
            'listing_price' => 500000,
            'description' => 'Beautiful home',
            'notes' => 'Owner is motivated',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test(Show::class, ['agentListing' => $listing])
            ->assertSee('456 Oak Avenue')
            ->assertSee('Los Angeles')
            ->assertSee('CA')
            ->assertSee('90001')
            ->assertSee('$500,000')
            ->assertSee('Beautiful home')
            ->assertSee('Owner is motivated');
    });

    it('cannot view another agent\'s listing', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();
        $otherListing = AgentListing::factory()->forAgent($otherAgent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Show::class, ['agentListing' => $otherListing])
            ->assertForbidden();
    });

    it('displays referred customers count', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        // Create customers referred from this listing
        Customer::factory()->count(3)->create([
            'listing_id' => $listing->id,
            'referred_by' => $agent->id,
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test(Show::class, ['agentListing' => $listing])
            ->assertSee('3');
    });

    it('shows zero referred customers when none exist', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Show::class, ['agentListing' => $listing])
            ->assertSee('0');
    });

    it('opens delete confirmation modal', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Show::class, ['agentListing' => $listing])
            ->assertSet('showDeleteModal', false)
            ->call('confirmDelete')
            ->assertSet('showDeleteModal', true);
    });

    it('can cancel delete', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Show::class, ['agentListing' => $listing])
            ->call('confirmDelete')
            ->assertSet('showDeleteModal', true)
            ->call('cancelDelete')
            ->assertSet('showDeleteModal', false);
    });

    it('can delete own listing', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Show::class, ['agentListing' => $listing])
            ->call('delete')
            ->assertRedirect(route('agent.listings.index'));

        $this->assertSoftDeleted('agent_listings', [
            'id' => $listing->id,
        ]);
    });

    it('cannot delete another agent\'s listing', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();
        $otherListing = AgentListing::factory()->forAgent($otherAgent)->create();

        // Force the listing onto the component to test authorization on delete
        Livewire::actingAs($agent, 'agent')
            ->test(Show::class, ['agentListing' => $otherListing])
            ->assertForbidden();
    });

    it('displays status badge correctly', function () {
        $agent = User::factory()->agent()->create();

        $activeListing = AgentListing::factory()->forAgent($agent)->active()->create();

        Livewire::actingAs($agent, 'agent')
            ->test(Show::class, ['agentListing' => $activeListing])
            ->assertSee('Active');
    });

    it('displays open house date when set', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'open_house_date' => '2025-02-15 14:00:00',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test(Show::class, ['agentListing' => $listing])
            ->assertSee('Feb 15, 2025');
    });

    it('shows not scheduled when no open house date', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'open_house_date' => null,
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test(Show::class, ['agentListing' => $listing])
            ->assertSee('Not scheduled');
    });

    it('refreshes listing on listing-updated event', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'address' => 'Original Address',
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test(Show::class, ['agentListing' => $listing])
            ->assertSee('Original Address');

        // Update listing directly in DB
        $listing->update(['address' => 'Updated Address']);

        // Dispatch the event and check it refreshes
        $component->dispatch('listing-updated')
            ->assertSee('Updated Address');
    });
});
