<?php

use App\Jobs\SendWelcomeSmsJob;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\ProjectPreview;
use App\Models\User;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Dispatch Tests — Deal & Customer observers
|--------------------------------------------------------------------------
*/

it('dispatches welcome sms when deal is created for customer with phone', function () {
    Queue::fake();
    $settings = CompanySetting::getInstance();
    $settings->update(['welcome_sms_enabled' => true]);

    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create([
        'phone' => '3035551234',
        'created_by' => $user->id,
    ]);

    Deal::factory()->create([
        'customer_id' => $customer->id,
        'created_by' => $user->id,
    ]);

    // Deal observer dispatches with deal_id
    Queue::assertPushed(SendWelcomeSmsJob::class, function ($job) use ($customer) {
        return $job->phone === $customer->phone
            && $job->customerId === $customer->id
            && $job->dealId !== null;
    });
});

it('dispatches welcome sms from customer created observer with delay', function () {
    Queue::fake();
    CompanySetting::getInstance()->update(['welcome_sms_enabled' => true]);

    $user = User::factory()->internal()->create();

    // Creating a customer with a phone should dispatch the job (no deal needed)
    $customer = Customer::factory()->create([
        'phone' => '3035551234',
        'created_by' => $user->id,
    ]);

    Queue::assertPushed(SendWelcomeSmsJob::class, function ($job) use ($customer) {
        return $job->phone === $customer->phone
            && $job->customerId === $customer->id
            && $job->dealId === null;
    });
});

it('does not dispatch welcome sms from deal observer when disabled in settings', function () {
    Queue::fake();
    $settings = CompanySetting::getInstance();
    $settings->update(['welcome_sms_enabled' => false]);

    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create([
        'phone' => '3035551234',
        'created_by' => $user->id,
    ]);

    Deal::factory()->create([
        'customer_id' => $customer->id,
        'created_by' => $user->id,
    ]);

    // Deal observer checks settings — should NOT dispatch with a deal_id when disabled
    Queue::assertNotPushed(SendWelcomeSmsJob::class, function ($job) {
        return $job->dealId !== null;
    });
});

it('does not dispatch welcome sms when customer has no phone', function () {
    Queue::fake();
    CompanySetting::getInstance()->update(['welcome_sms_enabled' => true]);

    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create([
        'phone' => null,
        'created_by' => $user->id,
    ]);

    Deal::factory()->create([
        'customer_id' => $customer->id,
        'created_by' => $user->id,
    ]);

    Queue::assertNotPushed(SendWelcomeSmsJob::class);
});

it('does not dispatch welcome sms from deal observer if already sent', function () {
    Queue::fake();
    CompanySetting::getInstance()->update(['welcome_sms_enabled' => true]);

    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create([
        'phone' => '3035551234',
        'welcome_sms_sent_at' => now(),
        'created_by' => $user->id,
    ]);

    Deal::factory()->create([
        'customer_id' => $customer->id,
        'created_by' => $user->id,
    ]);

    // Neither observer should dispatch since welcome_sms_sent_at is already set
    Queue::assertNotPushed(SendWelcomeSmsJob::class);
});

/*
|--------------------------------------------------------------------------
| Job Handle Tests — SendWelcomeSmsJob internals
|
| These tests create customers/deals with withoutEvents() to prevent
| the model observers from dispatching the job synchronously (which
| would consume the TwilioService mock before our manual handle() call).
|--------------------------------------------------------------------------
*/

it('sends sms with deal template including portal link', function () {
    CompanySetting::getInstance()->update([
        'welcome_sms_enabled' => true,
        'welcome_sms_with_deal' => 'Hi {name}! Welcome to {company}. Portal: {portal_link}',
    ]);

    $user = User::factory()->internal()->create();
    $customer = Customer::withoutEvents(function () use ($user) {
        return Customer::factory()->create([
            'phone' => '3035551234',
            'first_name' => 'John',
            'created_by' => $user->id,
        ]);
    });

    $deal = Deal::withoutEvents(function () use ($customer, $user) {
        return Deal::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $user->id,
        ]);
    });

    ProjectPreview::create([
        'customer_id' => $customer->id,
        'deal_id' => $deal->id,
        'phone' => $customer->phone,
        'first_name' => $customer->first_name,
        'last_name' => $customer->last_name,
        'email' => $customer->email,
        'address' => $customer->address,
        'status' => 'active',
    ]);

    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldReceive('isPhoneOptedOut')->andReturn(false);
    $twilio->shouldReceive('sendSms')->once()->withArgs(function ($to, $body, $context) {
        return $to === '3035551234' && str_contains($body, '/preview/');
    })->andReturn(['success' => true, 'sid' => 'SM_test', 'error' => null]);
    app()->instance(TwilioService::class, $twilio);

    $job = new SendWelcomeSmsJob('3035551234', $customer->id, null, $deal->id);
    $job->handle();

    $customer->refresh();
    expect($customer->welcome_sms_sent_at)->not->toBeNull();
});

