<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Invitation;
use App\Models\Property;
use App\Models\RoiCalculation;
use App\Models\User;
use App\Notifications\AgentPassedEstimate;
use App\Notifications\EstimateReadyForAgent;
use App\Services\PropertyValueCalculatorService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Agent ROI Integration Tests', function () {
    it('completes full selling agent flow: listing → dashboard → estimate → pass to customer', function () {
        Notification::fake();
        CompanySetting::getInstance();

        $agent = User::factory()->agent()->create(['needs_onboarding' => false]);
        $internal = User::factory()->internal()->create();

        // Step 1: Agent adds a listing via create-modal
        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->call('openModal')
            ->set('address', '123 Maple St')
            ->set('city', 'Denver')
            ->set('state', 'CO')
            ->set('zip', '80202')
            ->set('listingPrice', 500000)
            ->set('bedrooms', 4)
            ->set('sqft', 2500)
            ->set('intent', 'selling')
            ->call('save');

        // Verify property, deal, and estimate were created
        $property = Property::where('address', '123 Maple St')->first();
        expect($property)->not->toBeNull();

        $deal = Deal::where('property_id', $property->id)->first();
        expect($deal)->not->toBeNull();
        expect($deal->agent_id)->toBe($agent->id);
        expect($deal->agent_intent)->toBe('selling');
        expect((float) $deal->listing_value)->toEqual(500000.00);
        expect((int) $deal->listing_bedrooms)->toBe(4);
        expect((int) $deal->listing_sqft)->toBe(2500);
        expect($deal->stage)->toBe('lead');

        $estimate = Estimate::where('deal_id', $deal->id)->first();
        expect($estimate)->not->toBeNull();
        expect($estimate->status)->toBe('draft');

        // Step 2: Internal team marks estimate as sent
        $estimate->markAsSent();
        $estimate->refresh();
        expect($estimate->status)->toBe('sent');

        // Step 3: Agent gets notified
        Notification::assertSentTo($agent, EstimateReadyForAgent::class);

        // Step 4: Agent passes to customer via dashboard
        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('openPassModal', $deal->id)
            ->set('passEmail', 'buyer@example.com')
            ->set('passFirstName', 'John')
            ->set('passLastName', 'Buyer')
            ->call('passToCustomer');

        // Verify invitation was created
        $invitation = Invitation::where('email', 'buyer@example.com')->first();
        expect($invitation)->not->toBeNull();
    });

    it('completes buying agent flow with existing customer', function () {
        Notification::fake();
        CompanySetting::getInstance();

        $agent = User::factory()->agent()->create(['needs_onboarding' => false]);
        $customerUser = User::factory()->customer()->create(['email' => 'existing@buyer.com']);
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        // Agent adds listing with buying intent
        Livewire::actingAs($agent, 'agent')
            ->test('agent.listings.create-modal')
            ->call('openModal')
            ->set('address', '456 Oak Ave')
            ->set('city', 'Boulder')
            ->set('state', 'CO')
            ->set('zip', '80301')
            ->set('listingPrice', 650000)
            ->set('bedrooms', 3)
            ->set('sqft', 1800)
            ->set('intent', 'buying')
            ->call('save');

        $deal = Deal::where('agent_id', $agent->id)->latest()->first();
        expect($deal->agent_intent)->toBe('buying');

        // Agent passes to existing customer
        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('openPassModal', $deal->id)
            ->set('passEmail', 'existing@buyer.com')
            ->set('passFirstName', 'Jane')
            ->set('passLastName', 'Buyer')
            ->call('passToCustomer');

        // Deal should now have customer assigned
        $deal->refresh();
        expect($deal->customer_id)->toBe($customer->id);

        // Customer was notified
        Notification::assertSentTo($customerUser, AgentPassedEstimate::class);
    });

    it('calculates ROI from dashboard with auto-populate from listing', function () {
        CompanySetting::getInstance();

        $agent = User::factory()->agent()->create(['needs_onboarding' => false]);
        $property = Property::factory()->agentListing()->forAgent($agent)->create([
            'listing_price' => 500000,
            'square_footage' => 2000,
        ]);
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create([
            'listing_value' => 500000,
            'listing_sqft' => 2000,
            'listing_bedrooms' => 3,
            'roi_scope' => 'interior',
            'agent_intent' => 'selling',
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('populateFromListing');

        expect($component->get('roiPropertyValue'))->toBe(500000.0);
        expect($component->get('roiSqft'))->toBe(2000);
        expect($component->get('roiBedrooms'))->toBe(3);

        // Calculate ROI
        $component->call('calculateRoi');
        $results = $component->get('roiResults');

        expect($results)->not->toBeNull();
        expect($results['cost_low'])->toBeGreaterThan(0);
        expect($results['value_increase_low'])->toBeGreaterThan(0);
        expect($results['roi_percent_low'])->toBeGreaterThan(0);
    });

    it('calculates ROI correctly via service for different scopes', function () {
        CompanySetting::getInstance();
        $service = new PropertyValueCalculatorService;

        // Interior calculation
        $interior = $service->calculate(400000, 2000, 3, 'interior', 'selling');
        expect($interior['cost_low'])->toBeGreaterThan(0);
        expect($interior['cost_high'])->toBeGreaterThan($interior['cost_low']);
        expect($interior['roi_percent_low'])->toBeGreaterThan(0);

        // Exterior calculation
        $exterior = $service->calculate(400000, 2000, 3, 'exterior', 'selling');
        expect($exterior['cost_low'])->toBeGreaterThan(0);
        expect($exterior['value_increase_low'])->toBeGreaterThan(0);

        // Both scopes combined
        $both = $service->calculate(400000, 2000, 3, 'both', 'selling');
        expect($both['cost_low'])->toBeGreaterThan($interior['cost_low']);
        expect($both['cost_low'])->toBeGreaterThan($exterior['cost_low']);
    });

    it('saves ROI calculation to database', function () {
        CompanySetting::getInstance();

        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create([
            'listing_value' => 350000,
            'listing_sqft' => 1500,
            'listing_bedrooms' => 2,
            'agent_intent' => 'selling',
            'roi_scope' => 'interior',
        ]);

        $service = new PropertyValueCalculatorService;
        $results = $service->calculate(350000, 1500, 2, 'interior', 'selling');
        $calculation = $service->saveCalculation($deal, $agent, $results);

        expect($calculation)->toBeInstanceOf(RoiCalculation::class);
        expect($calculation->deal_id)->toBe($deal->id);
        expect($calculation->agent_id)->toBe($agent->id);
        expect((float) $calculation->estimated_cost_low)->toEqual($results['cost_low']);
        expect((float) $calculation->roi_percent_high)->toEqual($results['roi_percent_high']);

        // Generate public token
        $token = $calculation->generatePublicToken();
        expect($token)->toHaveLength(64);
        expect($calculation->isPublicTokenValid())->toBeTrue();
    });

    it('shows correct empty state for new agent', function () {
        $agent = User::factory()->agent()->create(['needs_onboarding' => false]);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('Welcome to Your Dashboard')
            ->assertSee('Add Your First Listing');
    });

    it('handles agent onboarding → first listing → dashboard flow', function () {
        CompanySetting::getInstance();

        // New agent needs onboarding
        $agent = User::factory()->agent()->create(['needs_onboarding' => true]);

        // Should be redirected to onboarding
        $this->actingAs($agent, 'agent')
            ->get(route('agent.dashboard'))
            ->assertRedirect(route('agent.onboarding'));

        // Complete onboarding step 1
        Livewire::actingAs($agent, 'agent')
            ->test('agent.onboarding')
            ->set('companyName', 'Test Realty')
            ->set('phone', '303-555-0123')
            ->call('nextStep')
            ->assertSet('step', 2);

        // Skip listing (step 2) and go to step 3
        Livewire::actingAs($agent, 'agent')
            ->test('agent.onboarding')
            ->set('step', 2)
            ->call('skipListing')
            ->assertSet('step', 3);

        // Complete onboarding
        Livewire::actingAs($agent, 'agent')
            ->test('agent.onboarding')
            ->set('step', 3)
            ->call('complete');

        $agent->refresh();
        expect($agent->needs_onboarding)->toBeFalsy();

        // Now agent can access dashboard
        $this->actingAs($agent, 'agent')
            ->get(route('agent.dashboard'))
            ->assertSuccessful();
    });
});
