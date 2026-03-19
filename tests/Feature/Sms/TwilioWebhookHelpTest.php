<?php

use App\Models\CompanySetting;
use App\Services\TwilioService;

it('responds to HELP keyword with company info', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'company_name' => 'Apex Painting',
        'company_phone' => '303-555-1234',
        'company_email' => 'info@apex.com',
    ]);
    CompanySetting::clearInstanceCache();

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('sendSms')
            ->once()
            ->withArgs(function (string $to, string $body) {
                return str_contains($body, 'Apex Painting')
                    && str_contains($body, '303-555-1234')
                    && str_contains($body, 'info@apex.com')
                    && str_contains($body, 'Reply STOP to opt out');
            })
            ->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'HELP',
        'From' => '+15551234567',
    ]);

    $response->assertStatus(200);
});

it('responds to INFO keyword', function () {
    CompanySetting::clearInstanceCache();

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('sendSms')
            ->once()
            ->withArgs(function (string $to, string $body) {
                return str_contains($body, 'Reply STOP to opt out');
            })
            ->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'INFO',
        'From' => '+15551234567',
    ]);

    $response->assertStatus(200);
});

it('responds to lowercase help keyword', function () {
    CompanySetting::clearInstanceCache();

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('sendSms')
            ->once()
            ->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'help',
        'From' => '+15551234567',
    ]);

    $response->assertStatus(200);
});

it('handles HELP when no company settings exist', function () {
    // Clear any existing settings to test fallback
    CompanySetting::clearInstanceCache();
    CompanySetting::query()->delete();
    CompanySetting::clearInstanceCache();

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('sendSms')
            ->once()
            ->withArgs(function (string $to, string $body) {
                return str_contains($body, 'For help')
                    && str_contains($body, 'Reply STOP to opt out');
            })
            ->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'HELP',
        'From' => '+15551234567',
    ]);

    $response->assertStatus(200);
});
