<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\User;
use App\Notifications\AgentListingSubmitted;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

describe('Agent Listing Creation with Deal & Estimate', function () {
    it('creates property, deal, and draft estimate on listing submit', function () {
        Notification::fake();

        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '742 Evergreen Terrace')
            ->set('city', 'Denver')
            ->set('state', 'CO')
            ->set('zip', '80202')
            ->set('listingPrice', '450000')
            ->set('bedrooms', 3)
            ->set('sqft', 2200)
            ->set('intent', 'selling')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('listing-created');

        // Property created
        $property = Property::where('address', '742 Evergreen Terrace')->first();
        expect($property)->not->toBeNull()
            ->and($property->agent_id)->toBe($agent->id)
            ->and($property->square_footage)->toBe(2200);

        // Deal created
        $deal = Deal::where('property_id', $property->id)->first();
        expect($deal)->not->toBeNull()
            ->and($deal->agent_id)->toBe($agent->id)
            ->and($deal->stage)->toBe('lead')
            ->and($deal->agent_intent)->toBe('selling')
            ->and((float) $deal->listing_value)->toBe(450000.00)
            ->and($deal->listing_bedrooms)->toBe(3)
            ->and($deal->listing_sqft)->toBe(2200);

        // Draft estimate created
        $estimate = Estimate::where('deal_id', $deal->id)->first();
        expect($estimate)->not->toBeNull()
            ->and($estimate->status)->toBe('draft')
            ->and($estimate->title)->toContain('742 Evergreen Terrace');
    });

    it('notifies internal team when agent submits listing', function () {
        Notification::fake();

        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '123 Notification St')
            ->set('city', 'Denver')
            ->set('state', 'CO')
            ->set('zip', '80202')
            ->set('intent', 'selling')
            ->call('save')
            ->assertHasNoErrors();

        Notification::assertSentTo($internal, AgentListingSubmitted::class);
    });

    it('sets deal title from address and city', function () {
        Notification::fake();

        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '555 Broadway')
            ->set('city', 'Boulder')
            ->set('state', 'CO')
            ->set('zip', '80301')
            ->set('intent', 'buying')
            ->call('save');

        $deal = Deal::where('agent_id', $agent->id)->first();
        expect($deal->title)->toBe('555 Broadway, Boulder');
    });

    it('validates intent is required', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '123 Test St')
            ->set('city', 'Denver')
            ->set('state', 'CO')
            ->set('zip', '80202')
            ->set('intent', 'invalid')
            ->call('save')
            ->assertHasErrors(['intent']);
    });

    it('validates bedrooms range', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '123 Test St')
            ->set('city', 'Denver')
            ->set('state', 'CO')
            ->set('zip', '80202')
            ->set('bedrooms', 15)
            ->set('intent', 'selling')
            ->call('save')
            ->assertHasErrors(['bedrooms']);
    });

    it('validates sqft range', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '123 Test St')
            ->set('city', 'Denver')
            ->set('state', 'CO')
            ->set('zip', '80202')
            ->set('sqft', 50)
            ->set('intent', 'selling')
            ->call('save')
            ->assertHasErrors(['sqft']);
    });

    it('creates deal with buying intent', function () {
        Notification::fake();

        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '999 Buyer Lane')
            ->set('city', 'Denver')
            ->set('state', 'CO')
            ->set('zip', '80202')
            ->set('intent', 'buying')
            ->set('bedrooms', 4)
            ->set('sqft', 3000)
            ->call('save')
            ->assertHasNoErrors();

        $deal = Deal::where('agent_id', $agent->id)->first();
        expect($deal->agent_intent)->toBe('buying')
            ->and($deal->listing_bedrooms)->toBe(4)
            ->and($deal->listing_sqft)->toBe(3000);
    });

    it('allows optional bedrooms and sqft', function () {
        Notification::fake();

        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->set('address', '111 Optional Fields')
            ->set('city', 'Denver')
            ->set('state', 'CO')
            ->set('zip', '80202')
            ->set('intent', 'selling')
            ->call('save')
            ->assertHasNoErrors();

        $deal = Deal::where('agent_id', $agent->id)->first();
        expect($deal->listing_bedrooms)->toBeNull()
            ->and($deal->listing_sqft)->toBeNull();
    });
});
