<?php

use App\Models\ChatThread;
use App\Models\SmsSession;
use App\Models\User;
use App\Services\ChatService;
use App\Services\TwilioService;

it('rejects requests with invalid signature', function () {
    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'Hello',
        'From' => '+15551234567',
    ]);

    $response->assertStatus(403);
});

it('returns twiml response for valid request', function () {
    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->andReturnUsing(fn ($phone) => $phone);
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'Hello there',
        'From' => '+15551234567',
    ]);

    $response->assertStatus(200);
    $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');
    expect($response->getContent())->toContain('<Response></Response>');
});

it('returns twiml for empty body', function () {
    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => '',
        'From' => '+15551234567',
    ]);

    $response->assertStatus(200);
    expect($response->getContent())->toContain('<Response></Response>');
});

it('handles STOP keyword for opt-out', function () {
    $user = User::factory()->customer()->create();

    // Create an SMS session so the user can be found by phone
    SmsSession::create([
        'user_id' => $user->id,
        'thread_id' => ChatThread::factory()->create()->id,
        'phone_number' => '+15551234567',
        'expires_at' => now()->addHours(24),
    ]);

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

it('handles START keyword for opt-in', function () {
    $user = User::factory()->customer()->create();
    $user->update(['sms_opted_out_at' => now()]);

    // Create an SMS session so the user can be found by phone
    SmsSession::create([
        'user_id' => $user->id,
        'thread_id' => ChatThread::factory()->create()->id,
        'phone_number' => '+15551234567',
        'expires_at' => now()->addHours(24),
    ]);

    expect($user->fresh()->sms_opted_out_at)->not->toBeNull();

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

it('handles UNSUBSCRIBE keyword as opt-out', function () {
    $user = User::factory()->customer()->create();

    SmsSession::create([
        'user_id' => $user->id,
        'thread_id' => ChatThread::factory()->create()->id,
        'phone_number' => '+15551234567',
        'expires_at' => now()->addHours(24),
    ]);

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->with('+15551234567')->andReturn('+15551234567');
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'UNSUBSCRIBE',
        'From' => '+15551234567',
    ]);

    $response->assertStatus(200);
    expect($user->fresh()->sms_opted_out_at)->not->toBeNull();
});

it('routes reply to active chat session', function () {
    $user = User::factory()->customer()->create();
    $thread = ChatThread::factory()->create();
    $thread->addParticipant($user);

    $session = SmsSession::create([
        'user_id' => $user->id,
        'thread_id' => $thread->id,
        'phone_number' => '+15551234567',
        'expires_at' => now()->addHours(24),
    ]);

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->with('+15551234567')->andReturn('+15551234567');
    });

    $chatServiceMock = $this->mock(ChatService::class, function ($mock) use ($thread, $user) {
        $mock->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function ($t, $s, $content, $options) use ($thread, $user) {
                return $t->id === $thread->id
                    && $s->id === $user->id
                    && $content === 'Hey, what time?'
                    && $options['metadata']['source'] === 'sms';
            });
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'Hey, what time?',
        'From' => '+15551234567',
    ]);

    $response->assertStatus(200);
});

it('ignores orphaned replies with no session', function () {
    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->with('+15559999999')->andReturn('+15559999999');
    });

    // No ChatService mock -- sendMessage should never be called
    $this->mock(ChatService::class, function ($mock) {
        $mock->shouldNotReceive('sendMessage');
    });

    $response = $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'Hello from unknown',
        'From' => '+15559999999',
    ]);

    $response->assertStatus(200);
    expect($response->getContent())->toContain('<Response></Response>');
});

it('extends session TTL after routing reply', function () {
    $user = User::factory()->customer()->create();
    $thread = ChatThread::factory()->create();
    $thread->addParticipant($user);

    $session = SmsSession::create([
        'user_id' => $user->id,
        'thread_id' => $thread->id,
        'phone_number' => '+15551234567',
        'expires_at' => now()->addHours(1),
    ]);

    $originalExpiry = $session->expires_at;

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->with('+15551234567')->andReturn('+15551234567');
    });

    $this->mock(ChatService::class, function ($mock) {
        $mock->shouldReceive('sendMessage')->once();
    });

    $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'Thanks!',
        'From' => '+15551234567',
    ]);

    $session->refresh();
    expect($session->expires_at->gt($originalExpiry))->toBeTrue();
});
