<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateOption;
use App\Models\EstimatePackage;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Proposal Preview Tab Tests
|--------------------------------------------------------------------------
|
| Tests for the rich Proposal tab in customer and agent estimate show pages.
| Validates that package cards, scope of work, pricing summary, and CTA
| render correctly using the shared partials in partials/proposal-preview/.
|
*/

describe('Customer Proposal Preview Tab', function () {
    beforeEach(function () {
        $this->customerUser = User::factory()->customer()->create();
        $this->customer = Customer::factory()->create(['user_id' => $this->customerUser->id]);
        $this->deal = Deal::factory()->forCustomer($this->customer)->create();
    });

    it('shows CTA button with link to full proposal', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 5000.00,
        ]);
        $estimate->generatePublicToken(365);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.show', ['estimate' => $estimate])
            ->assertSee('Review & Sign Your Proposal')
            ->assertSee('Review Full Proposal');
    });

    it('shows legacy package cards on proposal tab', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 5000.00,
        ]);
        $estimate->generatePublicToken(365);

        EstimatePackage::factory()->forEstimate($estimate)->basic()->withPricing(3000)->create([
            'name' => 'Good Package',
        ]);
        EstimatePackage::factory()->forEstimate($estimate)->standard()->recommended()->withPricing(4500)->create([
            'name' => 'Better Package',
        ]);
        EstimatePackage::factory()->forEstimate($estimate)->premium()->withPricing(6000)->create([
            'name' => 'Best Package',
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.show', ['estimate' => $estimate])
            ->assertSee('Package Options')
            ->assertSee('Good Package')
            ->assertSee('Better Package')
            ->assertSee('Best Package')
            ->assertSee('Recommended');
    });

    it('shows scope of work with areas on proposal tab', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 5000.00,
        ]);
        $estimate->generatePublicToken(365);

        $area = EstimateArea::factory()->forEstimate($estimate)->create([
            'name' => 'Living Room',
            'wall_sqft' => 400,
            'ceiling_sqft' => 200,
        ]);

        EstimateAreaSurface::factory()->forArea($area)->wall()->create([
            'name' => 'Wall Painting',
            'quantity' => 400,
            'is_included' => true,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.show', ['estimate' => $estimate])
            ->assertSee('Scope of Work')
            ->assertSee('Living Room')
            ->assertSee('Wall Painting');
    });

    it('shows pricing summary with total and deposit', function () {
        CompanySetting::getInstance()->update(['default_deposit_percent' => 50]);

        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 4000.00,
            'areas_subtotal' => 4000.00,
        ]);
        $estimate->generatePublicToken(365);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.show', ['estimate' => $estimate])
            ->assertSee('Pricing Summary')
            ->assertSee('$4,000.00')
            ->assertSee('Deposit Due')
            ->assertSee('$2,000.00')
            ->assertSee('Balance Due on Completion');
    });

    it('shows options as read-only badges when no packages', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 3000.00,
        ]);
        $estimate->generatePublicToken(365);

        EstimateOption::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Cabinet Refinishing',
            'fixed_price' => 800.00,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.show', ['estimate' => $estimate])
            ->assertSee('Cabinet Refinishing');
    });

    it('hides packages section when estimate has no packages', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 3000.00,
        ]);
        $estimate->generatePublicToken(365);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.show', ['estimate' => $estimate])
            ->assertDontSee('Package Options');
    });

    it('shows area notes in scope of work', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 5000.00,
        ]);
        $estimate->generatePublicToken(365);

        EstimateArea::factory()->forEstimate($estimate)->create([
            'name' => 'Kitchen',
            'notes' => 'Customer wants accent wall in navy blue',
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.show', ['estimate' => $estimate])
            ->assertSee('Customer wants accent wall in navy blue');
    });

    it('defaults to proposal tab for sent estimates', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 5000.00,
        ]);
        $estimate->generatePublicToken(365);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.show', ['estimate' => $estimate])
            ->assertSet('activeTab', 'proposal');
    });
});

describe('Agent Proposal Preview Tab', function () {
    beforeEach(function () {
        $this->agentUser = User::factory()->agent()->create();
        $this->customer = Customer::factory()->create();
        $this->deal = Deal::factory()
            ->forCustomer($this->customer)
            ->referredByAgent($this->agentUser)
            ->create();
    });

    it('shows CTA with link to full proposal', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 5000.00,
        ]);
        $estimate->generatePublicToken(365);

        Livewire::actingAs($this->agentUser, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->assertSee('View Full Proposal')
            ->assertSee('See exactly what your client will see');
    });

    it('shows legacy package cards', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 5000.00,
        ]);
        $estimate->generatePublicToken(365);

        EstimatePackage::factory()->forEstimate($estimate)->basic()->withPricing(3000)->create([
            'name' => 'Good Package',
        ]);
        EstimatePackage::factory()->forEstimate($estimate)->standard()->recommended()->withPricing(4500)->create([
            'name' => 'Better Package',
        ]);

        Livewire::actingAs($this->agentUser, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->assertSee('Package Options')
            ->assertSee('Good Package')
            ->assertSee('Better Package');
    });

    it('shows scope of work with areas and surfaces', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 5000.00,
        ]);
        $estimate->generatePublicToken(365);

        $area = EstimateArea::factory()->forEstimate($estimate)->create([
            'name' => 'Master Bedroom',
            'wall_sqft' => 350,
        ]);

        EstimateAreaSurface::factory()->forArea($area)->ceiling()->create([
            'name' => 'Ceiling Painting',
            'quantity' => 200,
            'is_included' => true,
        ]);

        Livewire::actingAs($this->agentUser, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->assertSee('Scope of Work')
            ->assertSee('Master Bedroom')
            ->assertSee('Ceiling Painting');
    });

    it('shows pricing summary', function () {
        CompanySetting::getInstance()->update(['default_deposit_percent' => 50]);

        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 6000.00,
            'areas_subtotal' => 6000.00,
        ]);
        $estimate->generatePublicToken(365);

        Livewire::actingAs($this->agentUser, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->assertSee('Pricing Summary')
            ->assertSee('$6,000.00');
    });

    it('hides packages section when no packages exist', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 3000.00,
        ]);
        $estimate->generatePublicToken(365);

        Livewire::actingAs($this->agentUser, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->assertDontSee('Package Options');
    });

    it('defaults to proposal tab for sent estimates', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 5000.00,
        ]);
        $estimate->generatePublicToken(365);

        Livewire::actingAs($this->agentUser, 'agent')
            ->test('agent.estimates.show', ['estimate' => $estimate])
            ->assertSet('activeTab', 'proposal');
    });
});
