<?php

use App\Models\AgentListing;
use App\Models\User;
use Livewire\Livewire;

describe('Agent Listings EditModal component', function () {
    it('renders the edit modal component', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->assertStatus(200);
    });

    it('opens modal with listing data on edit-listing event', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'address' => '123 Test Street',
            'city' => 'Los Angeles',
            'state' => 'CA',
            'zip' => '90001',
            'status' => 'active',
            'listing_price' => 500000,
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->assertSet('showModal', false)
            ->dispatch('edit-listing', listingId: $listing->id)
            ->assertSet('showModal', true)
            ->assertSet('address', '123 Test Street')
            ->assertSet('city', 'Los Angeles')
            ->assertSet('state', 'CA')
            ->assertSet('zip', '90001')
            ->assertSet('status', 'active')
            ->assertSet('listingPrice', '500000.00');
    });

    it('cannot open modal for another agent\'s listing', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();
        $otherListing = AgentListing::factory()->forAgent($otherAgent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->dispatch('edit-listing', listingId: $otherListing->id)
            ->assertForbidden();
    });

    it('closes modal and resets form', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->dispatch('edit-listing', listingId: $listing->id)
            ->assertSet('showModal', true)
            ->call('closeModal')
            ->assertSet('showModal', false)
            ->assertSet('address', '')
            ->assertSet('city', '')
            ->assertSet('listing', null);
    });

    it('validates required fields', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->dispatch('edit-listing', listingId: $listing->id)
            ->set('address', '')
            ->set('city', '')
            ->set('state', '')
            ->set('zip', '')
            ->call('save')
            ->assertHasErrors(['address', 'city', 'state', 'zip']);
    });

    it('validates state max length', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->dispatch('edit-listing', listingId: $listing->id)
            ->set('state', 'CAL')
            ->call('save')
            ->assertHasErrors(['state']);
    });

    it('validates listing price is numeric', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->dispatch('edit-listing', listingId: $listing->id)
            ->set('listingPrice', 'not-a-number')
            ->call('save')
            ->assertHasErrors(['listingPrice']);
    });

    it('validates status is valid', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->dispatch('edit-listing', listingId: $listing->id)
            ->set('status', 'invalid-status')
            ->call('save')
            ->assertHasErrors(['status']);
    });

    it('updates listing successfully', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'address' => 'Old Address',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->dispatch('edit-listing', listingId: $listing->id)
            ->set('address', 'New Address')
            ->set('city', 'San Francisco')
            ->set('state', 'ca')
            ->set('zip', '94102')
            ->set('status', 'pending')
            ->set('listingPrice', '750000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('listing-updated')
            ->assertSet('showModal', false);

        $this->assertDatabaseHas('agent_listings', [
            'id' => $listing->id,
            'address' => 'New Address',
            'city' => 'San Francisco',
            'state' => 'CA',
            'zip' => '94102',
            'status' => 'pending',
            'listing_price' => 750000,
        ]);
    });

    it('converts state to uppercase on update', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->dispatch('edit-listing', listingId: $listing->id)
            ->set('state', 'ny')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('agent_listings', [
            'id' => $listing->id,
            'state' => 'NY',
        ]);
    });

    it('cannot update another agent\'s listing', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();
        $otherListing = AgentListing::factory()->forAgent($otherAgent)->create();

        // Try to forcefully set listing and save
        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal');

        // Manually set the listing property (simulating a malicious attempt)
        $component->set('listing', $otherListing)
            ->set('address', '123 Hack Street')
            ->set('city', 'Hackville')
            ->set('state', 'HK')
            ->set('zip', '00000')
            ->call('save')
            ->assertForbidden();
    });

    it('does nothing when saving without listing', function () {
        $agent = User::factory()->agent()->create();

        // Call save without opening modal first (no listing loaded)
        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->call('save')
            ->assertHasNoErrors();
    });

    it('preserves agent_id when updating listing', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->dispatch('edit-listing', listingId: $listing->id)
            ->set('address', 'Updated Address')
            ->call('save');

        $this->assertDatabaseHas('agent_listings', [
            'id' => $listing->id,
            'agent_id' => $agent->id,
        ]);
    });

    it('loads open house date in correct format', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'open_house_date' => '2025-02-15 14:00:00',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->dispatch('edit-listing', listingId: $listing->id)
            ->assertSet('openHouseDate', '2025-02-15T14:00');
    });

    it('handles null optional fields', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'listing_price' => null,
            'description' => null,
            'notes' => null,
            'open_house_date' => null,
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->dispatch('edit-listing', listingId: $listing->id)
            ->assertSet('listingPrice', null)
            ->assertSet('description', '')
            ->assertSet('notes', '')
            ->assertSet('openHouseDate', null);
    });

    it('clears optional fields when set to empty', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'listing_price' => 500000,
            'description' => 'Original description',
            'notes' => 'Original notes',
        ]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.edit-modal')
            ->dispatch('edit-listing', listingId: $listing->id)
            ->set('listingPrice', '')
            ->set('description', '')
            ->set('notes', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('agent_listings', [
            'id' => $listing->id,
            'listing_price' => null,
            'description' => null,
            'notes' => null,
        ]);
    });
});
