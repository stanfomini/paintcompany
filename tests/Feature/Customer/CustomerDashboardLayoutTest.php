<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->customerUser = User::factory()->customer()->create();
    $this->customer = Customer::factory()->create(['user_id' => $this->customerUser->id]);
    $this->deal = Deal::factory()->forCustomer($this->customer)->create();
});

describe('Customer Dashboard Layout', function () {
    it('does not show the Need Help card', function () {
        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertDontSee('Need Help?');
    });

    it('does not show a Send a Message standalone section', function () {
        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertDontSee('Send a Message');
    });

    it('does not embed chat directly - chat dock is in the layout', function () {
        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertDontSee('Project Chat');
    });

    it('shows Add Rooms CTA when estimate exists but has no areas', function () {
        Estimate::factory()->forDeal($this->deal)->draft()->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Tell Us About Your Home');
    });

    it('does not show Add Rooms CTA when areas already exist', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->draft()->create();
        EstimateArea::factory()->forEstimate($estimate)->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertDontSee('Tell Us About Your Home');
    });

    it('does not show Add Rooms CTA for sent estimates', function () {
        Estimate::factory()->forDeal($this->deal)->sent()->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertDontSee('Tell Us About Your Home');
    });

    it('shows price button for sent estimates', function () {
        Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 5000.00,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('5,000.00')
            ->assertSee('View Your Full Proposal');
    });

    it('does not show price for draft estimates', function () {
        Estimate::factory()->forDeal($this->deal)->draft()->create([
            'total' => 5000.00,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertDontSee('5,000.00')
            ->assertSee('Help Build Your Estimate');
    });
});
