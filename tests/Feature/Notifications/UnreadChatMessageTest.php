<?php

use App\Jobs\SendUnreadChatEmailJob;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use App\Notifications\UnreadChatMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Cache::flush();
});

it('sends email when message is unread after delay', function () {
    $sender = User::factory()->internal()->create();
    $recipient = User::factory()->internal()->create();
    $thread = ChatThread::factory()->direct()->create();
    $thread->addParticipant($sender);
    $thread->addParticipant($recipient);

    $message = ChatMessage::factory()->create([
        'thread_id' => $thread->id,
        'user_id' => $sender->id,
        'type' => 'text',
    ]);

    $job = new SendUnreadChatEmailJob($message, $recipient);
    $job->handle();

    Notification::assertSentTo($recipient, UnreadChatMessage::class);
});

it('does not send email when message has been read', function () {
    $sender = User::factory()->internal()->create();
    $recipient = User::factory()->internal()->create();
    $thread = ChatThread::factory()->direct()->create();
    $thread->addParticipant($sender);
    $participant = $thread->addParticipant($recipient);

    $message = ChatMessage::factory()->create([
        'thread_id' => $thread->id,
        'user_id' => $sender->id,
        'type' => 'text',
    ]);

    // Mark as read after message was created
    $participant->update(['last_read_at' => now()]);

    $job = new SendUnreadChatEmailJob($message, $recipient);
    $job->handle();

    Notification::assertNothingSent();
});

it('throttles emails per thread per user per hour', function () {
    $sender = User::factory()->internal()->create();
    $recipient = User::factory()->internal()->create();
    $thread = ChatThread::factory()->direct()->create();
    $thread->addParticipant($sender);
    $thread->addParticipant($recipient);

    $message1 = ChatMessage::factory()->create([
        'thread_id' => $thread->id,
        'user_id' => $sender->id,
        'type' => 'text',
    ]);

    $message2 = ChatMessage::factory()->create([
        'thread_id' => $thread->id,
        'user_id' => $sender->id,
        'type' => 'text',
    ]);

    (new SendUnreadChatEmailJob($message1, $recipient))->handle();
    (new SendUnreadChatEmailJob($message2, $recipient))->handle();

    Notification::assertSentToTimes($recipient, UnreadChatMessage::class, 1);
});

it('does not send email for system messages', function () {
    $sender = User::factory()->internal()->create();
    $recipient = User::factory()->internal()->create();
    $thread = ChatThread::factory()->direct()->create();
    $thread->addParticipant($sender);
    $thread->addParticipant($recipient);

    $message = ChatMessage::factory()->create([
        'thread_id' => $thread->id,
        'user_id' => $sender->id,
        'type' => 'system',
    ]);

    $job = new SendUnreadChatEmailJob($message, $recipient);
    $job->handle();

    Notification::assertNothingSent();
});

it('does not send internal message email to non-internal user', function () {
    $sender = User::factory()->internal()->create();
    $recipient = User::factory()->customer()->create();
    $thread = ChatThread::factory()->direct()->create();
    $thread->addParticipant($sender);
    $thread->addParticipant($recipient);

    $message = ChatMessage::factory()->create([
        'thread_id' => $thread->id,
        'user_id' => $sender->id,
        'type' => 'text',
        'is_internal' => true,
    ]);

    $job = new SendUnreadChatEmailJob($message, $recipient);
    $job->handle();

    Notification::assertNothingSent();
});

it('sends internal message email to internal user', function () {
    $sender = User::factory()->internal()->create();
    $recipient = User::factory()->internal()->create();
    $thread = ChatThread::factory()->direct()->create();
    $thread->addParticipant($sender);
    $thread->addParticipant($recipient);

    $message = ChatMessage::factory()->create([
        'thread_id' => $thread->id,
        'user_id' => $sender->id,
        'type' => 'text',
        'is_internal' => true,
    ]);

    $job = new SendUnreadChatEmailJob($message, $recipient);
    $job->handle();

    Notification::assertSentTo($recipient, UnreadChatMessage::class);
});

it('sends email to all user types when not internal', function () {
    $sender = User::factory()->internal()->create();

    collect(['customer', 'agent', 'crew'])->each(function ($type) use ($sender) {
        Cache::flush();
        Notification::fake();

        $recipient = User::factory()->{$type}()->create();
        $thread = ChatThread::factory()->direct()->create();
        $thread->addParticipant($sender);
        $thread->addParticipant($recipient);

        $message = ChatMessage::factory()->create([
            'thread_id' => $thread->id,
            'user_id' => $sender->id,
            'type' => 'text',
            'is_internal' => false,
        ]);

        (new SendUnreadChatEmailJob($message, $recipient))->handle();

        Notification::assertSentTo($recipient, UnreadChatMessage::class);
    });
});

it('does not send email when recipient is not a participant', function () {
    $sender = User::factory()->internal()->create();
    $recipient = User::factory()->internal()->create();
    $thread = ChatThread::factory()->direct()->create();
    $thread->addParticipant($sender);
    // recipient NOT added as participant

    $message = ChatMessage::factory()->create([
        'thread_id' => $thread->id,
        'user_id' => $sender->id,
        'type' => 'text',
    ]);

    $job = new SendUnreadChatEmailJob($message, $recipient);
    $job->handle();

    Notification::assertNothingSent();
});

it('uses mail database and broadcast channels', function () {
    $message = ChatMessage::factory()->create();
    $notification = new UnreadChatMessage($message);

    expect($notification->via(new \stdClass))->toBe(['mail', 'database', 'broadcast']);
});
