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

describe('Customer No Pricing', function () {
    it('carousel does not show pricing for draft estimate areas', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->draft()->create([
            'total' => 5000.00,
        ]);
        EstimateArea::factory()->forEstimate($estimate)->create([
            'name' => 'Living Room',
            'cached_customer_price' => 1500.00,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $estimate])
            ->assertSee('Living Room')
            ->assertDontSee('1,500')
            ->assertDontSee('$1,500');
    });

    it('carousel shows surface names but not costs', function () {
        $estimate = Estimate::factory()->forDeal($this->deal)->draft()->create();
        EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Kitchen']);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.estimates.area-carousel', ['estimate' => $estimate])
            ->assertSee('Kitchen')
            ->assertDontSee('getCustomerPrice');
    });

    it('dashboard does not show price for draft estimates', function () {
        Estimate::factory()->forDeal($this->deal)->draft()->create([
            'total' => 3500.00,
            'subtotal' => 3500.00,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertDontSee('3,500.00')
            ->assertSee('Help Build Your Estimate');
    });

    it('dashboard shows price only for sent estimates', function () {
        Estimate::factory()->forDeal($this->deal)->sent()->create([
            'total' => 4200.00,
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('4,200.00');
    });
});
