<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use Livewire\Livewire;

describe('Customer Estimates Show component', function () {
    it('redirects to proposal page for valid estimate', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.show', ['estimate' => $estimate])
            ->assertRedirect();

        // Should have generated a public token
        $estimate->refresh();
        expect($estimate->public_token)->not->toBeNull();
    });

    it('redirects to the correct proposal URL', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
            'public_token' => 'test-token-123',
            'public_token_expires_at' => now()->addYear(),
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.show', ['estimate' => $estimate])
            ->assertRedirect(route('estimates.public', ['token' => 'test-token-123']));
    });

    it('generates token if estimate has no valid token', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'accepted',
            'public_token' => null,
            'public_token_expires_at' => null,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.show', ['estimate' => $estimate])
            ->assertRedirect();

        $estimate->refresh();
        expect($estimate->public_token)->not->toBeNull()
            ->and($estimate->public_token_expires_at)->not->toBeNull();
    });

    it('regenerates expired token', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
            'public_token' => 'old-expired-token',
            'public_token_expires_at' => now()->subDay(),
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.show', ['estimate' => $estimate])
            ->assertRedirect();

        $estimate->refresh();
        expect($estimate->public_token)->not->toBe('old-expired-token')
            ->and($estimate->public_token_expires_at->isFuture())->toBeTrue();
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
            ->test('customer.estimates.show', ['estimate' => $otherEstimate])
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
            ->test('customer.estimates.show', ['estimate' => $draftEstimate])
            ->assertNotFound();
    });

    it('redirects for accepted estimates', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'accepted',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.show', ['estimate' => $estimate])
            ->assertRedirect();
    });

    it('redirects for rejected estimates', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'rejected',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.estimates.show', ['estimate' => $estimate])
            ->assertRedirect();
    });
});
