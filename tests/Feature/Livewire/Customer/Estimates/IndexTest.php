<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use Livewire\Livewire;

describe('Customer Estimates Index component', function () {
    it('renders the estimates index for authenticated customers', function () {
        $customerUser = User::factory()->customer()->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.index')
            ->assertStatus(200)
            ->assertSee('Estimates');
    });

    it('displays customer estimates', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Kitchen Renovation',
        ]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
            'estimate_number' => 'EST-2026-00001',
            'total' => 5000.00,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.index')
            ->assertSee('EST-2026-00001')
            ->assertSee('Kitchen Renovation')
            ->assertSee('$5,000.00')
            ->assertSee('Pending');
    });

    it('does not display draft estimates', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'draft',
            'estimate_number' => 'EST-DRAFT-001',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.index')
            ->assertDontSee('EST-DRAFT-001');
    });

    it('does not display other customers estimates', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $otherCustomer = Customer::factory()->create();

        $myDeal = Deal::factory()->create(['customer_id' => $customer->id]);
        $otherDeal = Deal::factory()->create(['customer_id' => $otherCustomer->id]);

        Estimate::factory()->create([
            'deal_id' => $myDeal->id,
            'status' => 'sent',
            'estimate_number' => 'EST-MINE-001',
        ]);

        Estimate::factory()->create([
            'deal_id' => $otherDeal->id,
            'status' => 'sent',
            'estimate_number' => 'EST-OTHER-001',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.index')
            ->assertSee('EST-MINE-001')
            ->assertDontSee('EST-OTHER-001');
    });

    it('can search estimates by number', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
            'estimate_number' => 'EST-2026-00001',
        ]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
            'estimate_number' => 'EST-2026-00002',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.index')
            ->set('search', '00001')
            ->assertSee('EST-2026-00001')
            ->assertDontSee('EST-2026-00002');
    });

    it('can filter estimates by status', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
            'estimate_number' => 'EST-PENDING-001',
        ]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'accepted',
            'estimate_number' => 'EST-ACCEPTED-001',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.index')
            ->set('status', 'sent')
            ->assertSee('EST-PENDING-001')
            ->assertDontSee('EST-ACCEPTED-001');
    });

    it('displays different status badges', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
            'estimate_number' => 'EST-SENT',
        ]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'accepted',
            'estimate_number' => 'EST-ACCEPTED',
        ]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'rejected',
            'estimate_number' => 'EST-REJECTED',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.index')
            ->assertSee('Pending')
            ->assertSee('Accepted')
            ->assertSee('Declined');
    });

    it('shows empty state when no estimates exist', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.index')
            ->assertSee('No estimates found')
            ->assertSee('Estimates will appear here once sent to you');
    });

    it('shows empty state when search yields no results', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
            'estimate_number' => 'EST-2026-00001',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.index')
            ->set('search', 'NonexistentEstimate')
            ->assertSee('No estimates found')
            ->assertSee('Try adjusting your search criteria');
    });

    it('resets pagination when search changes', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        Estimate::factory()
            ->count(20)
            ->sequence(fn ($sequence) => ['estimate_number' => 'EST-TEST-'.str_pad($sequence->index + 1, 5, '0', STR_PAD_LEFT)])
            ->create([
                'deal_id' => $deal->id,
                'status' => 'sent',
            ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.index')
            ->set('search', 'test')
            ->assertSet('paginators.page', 1);
    });

    it('resets pagination when status filter changes', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        Estimate::factory()
            ->count(20)
            ->sequence(fn ($sequence) => ['estimate_number' => 'EST-FILTER-'.str_pad($sequence->index + 1, 5, '0', STR_PAD_LEFT)])
            ->create([
                'deal_id' => $deal->id,
                'status' => 'sent',
            ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.index')
            ->set('status', 'sent')
            ->assertSet('paginators.page', 1);
    });
});
