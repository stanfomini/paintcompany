<?php

use App\Jobs\SendWelcomeSmsJob;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\ProjectPreview;
use App\Models\User;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    CompanySetting::clearInstanceCache();
});

describe('SendWelcomeSmsJob', function () {
    it('sends welcome SMS with deal and portal link', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'welcome_sms_enabled' => true,
            'company_name' => 'Test Painting Co',
        ]);
        CompanySetting::clearInstanceCache();

        $customer = Customer::factory()->create([
            'first_name' => 'Jane',
            'phone' => '+15551234567',
        ]);

        // Deal::created auto-creates a ProjectPreview, so just create the deal
        $deal = Deal::factory()->forCustomer($customer)->create();
        $preview = ProjectPreview::where('deal_id', $deal->id)->first();

        $twilio = $this->mock(TwilioService::class);
        $twilio->shouldReceive('isEnabled')->andReturn(true);
        $twilio->shouldReceive('isPhoneOptedOut')->with('+15551234567')->andReturn(false);
        $twilio->shouldReceive('sendSms')
            ->once()
            ->withArgs(function ($phone, $message, $context) use ($preview) {
                return $phone === '+15551234567'
                    && str_contains($message, 'Jane')
                    && str_contains($message, 'Test Painting Co')
                    && str_contains($message, $preview->getPortalUrl())
                    && $context === ['context' => 'welcome_sms'];
            })
            ->andReturn(['success' => true]);

        $job = new SendWelcomeSmsJob('+15551234567', $customer->id, null, $deal->id);
        $job->handle();

        $customer->refresh();
        expect($customer->welcome_sms_sent_at)->not->toBeNull();
    });

    it('sends welcome SMS without deal', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'welcome_sms_enabled' => true,
            'company_name' => 'Test Painting Co',
        ]);
        CompanySetting::clearInstanceCache();

        $customer = Customer::factory()->create([
            'first_name' => 'Bob',
            'phone' => '+15559876543',
        ]);

        $twilio = $this->mock(TwilioService::class);
        $twilio->shouldReceive('isEnabled')->andReturn(true);
        $twilio->shouldReceive('isPhoneOptedOut')->with('+15559876543')->andReturn(false);
        $twilio->shouldReceive('sendSms')
            ->once()
            ->withArgs(function ($phone, $message, $context) {
                return $phone === '+15559876543'
                    && str_contains($message, 'Bob')
                    && str_contains($message, 'Test Painting Co')
                    && str_contains($message, 'glad to have you')
                    && $context === ['context' => 'welcome_sms'];
            })
            ->andReturn(['success' => true]);

        $job = new SendWelcomeSmsJob('+15559876543', $customer->id);
        $job->handle();

        $customer->refresh();
        expect($customer->welcome_sms_sent_at)->not->toBeNull();
    });

    it('skips when welcome SMS is disabled in settings', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['welcome_sms_enabled' => false]);
        CompanySetting::clearInstanceCache();

        $customer = Customer::factory()->create(['phone' => '+15551234567']);

        $twilio = $this->mock(TwilioService::class);
        $twilio->shouldNotReceive('sendSms');

        $job = new SendWelcomeSmsJob('+15551234567', $customer->id);
        $job->handle();

        $customer->refresh();
        expect($customer->welcome_sms_sent_at)->toBeNull();
    });

    it('skips when customer already received welcome SMS (idempotent)', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['welcome_sms_enabled' => true]);
        CompanySetting::clearInstanceCache();

        $customer = Customer::factory()->create([
            'phone' => '+15551234567',
            'welcome_sms_sent_at' => now(),
        ]);

        $twilio = $this->mock(TwilioService::class);
        $twilio->shouldNotReceive('sendSms');

        $job = new SendWelcomeSmsJob('+15551234567', $customer->id);
        $job->handle();
    });

    it('skips when user already received welcome SMS (idempotent)', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['welcome_sms_enabled' => true]);
        CompanySetting::clearInstanceCache();

        $user = User::factory()->agent()->create([
            'phone' => '+15551234567',
            'welcome_sms_sent_at' => now(),
        ]);

        $twilio = $this->mock(TwilioService::class);
        $twilio->shouldNotReceive('sendSms');

        $job = new SendWelcomeSmsJob('+15551234567', null, $user->id);
        $job->handle();
    });

    it('skips when Twilio is not enabled', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['welcome_sms_enabled' => true]);
        CompanySetting::clearInstanceCache();

        $customer = Customer::factory()->create(['phone' => '+15551234567']);

        $twilio = $this->mock(TwilioService::class);
        $twilio->shouldReceive('isEnabled')->andReturn(false);
        $twilio->shouldNotReceive('sendSms');

        $job = new SendWelcomeSmsJob('+15551234567', $customer->id);
        $job->handle();

        $customer->refresh();
        expect($customer->welcome_sms_sent_at)->toBeNull();
    });

    it('skips when phone is opted out', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['welcome_sms_enabled' => true]);
        CompanySetting::clearInstanceCache();

        $customer = Customer::factory()->create(['phone' => '+15551234567']);

        $twilio = $this->mock(TwilioService::class);
        $twilio->shouldReceive('isEnabled')->andReturn(true);
        $twilio->shouldReceive('isPhoneOptedOut')->with('+15551234567')->andReturn(true);
        $twilio->shouldNotReceive('sendSms');

        $job = new SendWelcomeSmsJob('+15551234567', $customer->id);
        $job->handle();

        $customer->refresh();
        expect($customer->welcome_sms_sent_at)->toBeNull();
    });

    it('uses custom template when configured', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'welcome_sms_enabled' => true,
            'company_name' => 'Acme Paint',
            'welcome_sms_without_deal' => 'Hey {name}, thanks for choosing {company}!',
        ]);
        CompanySetting::clearInstanceCache();

        $customer = Customer::factory()->create([
            'first_name' => 'Alice',
            'phone' => '+15551234567',
        ]);

        $twilio = $this->mock(TwilioService::class);
        $twilio->shouldReceive('isEnabled')->andReturn(true);
        $twilio->shouldReceive('isPhoneOptedOut')->andReturn(false);
        $twilio->shouldReceive('sendSms')
            ->once()
            ->withArgs(function ($phone, $message) {
                return $message === 'Hey Alice, thanks for choosing Acme Paint!';
            })
            ->andReturn(['success' => true]);

        $job = new SendWelcomeSmsJob('+15551234567', $customer->id);
        $job->handle();
    });

    it('updates both customer and user welcome_sms_sent_at when both provided', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['welcome_sms_enabled' => true]);
        CompanySetting::clearInstanceCache();

        $user = User::factory()->customer()->create(['phone' => '+15551234567']);
        $customer = Customer::factory()->create([
            'phone' => '+15551234567',
            'user_id' => $user->id,
        ]);

        $twilio = $this->mock(TwilioService::class);
        $twilio->shouldReceive('isEnabled')->andReturn(true);
        $twilio->shouldReceive('isPhoneOptedOut')->andReturn(false);
        $twilio->shouldReceive('sendSms')->once()->andReturn(['success' => true]);

        $job = new SendWelcomeSmsJob('+15551234567', $customer->id, $user->id);
        $job->handle();

        $customer->refresh();
        $user->refresh();
        expect($customer->welcome_sms_sent_at)->not->toBeNull();
        expect($user->welcome_sms_sent_at)->not->toBeNull();
    });

    it('falls through to without-deal template when deal has no preview', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'welcome_sms_enabled' => true,
            'company_name' => 'Test Co',
        ]);
        CompanySetting::clearInstanceCache();

        $customer = Customer::factory()->create([
            'first_name' => 'Sam',
            'phone' => '+15551234567',
        ]);

        $twilio = $this->mock(TwilioService::class);
        $twilio->shouldReceive('isEnabled')->andReturn(true);
        $twilio->shouldReceive('isPhoneOptedOut')->andReturn(false);
        $twilio->shouldReceive('sendSms')
            ->once()
            ->withArgs(function ($phone, $message) {
                return str_contains($message, 'glad to have you')
                    && ! str_contains($message, 'portal');
            })
            ->andReturn(['success' => true]);

        // Pass a deal_id that has no ProjectPreview
        $job = new SendWelcomeSmsJob('+15551234567', $customer->id, null, 99999);
        $job->handle();
    });
});

