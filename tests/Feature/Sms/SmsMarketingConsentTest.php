<?php

use App\Automation\Actions\SendSMS;
use App\Automation\Registry;
use App\Models\Customer;
use App\Models\SmsBotFlow;
use App\Models\SmsConversation;
use App\Models\WorkflowRun;
use App\Services\SmsBotFlowService;
use App\Services\TwilioService;

it('skips bot conversation when customer lacks marketing consent', function () {
    $customer = Customer::factory()->create([
        'phone' => '5551234567',
        'sms_marketing_consent_at' => null,
    ]);

    $flow = SmsBotFlow::factory()->create([
        'status' => 'active',
        'trigger_type' => 'new_lead',
        'requires_marketing_consent' => true,
    ]);

    $twilioMock = $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldNotReceive('sendSms');
    });

    $service = new SmsBotFlowService($twilioMock);
    $result = $service->startConversation('+15551234567', $flow);

    expect($result)->toBeNull();
    expect(SmsConversation::count())->toBe(0);
});

it('allows bot conversation when flow does not require marketing consent', function () {
    $customer = Customer::factory()->create([
        'phone' => '5551234567',
        'sms_marketing_consent_at' => null,
    ]);

    $flow = SmsBotFlow::factory()->create([
        'status' => 'active',
        'trigger_type' => 'keyword',
        'requires_marketing_consent' => false,
    ]);

    $twilioMock = $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('sendSms')->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
    });

    $service = new SmsBotFlowService($twilioMock);
    $result = $service->startConversation('+15551234567', $flow);

    expect($result)->toBeInstanceOf(SmsConversation::class);
});

it('allows bot conversation when customer has marketing consent', function () {
    $customer = Customer::factory()->create([
        'phone' => '5551234567',
        'sms_marketing_consent_at' => now(),
    ]);

    $flow = SmsBotFlow::factory()->create([
        'status' => 'active',
        'trigger_type' => 'new_lead',
        'requires_marketing_consent' => true,
    ]);

    $twilioMock = $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('sendSms')->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
    });

    $service = new SmsBotFlowService($twilioMock);
    $result = $service->startConversation('+15551234567', $flow);

    expect($result)->toBeInstanceOf(SmsConversation::class);
});

it('SendSMS automation blocks when customer lacks marketing consent', function () {
    $customer = Customer::factory()->create([
        'phone' => '5551234567',
        'sms_marketing_consent_at' => null,
    ]);

    $run = Mockery::mock(WorkflowRun::class);

    $registryMock = Mockery::mock(Registry::class);
    $registryMock->shouldReceive('resolveDynamicValues')
        ->with('5551234567', $run)->andReturn('5551234567');
    $registryMock->shouldReceive('resolveDynamicValues')
        ->with('Hello customer', $run)->andReturn('Hello customer');

    app()->instance(Registry::class, $registryMock);

    $action = new SendSMS;
    $result = $action->execute($run, [
        'to' => '5551234567',
        'message' => 'Hello customer',
    ]);

    expect($result['sent'])->toBeFalse();
    expect($result['error'])->toBe('Customer lacks SMS marketing consent');
});
