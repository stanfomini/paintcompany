<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateOption;
use App\Models\EstimateSignature;
use App\Models\User;
use App\Notifications\EstimateAccepted;
use Illuminate\Support\Facades\Notification;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('EstimateAccepted Notification', function () {
    it('can be sent to internal users', function () {
        Notification::fake();

        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create();

        $internalUser->notify(new EstimateAccepted($estimate));

        Notification::assertSentTo($internalUser, EstimateAccepted::class);
    });

    it('has correct email subject with estimate number', function () {
        $estimate = Estimate::factory()->create([
            'estimate_number' => 'EST-2026-00010',
        ]);

        $notification = new EstimateAccepted($estimate);
        $mailMessage = $notification->toMail(User::factory()->make());

        expect($mailMessage->subject)->toBe('Estimate Accepted: EST-2026-00010');
    });

    it('includes customer name in body', function () {
        $customer = Customer::factory()->create([
            'first_name' => 'Alice',
            'last_name' => 'Johnson',
        ]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create();

        $notification = new EstimateAccepted($estimate);
        $mailMessage = $notification->toMail(User::factory()->make());

        $foundCustomerName = false;
        foreach ($mailMessage->introLines as $line) {
            if (str_contains($line, 'Alice Johnson')) {
                $foundCustomerName = true;
                break;
            }
        }
        expect($foundCustomerName)->toBeTrue();
    });

    it('includes signature details when available', function () {
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create();

        Notification::fake();

        EstimateSignature::factory()->create([
            'estimate_id' => $estimate->id,
            'signer_name' => 'Bob Smith',
            'signer_email' => 'bob@example.com',
        ]);

        $estimate->refresh();

        $notification = new EstimateAccepted($estimate);
        $mailMessage = $notification->toMail(User::factory()->make());

        $foundSignerName = false;
        $foundSignerEmail = false;
        foreach ($mailMessage->introLines as $line) {
            if (str_contains($line, 'Bob Smith')) {
                $foundSignerName = true;
            }
            if (str_contains($line, 'bob@example.com')) {
                $foundSignerEmail = true;
            }
        }

        expect($foundSignerName)->toBeTrue();
        expect($foundSignerEmail)->toBeTrue();
    });

    it('includes selected options when available', function () {
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create();

        EstimateOption::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Premium Paint Upgrade',
            'is_selected' => true,
            'fixed_price' => 250.00,
        ]);

        $notification = new EstimateAccepted($estimate);
        $mailMessage = $notification->toMail(User::factory()->make());

        $foundOption = false;
        foreach ($mailMessage->introLines as $line) {
            if (str_contains($line, 'Premium Paint Upgrade')) {
                $foundOption = true;
                break;
            }
        }
        expect($foundOption)->toBeTrue();
    });

    it('has link to admin estimate view', function () {
        $estimate = Estimate::factory()->create();

        $notification = new EstimateAccepted($estimate);
        $mailMessage = $notification->toMail(User::factory()->make());

        expect($mailMessage->actionUrl)->toBe(route('estimates.show', $estimate));
    });

    it('implements ShouldQueue interface', function () {
        $estimate = Estimate::factory()->make();
        $notification = new EstimateAccepted($estimate);

        expect($notification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    it('uses mail database and broadcast channels', function () {
        $estimate = Estimate::factory()->make();
        $notification = new EstimateAccepted($estimate);
        $channels = $notification->via(User::factory()->make());

        expect($channels)->toBe(['mail', 'database', 'broadcast']);
    });

    it('converts to array with correct structure', function () {
        $customer = Customer::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
        ]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'estimate_number' => 'EST-2026-00020',
            'total' => 3000.00,
        ]);

        $notification = new EstimateAccepted($estimate);
        $array = $notification->toArray(User::factory()->make());

        expect($array)->toHaveKeys(['estimate_id', 'estimate_number', 'total', 'customer_name']);
        expect($array['estimate_number'])->toBe('EST-2026-00020');
        expect($array['customer_name'])->toBe('Test Customer');
    });
});
