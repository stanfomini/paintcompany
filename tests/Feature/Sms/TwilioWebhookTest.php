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

it('reuses existing sms thread when session has expired for unknown number', function () {
    User::factory()->internal()->create(['is_active' => true]);

    // Simulate a previous SMS thread that was created for this phone
    $existingThread = ChatThread::factory()->create([
        'type' => ChatThread::TYPE_SUPPORT,
        'source' => ChatThread::SOURCE_SMS_INBOUND,
        'subject' => 'SMS from +15559876543',
        'last_message_at' => now()->subDays(3),
    ]);

    // The old session has expired
    SmsSession::create([
        'user_id' => null,
        'thread_id' => $existingThread->id,
        'phone_number' => '+15559876543',
        'expires_at' => now()->subDay(),
    ]);

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->with('+15559876543')->andReturn('+15559876543');
    });

    $this->mock(ChatService::class, function ($mock) {
        $mock->shouldReceive('sendGuestMessage')->once();
    });

    $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'Hello again',
        'From' => '+15559876543',
    ]);

    // Should NOT create a new thread — should reuse the existing one
    $threads = ChatThread::where('source', ChatThread::SOURCE_SMS_INBOUND)
        ->where('subject', 'LIKE', '%5559876543%')
        ->get();

    expect($threads)->toHaveCount(1);
    expect($threads->first()->id)->toBe($existingThread->id);

    // A new session should be created pointing to the existing thread
    $newSession = SmsSession::where('phone_number', '+15559876543')
        ->where('expires_at', '>', now())
        ->first();

    expect($newSession)->not->toBeNull();
    expect($newSession->thread_id)->toBe($existingThread->id);
});

it('creates new thread for first-time sms from unknown number', function () {
    User::factory()->internal()->create(['is_active' => true]);

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->with('+15550001111')->andReturn('+15550001111');
    });

    $this->mock(ChatService::class, function ($mock) {
        $mock->shouldReceive('sendGuestMessage')->once();
    });

    // No existing thread for this phone
    expect(ChatThread::where('subject', 'LIKE', '%5550001111%')->count())->toBe(0);

    $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'First time here',
        'From' => '+15550001111',
    ]);

    $thread = ChatThread::where('source', ChatThread::SOURCE_SMS_INBOUND)
        ->where('subject', 'LIKE', '%5550001111%')
        ->first();

    expect($thread)->not->toBeNull();
    expect($thread->subject)->toBe('SMS from +15550001111');
    expect($thread->type)->toBe(ChatThread::TYPE_SUPPORT);
});

it('links customer to reused thread when customer was created after first sms', function () {
    User::factory()->internal()->create(['is_active' => true]);

    // Previous thread with no customer
    $existingThread = ChatThread::factory()->create([
        'type' => ChatThread::TYPE_SUPPORT,
        'source' => ChatThread::SOURCE_SMS_INBOUND,
        'subject' => 'SMS from +15553334444',
        'customer_id' => null,
        'last_message_at' => now()->subDays(2),
    ]);

    SmsSession::create([
        'user_id' => null,
        'thread_id' => $existingThread->id,
        'phone_number' => '+15553334444',
        'expires_at' => now()->subDay(),
    ]);

    // Customer was created after the first SMS (e.g., manually added)
    $customer = \App\Models\Customer::factory()->create([
        'phone' => '+15553334444',
        'user_id' => null,
    ]);

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true);
        $mock->shouldReceive('formatE164')->with('+15553334444')->andReturn('+15553334444');
    });

    $this->mock(ChatService::class, function ($mock) {
        $mock->shouldReceive('sendGuestMessage')->once();
    });

    $this->post(route('webhooks.twilio.sms'), [
        'Body' => 'Update on my project',
        'From' => '+15553334444',
    ]);

    $existingThread->refresh();
    expect($existingThread->customer_id)->toBe($customer->id);
});