it('substitutes name and company in deal template', function () {
    $settings = CompanySetting::getInstance();
    $settings->update([
        'welcome_sms_enabled' => true,
        'company_name' => 'Denver Painters',
        'welcome_sms_with_deal' => 'Hello {name}, thanks for choosing {company}! View: {portal_link}',
    ]);

    $user = User::factory()->internal()->create();
    $customer = Customer::withoutEvents(function () use ($user) {
        return Customer::factory()->create([
            'phone' => '3035551234',
            'first_name' => 'Alice',
            'created_by' => $user->id,
        ]);
    });

    $deal = Deal::withoutEvents(function () use ($customer, $user) {
        return Deal::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $user->id,
        ]);
    });

    ProjectPreview::create([
        'customer_id' => $customer->id,
        'deal_id' => $deal->id,
        'phone' => $customer->phone,
        'first_name' => $customer->first_name,
        'last_name' => $customer->last_name,
        'email' => $customer->email,
        'address' => $customer->address,
        'status' => 'active',
    ]);

    $sentBody = null;
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldReceive('isPhoneOptedOut')->andReturn(false);
    $twilio->shouldReceive('sendSms')->once()->withArgs(function ($to, $body, $context) use (&$sentBody) {
        $sentBody = $body;

        return true;
    })->andReturn(['success' => true, 'sid' => 'SM_test', 'error' => null]);
    app()->instance(TwilioService::class, $twilio);

    $job = new SendWelcomeSmsJob('3035551234', $customer->id, null, $deal->id);
    $job->handle();

    expect($sentBody)
        ->toContain('Alice')
        ->toContain('Denver Painters')
        ->toContain('/preview/');
});

it('sends sms with no-deal template when no deal exists', function () {
    CompanySetting::getInstance()->update([
        'welcome_sms_enabled' => true,
    ]);

    $user = User::factory()->internal()->create();
    $customer = Customer::withoutEvents(function () use ($user) {
        return Customer::factory()->create([
            'phone' => '3035551234',
            'first_name' => 'Jane',
            'created_by' => $user->id,
        ]);
    });

    $sentBody = null;
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldReceive('isPhoneOptedOut')->andReturn(false);
    $twilio->shouldReceive('sendSms')->once()->withArgs(function ($to, $body, $context) use (&$sentBody) {
        $sentBody = $body;

        return $to === '3035551234';
    })->andReturn(['success' => true, 'sid' => 'SM_test', 'error' => null]);
    app()->instance(TwilioService::class, $twilio);

    $job = new SendWelcomeSmsJob('3035551234', $customer->id);
    $job->handle();

    $customer->refresh();
    expect($customer->welcome_sms_sent_at)->not->toBeNull();
    // Default template contains "glad to have you"
    expect($sentBody)->toContain('glad to have you');
});

it('uses custom without-deal template when configured', function () {
    CompanySetting::getInstance()->update([
        'welcome_sms_enabled' => true,
        'company_name' => 'Test Co',
        'welcome_sms_without_deal' => 'Hey {name}! {company} here. Let us know how we can help!',
    ]);

    $user = User::factory()->internal()->create();
    $customer = Customer::withoutEvents(function () use ($user) {
        return Customer::factory()->create([
            'phone' => '3035551234',
            'first_name' => 'Bob',
            'created_by' => $user->id,
        ]);
    });

    $sentBody = null;
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldReceive('isPhoneOptedOut')->andReturn(false);
    $twilio->shouldReceive('sendSms')->once()->withArgs(function ($to, $body, $context) use (&$sentBody) {
        $sentBody = $body;

        return true;
    })->andReturn(['success' => true, 'sid' => 'SM_test', 'error' => null]);
    app()->instance(TwilioService::class, $twilio);

    $job = new SendWelcomeSmsJob('3035551234', $customer->id);
    $job->handle();

    expect($sentBody)
        ->toContain('Bob')
        ->toContain('Test Co')
        ->toContain('Let us know how we can help');
});

