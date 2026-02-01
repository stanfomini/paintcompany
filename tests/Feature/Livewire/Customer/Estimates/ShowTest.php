<?php

use App\Livewire\Customer\Estimates\Show;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\User;
use Livewire\Livewire;

describe('Customer Estimates Show component', function () {
    it('renders the estimate details for authenticated customer', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
            'estimate_number' => 'EST-2026-00001',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->assertStatus(200)
            ->assertSee('EST-2026-00001');
    });

    it('displays estimate details', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Kitchen Renovation',
        ]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
            'estimate_number' => 'EST-2026-00001',
            'title' => 'Full Kitchen Paint',
            'description' => 'Complete painting of kitchen area',
            'subtotal' => 4000.00,
            'tax_rate' => 8.00,
            'tax_amount' => 320.00,
            'discount_amount' => 200.00,
            'total' => 4120.00,
            'notes' => 'Includes premium paint',
            'terms' => '50% deposit required',
            'valid_until' => '2026-03-01',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->assertSee('EST-2026-00001')
            ->assertSee('Kitchen Renovation')
            ->assertSee('Full Kitchen Paint')
            ->assertSee('Complete painting of kitchen area')
            ->assertSee('$4,000.00')
            ->assertSee('$320.00')
            ->assertSee('$200.00')
            ->assertSee('$4,120.00')
            ->assertSee('Includes premium paint')
            ->assertSee('50% deposit required')
            ->assertSee('Mar 1, 2026');
    });

    it('cannot view another customers estimate', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $otherCustomer = Customer::factory()->create();
        $otherDeal = Deal::factory()->create(['customer_id' => $otherCustomer->id]);
        $otherEstimate = Estimate::factory()->create([
            'deal_id' => $otherDeal->id,
            'status' => 'sent',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $otherEstimate])
            ->assertNotFound();
    });

    it('cannot view draft estimates', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $draftEstimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'draft',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $draftEstimate])
            ->assertNotFound();
    });

    it('displays line items', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
        ]);

        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'description' => 'Wall Painting',
            'details' => 'Paint all walls',
            'quantity' => 500,
            'unit' => 'sq ft',
            'unit_price' => 5.00,
            'total' => 2500.00,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->assertSee('Line Items')
            ->assertSee('Wall Painting')
            ->assertSee('Paint all walls')
            ->assertSee('500')
            ->assertSee('sq ft')
            ->assertSee('$5.00')
            ->assertSee('$2,500.00');
    });

    it('shows accept and decline buttons for sent estimates', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->assertSee('Action Required')
            ->assertSee('Accept Estimate')
            ->assertSee('Decline');
    });

    it('does not show accept buttons for accepted estimates', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'accepted',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->assertDontSee('Action Required')
            ->assertDontSee('Accept Estimate');
    });

    it('can accept a sent estimate', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'stage' => 'lead',
        ]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->call('accept')
            ->assertSee('Estimate accepted successfully');

        $estimate->refresh();
        $deal->refresh();

        expect($estimate->status)->toBe('accepted');
        expect($estimate->accepted_at)->not->toBeNull();
        expect($deal->stage)->toBe('quoted');
    });

    it('can decline a sent estimate', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->call('decline')
            ->assertSee('Estimate declined');

        $estimate->refresh();

        expect($estimate->status)->toBe('rejected');
        expect($estimate->rejected_at)->not->toBeNull();
    });

    it('cannot accept an already accepted estimate', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'accepted',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->call('accept')
            ->assertSee('This estimate can no longer be accepted');
    });

    it('cannot decline an already rejected estimate', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'rejected',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->call('decline')
            ->assertSee('This estimate can no longer be declined');
    });

    it('opens accept confirmation modal', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->assertSet('showAcceptModal', false)
            ->call('confirmAccept')
            ->assertSet('showAcceptModal', true);
    });

    it('can cancel accept confirmation', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->call('confirmAccept')
            ->assertSet('showAcceptModal', true)
            ->call('cancelAccept')
            ->assertSet('showAcceptModal', false);
    });

    it('opens decline confirmation modal', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->assertSet('showDeclineModal', false)
            ->call('confirmDecline')
            ->assertSet('showDeclineModal', true);
    });

    it('can cancel decline confirmation', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->call('confirmDecline')
            ->assertSet('showDeclineModal', true)
            ->call('cancelDecline')
            ->assertSet('showDeclineModal', false);
    });

    it('does not update deal stage when accepting if deal is not lead', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'stage' => 'scheduled',
        ]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->call('accept');

        $deal->refresh();

        expect($deal->stage)->toBe('scheduled');
    });

    it('displays download PDF button', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test(Show::class, ['estimate' => $estimate])
            ->assertSee('Download PDF');
    });
});
