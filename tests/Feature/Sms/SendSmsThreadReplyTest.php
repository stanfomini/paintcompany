<?php

use App\Jobs\SendSmsThreadReplyJob;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\SmsSession;
use App\Models\User;
use App\Services\TwilioService;

describe('SendSmsThreadReplyJob', function () {
    it('sends reply to originating phone number for SMS inbound thread', function () {
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => ChatThread::SOURCE_SMS_INBOUND,
            'subject' => 'SMS from +15551234567',
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($internal);

        // Create SMS session (guest sender, no user_id)
        SmsSession::create([
            'user_id' => null,
            'thread_id' => $thread->id,
            'phone_number' => '+15551234567',
            'expires_at' => now()->addHours(24),
        ]);

        // Internal user sends a reply message
        $message = $thread->messages()->create([
            'user_id' => $internal->id,
            'content' => 'We can schedule your paint job next week.',
            'type' => ChatMessage::TYPE_TEXT,
        ]);

        $twilio = $this->mock(TwilioService::class, function ($mock) {
            $mock->shouldReceive('sendSms')
                ->once()
                ->with('+15551234567', \Mockery::on(fn ($body) => str_contains($body, 'We can schedule your paint job next week.')))
                ->andReturn(['success' => true, 'sid' => 'SM123']);
        });

        (new SendSmsThreadReplyJob($message, $thread))->handle();
    });

    it('does not send for non-SMS threads', function () {
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_DIRECT,
            'source' => null,
            'subject' => 'Direct chat',
            'last_message_at' => now(),
        ]);

        $message = $thread->messages()->create([
            'user_id' => $internal->id,
            'content' => 'Hello',
            'type' => ChatMessage::TYPE_TEXT,
        ]);

        $twilio = $this->mock(TwilioService::class, function ($mock) {
            $mock->shouldNotReceive('sendSms');
        });

        (new SendSmsThreadReplyJob($message, $thread))->handle();
    });

    it('does not send when no SMS session exists', function () {
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => ChatThread::SOURCE_SMS_INBOUND,
            'subject' => 'SMS from +15559999999',
            'last_message_at' => now(),
        ]);

        $message = $thread->messages()->create([
            'user_id' => $internal->id,
            'content' => 'Hello?',
            'type' => ChatMessage::TYPE_TEXT,
        ]);

        $twilio = $this->mock(TwilioService::class, function ($mock) {
            $mock->shouldNotReceive('sendSms');
        });

        (new SendSmsThreadReplyJob($message, $thread))->handle();
    });

    it('extends session expiry after successful send', function () {
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => ChatThread::SOURCE_SMS_INBOUND,
            'subject' => 'SMS from +15551234567',
            'last_message_at' => now(),
        ]);

        $session = SmsSession::create([
            'user_id' => null,
            'thread_id' => $thread->id,
            'phone_number' => '+15551234567',
            'expires_at' => now()->addHours(1), // Expiring soon
        ]);

        $message = $thread->messages()->create([
            'user_id' => $internal->id,
            'content' => 'Reply here',
            'type' => ChatMessage::TYPE_TEXT,
        ]);

        $this->mock(TwilioService::class, function ($mock) {
            $mock->shouldReceive('sendSms')->once()->andReturn(['success' => true, 'sid' => 'SM456']);
        });

        (new SendSmsThreadReplyJob($message, $thread))->handle();

        $session->refresh();
        expect($session->expires_at->gt(now()->addHours(23)))->toBeTrue();
        expect($session->last_message_id)->toBe($message->id);
    });

    it('sends reply to known user with consent prepend', function () {
        $internal = User::factory()->internal()->create();
        $customerUser = User::factory()->customer()->create();

        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => ChatThread::SOURCE_SMS_INBOUND,
            'subject' => 'SMS from +15557778888',
            'last_message_at' => now(),
        ]);

        // Session has a user_id (known customer)
        SmsSession::create([
            'user_id' => $customerUser->id,
            'thread_id' => $thread->id,
            'phone_number' => '+15557778888',
            'expires_at' => now()->addHours(24),
        ]);

        $message = $thread->messages()->create([
            'user_id' => $internal->id,
            'content' => 'Your estimate is ready.',
            'type' => ChatMessage::TYPE_TEXT,
        ]);

        $this->mock(TwilioService::class, function ($mock) use ($customerUser) {
            $mock->shouldReceive('prependConsentIfNeeded')
                ->once()
                ->with($customerUser->id, \Mockery::type('string'))
                ->andReturnUsing(fn ($id, $body) => $body);
            $mock->shouldReceive('sendSms')
                ->once()
                ->andReturn(['success' => true, 'sid' => 'SM789']);
        });

        (new SendSmsThreadReplyJob($message, $thread))->handle();
    });
});

describe('ChatService dispatches SMS reply for SMS threads', function () {
    it('dispatches SendSmsThreadReplyJob when internal user sends message in SMS thread', function () {
        \Illuminate\Support\Facades\Queue::fake([SendSmsThreadReplyJob::class]);

        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => ChatThread::SOURCE_SMS_INBOUND,
            'subject' => 'SMS from +15551234567',
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($internal);

        $chatService = app(\App\Services\ChatService::class);
        $chatService->sendMessage($thread, $internal, 'We will be there Monday.');

        \Illuminate\Support\Facades\Queue::assertPushed(SendSmsThreadReplyJob::class);
    });

    it('does not dispatch SendSmsThreadReplyJob for non-SMS threads', function () {
        \Illuminate\Support\Facades\Queue::fake([SendSmsThreadReplyJob::class]);

        $internal = User::factory()->internal()->create();
        $internal->assignRole('admin');

        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_DIRECT,
            'source' => null,
            'subject' => 'Direct chat',
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($internal);

        $other = User::factory()->internal()->create();
        $thread->addParticipant($other);

        $chatService = app(\App\Services\ChatService::class);
        $chatService->sendMessage($thread, $internal, 'Regular message');

        \Illuminate\Support\Facades\Queue::assertNotPushed(SendSmsThreadReplyJob::class);
    });

    it('does not dispatch SendSmsThreadReplyJob when customer sends message in SMS thread', function () {
        \Illuminate\Support\Facades\Queue::fake([SendSmsThreadReplyJob::class]);

        $customer = User::factory()->customer()->create();

        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'source' => ChatThread::SOURCE_SMS_INBOUND,
            'subject' => 'SMS from +15551234567',
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($customer);

        $chatService = app(\App\Services\ChatService::class);
        $chatService->sendMessage($thread, $customer, 'Customer reply');

        \Illuminate\Support\Facades\Queue::assertNotPushed(SendSmsThreadReplyJob::class);
    });
});