it('does not send sms if phone is opted out', function () {
    CompanySetting::getInstance()->update(['welcome_sms_enabled' => true]);

    $user = User::factory()->internal()->create();
    $customer = Customer::withoutEvents(function () use ($user) {
        return Customer::factory()->create([
            'phone' => '3035551234',
            'created_by' => $user->id,
        ]);
    });

    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldReceive('isPhoneOptedOut')->with('3035551234')->andReturn(true);
    $twilio->shouldNotReceive('sendSms');
    app()->instance(TwilioService::class, $twilio);

    $job = new SendWelcomeSmsJob('3035551234', $customer->id);
    $job->handle();

    $customer->refresh();
    expect($customer->welcome_sms_sent_at)->toBeNull();
});

it('does not send sms if twilio is not enabled', function () {
    CompanySetting::getInstance()->update(['welcome_sms_enabled' => true]);

    $user = User::factory()->internal()->create();
    $customer = Customer::withoutEvents(function () use ($user) {
        return Customer::factory()->create([
            'phone' => '3035551234',
            'created_by' => $user->id,
        ]);
    });

    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(false);
    $twilio->shouldNotReceive('sendSms');
    app()->instance(TwilioService::class, $twilio);

    $job = new SendWelcomeSmsJob('3035551234', $customer->id);
    $job->handle();

    $customer->refresh();
    expect($customer->welcome_sms_sent_at)->toBeNull();
});

it('does not send sms if welcome sms is disabled in settings', function () {
    CompanySetting::getInstance()->update(['welcome_sms_enabled' => false]);

    $user = User::factory()->internal()->create();
    $customer = Customer::withoutEvents(function () use ($user) {
        return Customer::factory()->create([
            'phone' => '3035551234',
            'created_by' => $user->id,
        ]);
    });

    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldNotReceive('isEnabled');
    $twilio->shouldNotReceive('sendSms');
    app()->instance(TwilioService::class, $twilio);

    $job = new SendWelcomeSmsJob('3035551234', $customer->id);
    $job->handle();

    $customer->refresh();
    expect($customer->welcome_sms_sent_at)->toBeNull();
});

it('does not send sms if customer already received welcome sms', function () {
    CompanySetting::getInstance()->update(['welcome_sms_enabled' => true]);

    $user = User::factory()->internal()->create();
    $customer = Customer::withoutEvents(function () use ($user) {
        return Customer::factory()->create([
            'phone' => '3035551234',
            'welcome_sms_sent_at' => now(),
            'created_by' => $user->id,
        ]);
    });

    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldNotReceive('sendSms');
    app()->instance(TwilioService::class, $twilio);

    $job = new SendWelcomeSmsJob('3035551234', $customer->id);
    $job->handle();

    // welcome_sms_sent_at should remain unchanged (not re-set)
    expect($customer->fresh()->welcome_sms_sent_at)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Agent User Tests
|--------------------------------------------------------------------------
*/

it('sends welcome sms to agent user', function () {
    CompanySetting::getInstance()->update(['welcome_sms_enabled' => true]);

    $agent = User::factory()->agent()->create(['phone' => '3035559999']);

    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldReceive('isPhoneOptedOut')->andReturn(false);
    $twilio->shouldReceive('sendSms')->once()->andReturn(['success' => true, 'sid' => 'SM_test', 'error' => null]);
    app()->instance(TwilioService::class, $twilio);

    $job = new SendWelcomeSmsJob('3035559999', null, $agent->id);
    $job->handle();

    $agent->refresh();
    expect($agent->welcome_sms_sent_at)->not->toBeNull();
});

it('does not send welcome sms to agent user if already sent', function () {
    CompanySetting::getInstance()->update(['welcome_sms_enabled' => true]);

    $agent = User::factory()->agent()->create([
        'phone' => '3035559999',
        'welcome_sms_sent_at' => now(),
    ]);

    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldNotReceive('sendSms');
    app()->instance(TwilioService::class, $twilio);

    $job = new SendWelcomeSmsJob('3035559999', null, $agent->id);
    $job->handle();
});

it('uses first name from user name for agent sms', function () {
    CompanySetting::getInstance()->update([
        'welcome_sms_enabled' => true,
        'welcome_sms_without_deal' => 'Welcome {name} to {company}!',
        'company_name' => 'Apex Painters',
    ]);

    $agent = User::factory()->agent()->create([
        'name' => 'Sarah Johnson',
        'phone' => '3035559999',
    ]);

    $sentBody = null;
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldReceive('isPhoneOptedOut')->andReturn(false);
    $twilio->shouldReceive('sendSms')->once()->withArgs(function ($to, $body, $context) use (&$sentBody) {
        $sentBody = $body;

        return true;
    })->andReturn(['success' => true, 'sid' => 'SM_test', 'error' => null]);
    app()->instance(TwilioService::class, $twilio);

    $job = new SendWelcomeSmsJob('3035559999', null, $agent->id);
    $job->handle();

    expect($sentBody)
        ->toContain('Sarah')
        ->toContain('Apex Painters');
});

/*
|--------------------------------------------------------------------------
| Edge Cases
|--------------------------------------------------------------------------
*/

it('falls back to default deal template when custom template is null', function () {
    CompanySetting::getInstance()->update([
        'welcome_sms_enabled' => true,
        'welcome_sms_with_deal' => null,
    ]);

    $user = User::factory()->internal()->create();
    $customer = Customer::withoutEvents(function () use ($user) {
        return Customer::factory()->create([
            'phone' => '3035551234',
            'first_name' => 'Mike',
            'created_by' => $user->id,
        ]);
    });

    $deal = Deal::withoutEvents(function () use ($customer, $user) {
        return Deal::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $user->id,
        ]);
    });

    ProjectPreview::create([
        'customer_id' => $customer->id,
        'deal_id' => $deal->id,
        'phone' => $customer->phone,
        'first_name' => $customer->first_name,
        'last_name' => $customer->last_name,
        'email' => $customer->email,
        'address' => $customer->address,
        'status' => 'active',
    ]);

    $sentBody = null;
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldReceive('isPhoneOptedOut')->andReturn(false);
    $twilio->shouldReceive('sendSms')->once()->withArgs(function ($to, $body, $context) use (&$sentBody) {
        $sentBody = $body;

        return true;
    })->andReturn(['success' => true, 'sid' => 'SM_test', 'error' => null]);
    app()->instance(TwilioService::class, $twilio);

    $job = new SendWelcomeSmsJob('3035551234', $customer->id, null, $deal->id);
    $job->handle();

    // Default template includes "project portal is ready"
    expect($sentBody)->toContain('project portal is ready');
});

