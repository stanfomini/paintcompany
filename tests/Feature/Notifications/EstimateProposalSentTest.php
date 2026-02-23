<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Notifications\EstimateProposalSent;
use Illuminate\Support\Facades\Notification;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('EstimateProposalSent Notification', function () {
    it('can be sent to a customer', function () {
        Notification::fake();

        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create();
        $estimate->generatePublicToken();

        $customer->notify(new EstimateProposalSent($estimate));

        Notification::assertSentTo($customer, EstimateProposalSent::class);
    });

    it('contains estimate information', function () {
        Notification::fake();

        $customer = Customer::factory()->create(['first_name' => 'John']);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'estimate_number' => 'EST-2026-00001',
            'title' => 'Kitchen Painting',
            'total' => 1500.00,
        ]);
        $estimate->generatePublicToken();

        $customer->notify(new EstimateProposalSent($estimate));

        Notification::assertSentTo($customer, EstimateProposalSent::class, function ($notification) {
            expect($notification->estimate->estimate_number)->toBe('EST-2026-00001');
            expect($notification->estimate->title)->toBe('Kitchen Painting');
            expect((float) $notification->estimate->total)->toBe(1500.00);

            return true;
        });
    });

    it('has correct email subject', function () {
        $customer = Customer::factory()->make(['first_name' => 'John']);
        $estimate = Estimate::factory()->make();

        $notification = new EstimateProposalSent($estimate);
        $mailMessage = $notification->toMail($customer);

        expect($mailMessage->subject)->toContain('Your Estimate from');
    });

    it('greets customer by first name', function () {
        $customer = Customer::factory()->make(['first_name' => 'Jane']);
        $estimate = Estimate::factory()->make();

        $notification = new EstimateProposalSent($estimate);
        $mailMessage = $notification->toMail($customer);

        expect($mailMessage->greeting)->toBe('Hello Jane!');
    });

    it('includes public URL as action button when available', function () {
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create();
        $estimate->generatePublicToken();

        $notification = new EstimateProposalSent($estimate);
        $mailMessage = $notification->toMail($customer);

        expect($mailMessage->actionUrl)->toBe($estimate->getPublicUrl());
    });

    it('includes valid until date when set', function () {
        $customer = Customer::factory()->make();
        $estimate = Estimate::factory()->make([
            'valid_until' => '2026-12-31',
        ]);

        $notification = new EstimateProposalSent($estimate);
        $mailMessage = $notification->toMail($customer);

        $foundValidUntil = false;
        foreach ($mailMessage->introLines as $line) {
            if (str_contains($line, 'Valid Until')) {
                $foundValidUntil = true;
                break;
            }
        }
        expect($foundValidUntil)->toBeTrue();
    });

    it('implements ShouldQueue interface', function () {
        $estimate = Estimate::factory()->make();
        $notification = new EstimateProposalSent($estimate);

        expect($notification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    it('uses mail database and broadcast channels', function () {
        $customer = Customer::factory()->make();
        $estimate = Estimate::factory()->make();

        $notification = new EstimateProposalSent($estimate);
        $channels = $notification->via($customer);

        expect($channels)->toBe(['mail', 'database', 'broadcast']);
    });

    it('converts to array with correct structure', function () {
        $estimate = Estimate::factory()->make([
            'estimate_number' => 'EST-2026-00005',
            'total' => 2500.00,
        ]);

        $notification = new EstimateProposalSent($estimate);
        $array = $notification->toArray(Customer::factory()->make());

        expect($array)->toHaveKeys(['estimate_id', 'estimate_number', 'total']);
        expect($array['estimate_number'])->toBe('EST-2026-00005');
    });
});
