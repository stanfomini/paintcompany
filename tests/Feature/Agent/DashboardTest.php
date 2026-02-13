<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Agent Dashboard', function () {
    it('renders the dashboard for authenticated agent', function () {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent, 'agent')
            ->get(route('agent.dashboard'))
            ->assertSuccessful()
            ->assertSeeLivewire('agent.dashboard');
    });

    it('auto-selects first deal on mount', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();

        $deal1 = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create([
            'created_at' => now()->subDay(),
        ]);
        $deal2 = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create([
            'created_at' => now(),
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard');

        // Auto-selects the latest active deal
        expect($component->get('selectedDealId'))->toBe($deal2->id);
    });

    it('shows listing switcher with multiple deals', function () {
        $agent = User::factory()->agent()->create();

        $property1 = Property::factory()->agentListing()->forAgent($agent)->create([
            'address' => '100 Oak Ave',
        ]);
        $property2 = Property::factory()->agentListing()->forAgent($agent)->create([
            'address' => '200 Elm St',
        ]);

        Deal::factory()->referredByAgent($agent)->forProperty($property1)->lead()->create();
        Deal::factory()->referredByAgent($agent)->forProperty($property2)->quoted()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('100 Oak Ave')
            ->assertSee('200 Elm St')
            ->assertSee('Not Interested');
    });

    it('shows estimate draft status for selected deal', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create();
        Estimate::factory()->forDeal($deal)->draft()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('Our team is preparing your estimate');
    });

    it('shows estimate sent status for selected deal', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create([
            'customer_id' => null,
        ]);
        Estimate::factory()->forDeal($deal)->sent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('Estimate is ready! Pass it to your client.');
    });

    it('shows pass to customer button when estimate exists and no customer', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create([
            'customer_id' => null,
        ]);
        Estimate::factory()->forDeal($deal)->draft()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('Pass to Customer');
    });

    it('hides pass to customer button when deal has customer', function () {
        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->forCustomer($customer)->lead()->create();
        Estimate::factory()->forDeal($deal)->draft()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertDontSee('Pass to Customer');
    });

    it('calculates ROI with valid inputs', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->set('roiPropertyValue', 500000)
            ->set('roiSqft', 2000)
            ->set('roiBedrooms', 3)
            ->set('roiScope', 'interior')
            ->set('roiIntent', 'selling')
            ->call('calculateRoi');

        $results = $component->get('roiResults');

        expect($results)->not->toBeNull();
        expect($results)->toHaveKeys(['cost_low', 'cost_high', 'value_increase_low', 'value_increase_high', 'roi_percent_low', 'roi_percent_high']);
        expect($results['cost_low'])->toBeGreaterThan(0);
        expect($results['cost_high'])->toBeGreaterThan($results['cost_low']);
    });

    it('validates ROI inputs', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->set('roiPropertyValue', null)
            ->set('roiSqft', null)
            ->set('roiBedrooms', null)
            ->call('calculateRoi')
            ->assertHasErrors(['roiPropertyValue', 'roiSqft', 'roiBedrooms']);
    });

    it('populates ROI form from selected listing', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create([
            'square_footage' => 2500,
            'listing_price' => 450000,
        ]);
        $deal = Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create([
            'listing_value' => 500000,
            'listing_sqft' => 2200,
            'listing_bedrooms' => 4,
            'roi_scope' => 'exterior',
            'agent_intent' => 'buying',
        ]);

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->call('populateFromListing');

        expect($component->get('roiPropertyValue'))->toBe(500000.0);
        expect($component->get('roiSqft'))->toBe(2200);
        expect($component->get('roiBedrooms'))->toBe(4);
        expect($component->get('roiScope'))->toBe('exterior');
        expect($component->get('roiIntent'))->toBe('buying');
    });

    it('shows disclaimer text on ROI results', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->create();
        Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->set('roiPropertyValue', 450000)
            ->set('roiSqft', 2000)
            ->set('roiBedrooms', 3)
            ->set('roiScope', 'interior')
            ->set('roiIntent', 'selling')
            ->call('calculateRoi')
            ->assertSee('Estimates vary by area, property condition, and listing strategy');
    });

    it('shows empty state when no deals', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('Welcome to Your Dashboard')
            ->assertSee('Add Your First Listing');
    });

    it('shows stats section with metrics', function () {
        $agent = User::factory()->agent()->create();
        $property = Property::factory()->agentListing()->forAgent($agent)->active()->create();

        Deal::factory()->referredByAgent($agent)->forProperty($property)->lead()->count(2)->create();
        Deal::factory()->referredByAgent($agent)->forProperty($property)->signed()->create();
        Customer::factory()->count(3)->referredByAgent($agent)->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.dashboard')
            ->assertSee('Listings')
            ->assertSee('Active')
            ->assertSee('Customers')
            ->assertSee('Conversion')
            ->assertSee('Deal Trend');

        expect($component->get('totalListings'))->toBe(1);
        // 2 lead + 1 signed = 3 active deals (signed is not a past stage)
        expect($component->get('activeListings'))->toBe(3);
        expect($component->get('referredCustomers'))->toBe(3);
    });
});