it('falls back to without-deal template when deal has no project preview', function () {
    CompanySetting::getInstance()->update([
        'welcome_sms_enabled' => true,
    ]);

    $user = User::factory()->internal()->create();
    $customer = Customer::withoutEvents(function () use ($user) {
        return Customer::factory()->create([
            'phone' => '3035551234',
            'first_name' => 'Sam',
            'created_by' => $user->id,
        ]);
    });

    $deal = Deal::withoutEvents(function () use ($customer, $user) {
        return Deal::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $user->id,
        ]);
    });

    // Intentionally NOT creating a ProjectPreview
    $sentBody = null;
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldReceive('isPhoneOptedOut')->andReturn(false);
    $twilio->shouldReceive('sendSms')->once()->withArgs(function ($to, $body, $context) use (&$sentBody) {
        $sentBody = $body;

        return true;
    })->andReturn(['success' => true, 'sid' => 'SM_test', 'error' => null]);
    app()->instance(TwilioService::class, $twilio);

    $job = new SendWelcomeSmsJob('3035551234', $customer->id, null, $deal->id);
    $job->handle();

    // Should fall back to without-deal template
    expect($sentBody)->toContain('glad to have you');
});

it('uses "there" as name when customer has no first name', function () {
    CompanySetting::getInstance()->update([
        'welcome_sms_enabled' => true,
        'welcome_sms_without_deal' => 'Hi {name}, welcome!',
    ]);

    $user = User::factory()->internal()->create();
    $customer = Customer::withoutEvents(function () use ($user) {
        return Customer::factory()->create([
            'phone' => '3035551234',
            'first_name' => '',
            'created_by' => $user->id,
        ]);
    });

    $sentBody = null;
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldReceive('isPhoneOptedOut')->andReturn(false);
    $twilio->shouldReceive('sendSms')->once()->withArgs(function ($to, $body, $context) use (&$sentBody) {
        $sentBody = $body;

        return true;
    })->andReturn(['success' => true, 'sid' => 'SM_test', 'error' => null]);
    app()->instance(TwilioService::class, $twilio);

    $job = new SendWelcomeSmsJob('3035551234', $customer->id);
    $job->handle();

    expect($sentBody)->toContain('Hi there');
});
