<?php

use App\Models\SmsLog;
use App\Models\User;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Config;

it('logs successful outbound SMS', function () {
    Config::set('services.twilio.enabled', true);
    Config::set('services.twilio.sid', 'test-sid');
    Config::set('services.twilio.token', 'test-token');
    Config::set('services.twilio.from', '+15559999999');

    $mockClient = Mockery::mock(\Twilio\Rest\Client::class);
    $mockMessages = Mockery::mock();
    $mockMessage = Mockery::mock();
    $mockMessage->sid = 'SM_test_123';
    $mockMessages->shouldReceive('create')->once()->andReturn($mockMessage);
    $mockClient->messages = $mockMessages;

    $service = new TwilioService;
    $reflection = new ReflectionClass($service);
    $prop = $reflection->getProperty('client');
    $prop->setAccessible(true);
    $prop->setValue($service, $mockClient);

    $result = $service->sendSms('+15551234567', 'Test message', ['message_type' => 'bot']);

    expect($result['success'])->toBeTrue();
    expect(SmsLog::where('phone', '+15551234567')->where('status', 'sent')->exists())->toBeTrue();

    $log = SmsLog::where('phone', '+15551234567')->first();
    expect($log->direction)->toBe('outbound');
    expect($log->body)->toBe('Test message');
    expect($log->message_type)->toBe('bot');
    expect($log->twilio_sid)->toBe('SM_test_123');
});

it('logs blocked SMS for opted out phone', function () {
    Config::set('services.twilio.enabled', true);
    Config::set('services.twilio.sid', 'test-sid');
    Config::set('services.twilio.token', 'test-token');
    Config::set('services.twilio.from', '+15559999999');

    $user = User::factory()->customer()->create([
        'phone' => '5551234567',
        'sms_opted_out_at' => now(),
    ]);

    $service = new TwilioService;
    $result = $service->sendSms('+15551234567', 'Test message');

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toBe('opted_out');

    $log = SmsLog::where('phone', '+15551234567')->where('status', 'blocked')->first();
    expect($log)->not->toBeNull();
    expect($log->direction)->toBe('outbound');
    expect($log->error)->toBe('Phone opted out');
});

it('logs inbound SMS from webhook', function () {
    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->with('+15551234567')->andReturn('+15551234567');
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'Hello there',
        'From' => '+15551234567',
        'MessageSid' => 'SM_inbound_123',
    ]);

    $response->assertStatus(200);

    $log = SmsLog::where('phone', '+15551234567')->where('direction', 'inbound')->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe('received');
    expect($log->body)->toBe('Hello there');
    expect($log->twilio_sid)->toBe('SM_inbound_123');
});

it('truncates body to 160 characters', function () {
    $longBody = str_repeat('A', 300);

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->with('+15551234567')->andReturn('+15551234567');
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => $longBody,
        'From' => '+15551234567',
    ]);

    $response->assertStatus(200);

    $log = SmsLog::where('phone', '+15551234567')->first();
    expect(strlen($log->body))->toBe(160);
});
