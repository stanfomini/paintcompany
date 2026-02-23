<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use App\Notifications\EstimateRejected;
use Illuminate\Support\Facades\Notification;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('EstimateRejected Notification', function () {
    it('can be sent to internal users', function () {
        Notification::fake();

        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        $internalUser->notify(new EstimateRejected($estimate));

        Notification::assertSentTo($internalUser, EstimateRejected::class);
    });

    it('has correct email subject with estimate number', function () {
        $estimate = Estimate::factory()->create([
            'estimate_number' => 'EST-2026-00015',
        ]);

        $notification = new EstimateRejected($estimate);
        $mailMessage = $notification->toMail(User::factory()->make());

        expect($mailMessage->subject)->toBe('Estimate Rejected: EST-2026-00015');
    });

    it('includes customer name in body', function () {
        $customer = Customer::factory()->create([
            'first_name' => 'Charlie',
            'last_name' => 'Brown',
        ]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create();

        $notification = new EstimateRejected($estimate);
        $mailMessage = $notification->toMail(User::factory()->make());

        $foundCustomerName = false;
        foreach ($mailMessage->introLines as $line) {
            if (str_contains($line, 'Charlie Brown')) {
                $foundCustomerName = true;
                break;
            }
        }
        expect($foundCustomerName)->toBeTrue();
    });

    it('includes rejection reason when provided', function () {
        $estimate = Estimate::factory()->create();

        $notification = new EstimateRejected($estimate, 'Price is too high for my budget');
        $mailMessage = $notification->toMail(User::factory()->make());

        $foundReason = false;
        foreach ($mailMessage->introLines as $line) {
            if (str_contains($line, 'Price is too high for my budget')) {
                $foundReason = true;
                break;
            }
        }
        expect($foundReason)->toBeTrue();
    });

    it('works without rejection reason', function () {
        $estimate = Estimate::factory()->create();

        $notification = new EstimateRejected($estimate);
        $mailMessage = $notification->toMail(User::factory()->make());

        $foundReasonSection = false;
        foreach ($mailMessage->introLines as $line) {
            if (str_contains($line, 'Reason provided')) {
                $foundReasonSection = true;
                break;
            }
        }
        expect($foundReasonSection)->toBeFalse();
    });

    it('has link to admin estimate view', function () {
        $estimate = Estimate::factory()->create();

        $notification = new EstimateRejected($estimate);
        $mailMessage = $notification->toMail(User::factory()->make());

        expect($mailMessage->actionUrl)->toBe(route('estimates.show', $estimate));
    });

    it('implements ShouldQueue interface', function () {
        $estimate = Estimate::factory()->make();
        $notification = new EstimateRejected($estimate);

        expect($notification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    it('uses mail database and broadcast channels', function () {
        $estimate = Estimate::factory()->make();
        $notification = new EstimateRejected($estimate);
        $channels = $notification->via(User::factory()->make());

        expect($channels)->toBe(['mail', 'database', 'broadcast']);
    });

    it('converts to array with correct structure', function () {
        $customer = Customer::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'estimate_number' => 'EST-2026-00025',
            'total' => 5000.00,
        ]);

        $notification = new EstimateRejected($estimate, 'Too expensive');
        $array = $notification->toArray(User::factory()->make());

        expect($array)->toHaveKeys(['estimate_id', 'estimate_number', 'total', 'customer_name', 'reason']);
        expect($array['estimate_number'])->toBe('EST-2026-00025');
        expect($array['customer_name'])->toBe('Test User');
        expect($array['reason'])->toBe('Too expensive');
    });

    it('stores null reason when not provided', function () {
        $estimate = Estimate::factory()->make();
        $notification = new EstimateRejected($estimate);

        expect($notification->reason)->toBeNull();
    });
});
