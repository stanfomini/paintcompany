<?php

use App\Jobs\SendSmsThreadReplyJob;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\SmsSession;
use App\Models\User;
use App\Services\TwilioService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('sends MMS when internal user replies to SMS thread with image attachment', function () {
    $internalUser = User::factory()->internal()->create(['name' => 'Jane']);
    $thread = ChatThread::factory()->create([
        'type' => ChatThread::TYPE_SUPPORT,
        'source' => ChatThread::SOURCE_SMS_INBOUND,
    ]);
    $thread->addParticipant($internalUser);

    // Create SMS session for the thread (guest sender)
    SmsSession::create([
        'user_id' => null,
        'thread_id' => $thread->id,
        'phone_number' => '+13035557777',
        'expires_at' => now()->addHours(24),
    ]);

    // Create a reply message with an image attachment
    $message = ChatMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $internalUser->id,
        'content' => 'Here is the color sample',
        'type' => ChatMessage::TYPE_IMAGE,
        'metadata' => [],
    ]);

    ChatAttachment::create([
        'message_id' => $message->id,
        'path' => 'chat-attachments/test-viz.jpg',
        'filename' => 'test-viz.jpg',
        'original_filename' => 'color-sample.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 2048,
    ]);

    // Mock TwilioService to verify MMS is sent
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('prependConsentIfNeeded')->andReturnUsing(fn ($userId, $body) => $body);
    $twilio->shouldReceive('sendMms')
        ->once()
        ->withArgs(function ($phone, $body, $mediaUrls) {
            return $phone === '+13035557777'
                && str_contains($body, 'Here is the color sample')
                && count($mediaUrls) === 1;
        })
        ->andReturn(['success' => true, 'sid' => 'SM789', 'error' => null]);
    $twilio->shouldNotReceive('sendSms');
    app()->instance(TwilioService::class, $twilio);

    $job = new SendSmsThreadReplyJob($message, $thread);
    $job->handle();
});

it('sends text-only SMS for non-image attachments', function () {
    $internalUser = User::factory()->internal()->create(['name' => 'Bob']);
    $thread = ChatThread::factory()->create([
        'type' => ChatThread::TYPE_SUPPORT,
        'source' => ChatThread::SOURCE_SMS_INBOUND,
    ]);
    $thread->addParticipant($internalUser);

    SmsSession::create([
        'user_id' => null,
        'thread_id' => $thread->id,
        'phone_number' => '+13035558888',
        'expires_at' => now()->addHours(24),
    ]);

    // Create a message with a PDF attachment (not an image)
    $message = ChatMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $internalUser->id,
        'content' => 'Here is the estimate PDF',
        'type' => ChatMessage::TYPE_FILE,
        'metadata' => [],
    ]);

    ChatAttachment::create([
        'message_id' => $message->id,
        'path' => 'chat-attachments/estimate.pdf',
        'filename' => 'estimate.pdf',
        'original_filename' => 'estimate.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 4096,
    ]);

    // Mock TwilioService to verify regular SMS is sent (not MMS)
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('prependConsentIfNeeded')->andReturnUsing(fn ($userId, $body) => $body);
    $twilio->shouldReceive('sendSms')
        ->once()
        ->withArgs(function ($phone, $body) {
            return $phone === '+13035558888'
                && str_contains($body, 'Here is the estimate PDF');
        })
        ->andReturn(['success' => true, 'sid' => 'SM999', 'error' => null]);
    $twilio->shouldNotReceive('sendMms');
    app()->instance(TwilioService::class, $twilio);

    $job = new SendSmsThreadReplyJob($message, $thread);
    $job->handle();
});

it('sends MMS with multiple image attachments', function () {
    $internalUser = User::factory()->internal()->create(['name' => 'Alice']);
    $thread = ChatThread::factory()->create([
        'type' => ChatThread::TYPE_SUPPORT,
        'source' => ChatThread::SOURCE_SMS_INBOUND,
    ]);
    $thread->addParticipant($internalUser);

    SmsSession::create([
        'user_id' => null,
        'thread_id' => $thread->id,
        'phone_number' => '+13035559999',
        'expires_at' => now()->addHours(24),
    ]);

    $message = ChatMessage::create([
        'thread_id' => $thread->id,
        'user_id' => $internalUser->id,
        'content' => 'Two visualizations',
        'type' => ChatMessage::TYPE_IMAGE,
        'metadata' => [],
    ]);

    ChatAttachment::create([
        'message_id' => $message->id,
        'path' => 'chat-attachments/viz1.jpg',
        'filename' => 'viz1.jpg',
        'original_filename' => 'viz1.jpg',
        'mime_type' => 'image/jpeg',
        'file_size' => 1024,
    ]);

    ChatAttachment::create([
        'message_id' => $message->id,
        'path' => 'chat-attachments/viz2.png',
        'filename' => 'viz2.png',
        'original_filename' => 'viz2.png',
        'mime_type' => 'image/png',
        'file_size' => 2048,
    ]);

    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('prependConsentIfNeeded')->andReturnUsing(fn ($userId, $body) => $body);
    $twilio->shouldReceive('sendMms')
        ->once()
        ->withArgs(function ($phone, $body, $mediaUrls) {
            return $phone === '+13035559999'
                && count($mediaUrls) === 2;
        })
        ->andReturn(['success' => true, 'sid' => 'SM111', 'error' => null]);
    $twilio->shouldNotReceive('sendSms');
    app()->instance(TwilioService::class, $twilio);

    $job = new SendSmsThreadReplyJob($message, $thread);
    $job->handle();
});
