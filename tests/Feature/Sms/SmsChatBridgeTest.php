<?php

use App\Jobs\SendUnreadChatSmsJob;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\SmsSession;
use App\Models\User;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->sender = User::factory()->internal()->create(['name' => 'Alice Admin']);
    $this->recipient = User::factory()->customer()->create();
    $this->recipient->customerProfile()->update(['phone' => '5551234567']);
    $this->recipient->update([
        'notification_preferences' => [
            'chat' => ['email' => true, 'push' => true, 'sms' => true],
        ],
    ]);

    $this->thread = ChatThread::factory()->create();
    $this->thread->addParticipant($this->sender);
    $this->thread->addParticipant($this->recipient);

    // Leave last_read_at null so the message appears unread
    ChatParticipant::where('thread_id', $this->thread->id)
        ->where('user_id', $this->recipient->id)
        ->update(['last_read_at' => null]);

    $this->message = ChatMessage::factory()
        ->forThread($this->thread)
        ->fromUser($this->sender)
        ->withContent('Hey, your project starts Monday!')
        ->create();
});

it('sends sms for unread chat message', function () {
    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('formatE164')
            ->andReturnUsing(fn ($phone) => '+1'.$phone);
        $mock->shouldReceive('prependConsentIfNeeded')
            ->andReturnUsing(fn ($userId, $body) => $body);
        $mock->shouldReceive('sendSms')
            ->once()
            ->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
    });

    $job = new SendUnreadChatSmsJob($this->message, $this->recipient);
    $job->handle();
});

it('skips deleted messages', function () {
    // Force-delete removes the model from the database entirely,
    // setting exists=false on the serialized model after queue round-trip
    $message = $this->message;
    $message->forceDelete();

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('formatE164')->andReturnUsing(fn ($phone) => $phone ? '+1'.$phone : null);
        $mock->shouldNotReceive('sendSms');
    });

    $job = new SendUnreadChatSmsJob($message, $this->recipient);
    $job->handle();
});

it('skips system messages', function () {
    $systemMessage = ChatMessage::factory()
        ->forThread($this->thread)
        ->fromUser($this->sender)
        ->system()
        ->create();

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldNotReceive('sendSms');
    });

    $job = new SendUnreadChatSmsJob($systemMessage, $this->recipient);
    $job->handle();
});

it('skips already read messages', function () {
    // Mark the recipient as having read after the message was created
    ChatParticipant::where('thread_id', $this->thread->id)
        ->where('user_id', $this->recipient->id)
        ->update(['last_read_at' => now()->addMinute()]);

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('formatE164')->andReturnUsing(fn ($phone) => $phone ? '+1'.$phone : null);
        $mock->shouldNotReceive('sendSms');
    });

    $job = new SendUnreadChatSmsJob($this->message, $this->recipient);
    $job->handle();
});

it('throttles to one sms per thread per user per hour', function () {
    // Set the throttle cache key
    $cacheKey = "chat_sms:{$this->thread->id}:{$this->recipient->id}";
    Cache::put($cacheKey, true, now()->addHour());

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('formatE164')->andReturnUsing(fn ($phone) => $phone ? '+1'.$phone : null);
        $mock->shouldNotReceive('sendSms');
    });

    $job = new SendUnreadChatSmsJob($this->message, $this->recipient);
    $job->handle();
});

it('creates sms session on successful send', function () {
    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('formatE164')
            ->andReturnUsing(fn ($phone) => '+1'.$phone);
        $mock->shouldReceive('prependConsentIfNeeded')
            ->andReturnUsing(fn ($userId, $body) => $body);
        $mock->shouldReceive('sendSms')
            ->once()
            ->andReturn(['success' => true, 'sid' => 'SM456', 'error' => null]);
    });

    expect(SmsSession::where('user_id', $this->recipient->id)->exists())->toBeFalse();

    $job = new SendUnreadChatSmsJob($this->message, $this->recipient);
    $job->handle();

    $session = SmsSession::where('user_id', $this->recipient->id)
        ->where('thread_id', $this->thread->id)
        ->first();

    expect($session)->not->toBeNull()
        ->and($session->last_message_id)->toBe($this->message->id)
        ->and($session->expires_at)->not->toBeNull();
});

it('skips internal users', function () {
    $internalRecipient = User::factory()->internal()->create();
    $this->thread->addParticipant($internalRecipient);

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldNotReceive('sendSms');
    });

    $job = new SendUnreadChatSmsJob($this->message, $internalRecipient);
    $job->handle();
});

it('skips when user does not want sms for chat', function () {
    $this->recipient->update([
        'notification_preferences' => [
            'chat' => ['email' => true, 'push' => true, 'sms' => false],
        ],
    ]);

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldNotReceive('sendSms');
    });

    $job = new SendUnreadChatSmsJob($this->message, $this->recipient);
    $job->handle();
});

it('includes sender name and message preview in sms body', function () {
    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('formatE164')
            ->andReturnUsing(fn ($phone) => '+1'.$phone);
        $mock->shouldReceive('prependConsentIfNeeded')
            ->andReturnUsing(fn ($userId, $body) => $body);
        $mock->shouldReceive('sendSms')
            ->once()
            ->withArgs(function ($to, $body) {
                return str_contains($body, 'Alice Admin')
                    && str_contains($body, 'your project starts Monday');
            })
            ->andReturn(['success' => true, 'sid' => 'SM789', 'error' => null]);
    });

    $job = new SendUnreadChatSmsJob($this->message, $this->recipient);
    $job->handle();
});

it('does not send when recipient has no phone number', function () {
    // Clear the phone number
    $this->recipient->customerProfile()->update(['phone' => null]);

    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('formatE164')
            ->andReturnUsing(fn ($phone) => $phone ? '+1'.$phone : null);
        $mock->shouldNotReceive('sendSms');
    });

    $job = new SendUnreadChatSmsJob($this->message, $this->recipient);
    $job->handle();
});
