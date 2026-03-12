<?php

use App\Models\User;
use App\Services\TwilioService;

it('opts out user found via customer profile phone (no active session)', function () {
    $user = User::factory()->customer()->create();
    $user->customerProfile()->update(['phone' => '5551234567']);

    expect($user->fresh()->sms_opted_out_at)->toBeNull();

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->with('+15551234567')->andReturn('+15551234567');
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'STOP',
        'From' => '+15551234567',
    ]);

    $response->assertStatus(200);
    expect($user->fresh()->sms_opted_out_at)->not->toBeNull();
});

it('opts out user found via users.phone column (no active session)', function () {
    $user = User::factory()->customer()->create(['phone' => '5551234567']);

    expect($user->fresh()->sms_opted_out_at)->toBeNull();

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->with('+15551234567')->andReturn('+15551234567');
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'STOP',
        'From' => '+15551234567',
    ]);

    $response->assertStatus(200);
    expect($user->fresh()->sms_opted_out_at)->not->toBeNull();
});

it('opts in user found via profile phone (no active session)', function () {
    $user = User::factory()->customer()->create(['sms_opted_out_at' => now()]);
    $user->customerProfile()->update(['phone' => '5551234567']);

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->with('+15551234567')->andReturn('+15551234567');
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'START',
        'From' => '+15551234567',
    ]);

    $response->assertStatus(200);
    expect($user->fresh()->sms_opted_out_at)->toBeNull();
});

it('opts out crew user found via crew profile phone', function () {
    $user = User::factory()->crew()->create();
    $user->crewProfile()->update(['phone' => '5559876543']);

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->with('+15559876543')->andReturn('+15559876543');
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'STOP',
        'From' => '+15559876543',
    ]);

    $response->assertStatus(200);
    expect($user->fresh()->sms_opted_out_at)->not->toBeNull();
});
