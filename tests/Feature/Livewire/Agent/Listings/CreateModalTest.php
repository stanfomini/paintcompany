<?php

use App\Models\AgentListing;
use App\Models\User;
use Livewire\Livewire;

describe('Agent Listings CreateModal component', function () {
    it('renders the create modal component', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->assertStatus(200);
    });

    it('opens and closes the modal', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->assertSet('showModal', false)
            ->call('openModal')
            ->assertSet('showModal', true)
            ->call('closeModal')
            ->assertSet('showModal', false);
    });

    it('resets form fields when closing modal', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '123 Test St')
            ->set('city', 'Los Angeles')
            ->set('state', 'CA')
            ->set('zip', '90001')
            ->call('closeModal')
            ->assertSet('address', '')
            ->assertSet('city', '')
            ->assertSet('state', '')
            ->assertSet('zip', '');
    });

    it('validates required fields', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->call('save')
            ->assertHasErrors(['address', 'city', 'state', 'zip']);
    });

    it('validates state max length', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '123 Test St')
            ->set('city', 'Los Angeles')
            ->set('state', 'CAL')
            ->set('zip', '90001')
            ->call('save')
            ->assertHasErrors(['state']);
    });

    it('validates listing price is numeric', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '123 Test St')
            ->set('city', 'Los Angeles')
            ->set('state', 'CA')
            ->set('zip', '90001')
            ->set('listingPrice', 'not-a-number')
            ->call('save')
            ->assertHasErrors(['listingPrice']);
    });

    it('validates status is valid', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '123 Test St')
            ->set('city', 'Los Angeles')
            ->set('state', 'CA')
            ->set('zip', '90001')
            ->set('status', 'invalid-status')
            ->call('save')
            ->assertHasErrors(['status']);
    });

    it('creates a listing successfully with required fields only', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '123 Test Street')
            ->set('city', 'Los Angeles')
            ->set('state', 'ca')
            ->set('zip', '90001')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('listing-created')
            ->assertSet('showModal', false);

        $this->assertDatabaseHas('agent_listings', [
            'agent_id' => $agent->id,
            'address' => '123 Test Street',
            'city' => 'Los Angeles',
            'state' => 'CA',
            'zip' => '90001',
            'status' => 'active',
        ]);
    });

    it('creates a listing successfully with all fields', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '456 Full Address')
            ->set('city', 'San Francisco')
            ->set('state', 'CA')
            ->set('zip', '94102')
            ->set('status', 'pending')
            ->set('listingPrice', '750000')
            ->set('description', 'Beautiful home with great view')
            ->set('notes', 'Owner is motivated')
            ->set('openHouseDate', '2025-02-15T14:00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('listing-created');

        $this->assertDatabaseHas('agent_listings', [
            'agent_id' => $agent->id,
            'address' => '456 Full Address',
            'city' => 'San Francisco',
            'state' => 'CA',
            'zip' => '94102',
            'status' => 'pending',
            'listing_price' => 750000,
            'description' => 'Beautiful home with great view',
            'notes' => 'Owner is motivated',
        ]);
    });

    it('converts state to uppercase', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '123 Test Street')
            ->set('city', 'Los Angeles')
            ->set('state', 'ca')
            ->set('zip', '90001')
            ->call('save');

        $this->assertDatabaseHas('agent_listings', [
            'agent_id' => $agent->id,
            'state' => 'CA',
        ]);
    });

    it('sets agent_id to authenticated agent', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '123 Test Street')
            ->set('city', 'Los Angeles')
            ->set('state', 'CA')
            ->set('zip', '90001')
            ->call('save');

        $listing = AgentListing::where('address', '123 Test Street')->first();

        expect($listing->agent_id)->toBe($agent->id);
    });

    it('resets status to active after closing modal', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('status', 'sold')
            ->call('closeModal')
            ->assertSet('status', 'active');
    });

    it('defaults status to active', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->assertSet('status', 'active');
    });

    it('allows empty optional fields', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '123 Test Street')
            ->set('city', 'Los Angeles')
            ->set('state', 'CA')
            ->set('zip', '90001')
            ->set('listingPrice', '')
            ->set('description', '')
            ->set('notes', '')
            ->set('openHouseDate', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('agent_listings', [
            'agent_id' => $agent->id,
            'address' => '123 Test Street',
            'listing_price' => null,
            'description' => null,
            'notes' => null,
            'open_house_date' => null,
        ]);
    });

    it('validates open house date format', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '123 Test St')
            ->set('city', 'Los Angeles')
            ->set('state', 'CA')
            ->set('zip', '90001')
            ->set('openHouseDate', 'not-a-date')
            ->call('save')
            ->assertHasErrors(['openHouseDate']);
    });

    it('validates listing price minimum value', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '123 Test St')
            ->set('city', 'Los Angeles')
            ->set('state', 'CA')
            ->set('zip', '90001')
            ->set('listingPrice', '-100')
            ->call('save')
            ->assertHasErrors(['listingPrice']);
    });
});
