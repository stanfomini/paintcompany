<?php

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\EmailReceivedNotification;

describe('EmailReceivedNotification', function () {
    it('sends via WebPushChannel when user has push enabled and subscription', function () {
        $user = User::factory()->internal()->create();
        PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => 'https://push.example.com/test',
            'public_key' => 'test-public-key',
            'auth_token' => 'test-auth-token',
            'content_encoding' => 'aes128gcm',
        ]);

        $thread = EmailThread::factory()->create();
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'from_name' => 'Jane Doe',
            'from_address' => 'jane@example.com',
            'subject' => 'Hello there',
        ]);

        $notification = new EmailReceivedNotification($message);
        $channels = $notification->via($user);

        expect($channels)->toContain(WebPushChannel::class);
        expect($channels)->toContain('database');
        expect($channels)->toContain('broadcast');
    });

    it('returns database and broadcast channels when user has no push subscription', function () {
        $user = User::factory()->internal()->create();

        $thread = EmailThread::factory()->create();
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
        ]);

        $notification = new EmailReceivedNotification($message);
        $channels = $notification->via($user);

        expect($channels)->not->toContain(WebPushChannel::class);
        expect($channels)->toContain('database');
        expect($channels)->toContain('broadcast');
    });

    it('excludes WebPush when user has push disabled for email_messages', function () {
        $user = User::factory()->internal()->create([
            'notification_preferences' => [
                'email_messages' => ['email' => true, 'push' => false],
            ],
        ]);
        PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => 'https://push.example.com/test',
            'public_key' => 'test-public-key',
            'auth_token' => 'test-auth-token',
            'content_encoding' => 'aes128gcm',
        ]);

        $thread = EmailThread::factory()->create();
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
        ]);

        $notification = new EmailReceivedNotification($message);
        $channels = $notification->via($user);

        expect($channels)->not->toContain(WebPushChannel::class);
        expect($channels)->toContain('database');
        expect($channels)->toContain('broadcast');
    });

    it('builds correct WebPushMessage with from_name', function () {
        $user = User::factory()->internal()->create();

        $thread = EmailThread::factory()->create();
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'from_name' => 'Jane Doe',
            'from_address' => 'jane@example.com',
            'subject' => 'Important update',
        ]);

        $notification = new EmailReceivedNotification($message);
        $webPush = $notification->toWebPush($user);

        expect($webPush)->toBeInstanceOf(WebPushMessage::class);
        expect($webPush->title)->toBe('Jane Doe');
        expect($webPush->body)->toBe('Important update');
        expect($webPush->url)->toBe("/email?threadId={$thread->id}");
        expect($webPush->tag)->toBe("email-thread-{$thread->id}");
    });

    it('falls back to from_address when from_name is null', function () {
        $user = User::factory()->internal()->create();

        $thread = EmailThread::factory()->create();
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'from_name' => null,
            'from_address' => 'jane@example.com',
            'subject' => 'No name email',
        ]);

        $notification = new EmailReceivedNotification($message);
        $webPush = $notification->toWebPush($user);

        expect($webPush->title)->toBe('jane@example.com');
    });

    it('has email_messages push category', function () {
        $thread = EmailThread::factory()->create();
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
        ]);

        $notification = new EmailReceivedNotification($message);

        expect($notification->pushCategory)->toBe('email_messages');
    });
});