describe('Deal::created welcome SMS dispatch', function () {
    it('dispatches SendWelcomeSmsJob when deal is created for customer with phone', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['welcome_sms_enabled' => true]);
        CompanySetting::clearInstanceCache();

        $customer = Customer::factory()->create([
            'phone' => '+15551234567',
            'welcome_sms_sent_at' => null,
        ]);

        // Clear queue from customer creation
        Queue::fake();

        Deal::factory()->forCustomer($customer)->create();

        Queue::assertPushed(SendWelcomeSmsJob::class, function ($job) use ($customer) {
            return $job->phone === '+15551234567'
                && $job->customerId === $customer->id
                && $job->dealId !== null;
        });
    });

    it('does not dispatch when customer already received welcome SMS', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['welcome_sms_enabled' => true]);
        CompanySetting::clearInstanceCache();

        $customer = Customer::factory()->create([
            'phone' => '+15551234567',
            'welcome_sms_sent_at' => now(),
        ]);

        // Clear queue from customer creation
        Queue::fake();

        Deal::factory()->forCustomer($customer)->create();

        Queue::assertNotPushed(SendWelcomeSmsJob::class);
    });

    it('does not dispatch when customer has no phone', function () {
        $settings = CompanySetting::getInstance();
        $settings->update(['welcome_sms_enabled' => true]);
        CompanySetting::clearInstanceCache();

        $customer = Customer::factory()->create([
            'phone' => null,
            'welcome_sms_sent_at' => null,
        ]);

        // Clear queue from customer creation
        Queue::fake();

        Deal::factory()->forCustomer($customer)->create();

        Queue::assertNotPushed(SendWelcomeSmsJob::class);
    });
});

describe('Customer::created welcome SMS dispatch', function () {
    it('dispatches SendWelcomeSmsJob when customer is created with phone', function () {
        $customer = Customer::factory()->create([
            'phone' => '+15559999999',
            'welcome_sms_sent_at' => null,
        ]);

        Queue::assertPushed(SendWelcomeSmsJob::class, function ($job) use ($customer) {
            return $job->phone === '+15559999999'
                && $job->customerId === $customer->id
                && $job->dealId === null;
        });
    });

    it('does not dispatch when customer has no phone', function () {
        $customer = Customer::factory()->create([
            'phone' => null,
        ]);

        Queue::assertNotPushed(SendWelcomeSmsJob::class);
    });
});
