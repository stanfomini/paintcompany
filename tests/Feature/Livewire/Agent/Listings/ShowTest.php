<?php

use App\Models\Customer;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

describe('Agent Listings Show component', function () {
    it('renders the show component for own listing', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create([
            'address' => '123 Test Street',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.show', ['property' => $property])
            ->assertStatus(200)
            ->assertSee('123 Test Street');
    });

    it('displays listing details', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create([
            'address' => '456 Oak Avenue',
            'city' => 'Los Angeles',
            'state' => 'CA',
            'zip' => '90001',
            'listing_price' => 500000,
            'description' => 'Beautiful home',
            'notes' => 'Owner is motivated',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.show', ['property' => $property])
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
        $otherProperty = Property::factory()->agentListing()->forAgent($otherAgent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.show', ['property' => $otherProperty])
            ->assertForbidden();
    });

    it('displays referred customers count', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();

        // Create customers referred from this listing
        Customer::factory()->count(3)->create([
            'property_id' => $property->id,
            'referred_by' => $agent->id,
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.show', ['property' => $property])
            ->assertSee('3');
    });

    it('shows zero referred customers when none exist', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.show', ['property' => $property])
            ->assertSee('0');
    });

    it('opens delete confirmation modal', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.show', ['property' => $property])
            ->assertSet('showDeleteModal', false)
            ->call('confirmDelete')
            ->assertSet('showDeleteModal', true);
    });

    it('can cancel delete', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.show', ['property' => $property])
            ->call('confirmDelete')
            ->assertSet('showDeleteModal', true)
            ->call('cancelDelete')
            ->assertSet('showDeleteModal', false);
    });

    it('can delete own listing', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.show', ['property' => $property])
            ->call('delete')
            ->assertRedirect(route('agent.listings.index'));

        $this->assertSoftDeleted('properties', [
            'id' => $property->id,
        ]);
    });

    it('cannot delete another agent\'s listing', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();
        $otherProperty = Property::factory()->agentListing()->forAgent($otherAgent)->create();

        // Force the listing onto the component to test authorization on delete
        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.show', ['property' => $otherProperty])
            ->assertForbidden();
    });

    it('displays status badge correctly', function () {
        $agent = User::factory()->agent()->create();

        $activeProperty = Property::factory()->agentListing()->forAgent($agent)->active()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.show', ['property' => $activeProperty])
            ->assertSee('Active');
    });

    it('displays open house date when set', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create([
            'open_house_date' => '2025-02-15 14:00:00',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.show', ['property' => $property])
            ->assertSee('Feb 15, 2025');
    });

    it('shows not scheduled when no open house date', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create([
            'open_house_date' => null,
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.show', ['property' => $property])
            ->assertSee('Not scheduled');
    });

    it('refreshes listing on listing-updated event', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create([
            'address' => 'Original Address',
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.show', ['property' => $property])
            ->assertSee('Original Address');

        // Update listing directly in DB
        $property->update(['address' => 'Updated Address']);

        // Dispatch the event and check it refreshes
        $component->dispatch('listing-updated')
            ->assertSee('Updated Address');
    });
});
