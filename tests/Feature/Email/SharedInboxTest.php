<?php

use App\Events\EmailReceived;
use App\Jobs\AutoLinkEmailJob;
use App\Jobs\DownloadEmailAttachmentsJob;
use App\Jobs\ProcessInboundEmailJob;
use App\Models\Customer;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use App\Notifications\EmailReceivedNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(\Database\Seeders\EmailLabelSeeder::class);
    $this->validSecret = 'test-mailgun-secret';
    config([
        'services.mailgun.secret' => $this->validSecret,
        'services.mailgun.webhook_signing_key' => null,
    ]);
});

function buildSharedPayload(array $overrides = [], ?string $secret = null): array
{
    $secret = $secret ?? config('services.mailgun.secret');
    $timestamp = (string) time();
    $token = bin2hex(random_bytes(16));
    $signature = hash_hmac('sha256', $timestamp.$token, $secret);

    return array_merge([
        'recipient' => 'hello@'.config('email.domain'),
        'sender' => 'customer@external.com',
        'from' => 'Jane Customer <customer@external.com>',
        'To' => 'hello@'.config('email.domain'),
        'subject' => 'Shared Inbox Test',
        'body-html' => '<p>Hello team</p>',
        'body-plain' => 'Hello team',
        'Message-Id' => '<shared-'.fake()->uuid().'@external.com>',
        'timestamp' => $timestamp,
        'token' => $token,
        'signature' => $signature,
    ], $overrides);
}

describe('Shared Inbox - Inbound Email', function () {
    it('routes inbound email to shared account', function () {
        Queue::fake([AutoLinkEmailJob::class, DownloadEmailAttachmentsJob::class]);
        Event::fake([EmailReceived::class]);
        Notification::fake();

        $shared = EmailAccount::factory()->shared()->create(['address' => 'hello']);
        $member = User::factory()->internal()->create();
        $shared->members()->attach($member, ['can_send' => true, 'receives_notifications' => true]);

        $payload = buildSharedPayload();

        (new ProcessInboundEmailJob($payload))->handle(new \App\Services\EmailThreadingService);

        expect(EmailMessage::count())->toBe(1);
        $message = EmailMessage::first();
        expect($message->email_account_id)->toBe($shared->id);
        expect($message->from_address)->toBe('customer@external.com');
    });

    it('notifies all members with receives_notifications for shared account', function () {
        Queue::fake([AutoLinkEmailJob::class, DownloadEmailAttachmentsJob::class]);
        Event::fake([EmailReceived::class]);
        Notification::fake();

        $shared = EmailAccount::factory()->shared()->create(['address' => 'hello']);
        $member1 = User::factory()->internal()->create();
        $member2 = User::factory()->internal()->create();
        $member3 = User::factory()->internal()->create();
        $shared->members()->attach($member1, ['receives_notifications' => true]);
        $shared->members()->attach($member2, ['receives_notifications' => true]);
        $shared->members()->attach($member3, ['receives_notifications' => false]);

        $payload = buildSharedPayload();
        (new ProcessInboundEmailJob($payload))->handle(new \App\Services\EmailThreadingService);

        Notification::assertSentTo($member1, EmailReceivedNotification::class);
        Notification::assertSentTo($member2, EmailReceivedNotification::class);
        Notification::assertNotSentTo($member3, EmailReceivedNotification::class);
    });

    it('notifies assigned rep first and avoids double-notify', function () {
        Queue::fake([AutoLinkEmailJob::class, DownloadEmailAttachmentsJob::class]);
        Event::fake([EmailReceived::class]);
        Notification::fake();

        $rep = User::factory()->internal()->create();
        $customer = Customer::factory()->create([
            'email' => 'customer@external.com',
            'assigned_to' => $rep->id,
        ]);

        $shared = EmailAccount::factory()->shared()->create(['address' => 'hello']);
        $shared->members()->attach($rep, ['receives_notifications' => true]);
        $otherMember = User::factory()->internal()->create();
        $shared->members()->attach($otherMember, ['receives_notifications' => true]);

        $payload = buildSharedPayload();
        (new ProcessInboundEmailJob($payload))->handle(new \App\Services\EmailThreadingService);

        // Rep notified once (not twice for being assigned + member)
        Notification::assertSentToTimes($rep, EmailReceivedNotification::class, 1);
        Notification::assertSentTo($otherMember, EmailReceivedNotification::class);
    });

    it('uses default_notify_user when no assigned rep', function () {
        Queue::fake([AutoLinkEmailJob::class, DownloadEmailAttachmentsJob::class]);
        Event::fake([EmailReceived::class]);
        Notification::fake();

        $defaultUser = User::factory()->internal()->create();
        $shared = EmailAccount::factory()->shared()->create([
            'address' => 'hello',
            'default_notify_user_id' => $defaultUser->id,
        ]);
        $member = User::factory()->internal()->create();
        $shared->members()->attach($defaultUser, ['receives_notifications' => true]);
        $shared->members()->attach($member, ['receives_notifications' => true]);

        // Sender is not a known customer
        $payload = buildSharedPayload(['sender' => 'unknown@external.com', 'from' => 'Unknown <unknown@external.com>']);
        (new ProcessInboundEmailJob($payload))->handle(new \App\Services\EmailThreadingService);

        Notification::assertSentTo($defaultUser, EmailReceivedNotification::class);
        // Other members also notified
        Notification::assertSentTo($member, EmailReceivedNotification::class);
    });

    it('still works for personal accounts', function () {
        Queue::fake([AutoLinkEmailJob::class, DownloadEmailAttachmentsJob::class]);
        Event::fake([EmailReceived::class]);
        Notification::fake();

        $account = EmailAccount::factory()->create(['address' => 'matt']);

        $payload = buildSharedPayload([
            'recipient' => 'matt@'.config('email.domain'),
            'To' => 'matt@'.config('email.domain'),
        ]);

        (new ProcessInboundEmailJob($payload))->handle(new \App\Services\EmailThreadingService);

        expect(EmailMessage::count())->toBe(1);
        Notification::assertSentTo($account->user, EmailReceivedNotification::class);
    });

    it('threads shared inbox emails correctly', function () {
        Queue::fake([AutoLinkEmailJob::class, DownloadEmailAttachmentsJob::class]);
        Event::fake([EmailReceived::class]);
        Notification::fake();

        $shared = EmailAccount::factory()->shared()->create(['address' => 'hello']);
        $member = User::factory()->internal()->create();
        $shared->members()->attach($member);

        // First email
        $payload1 = buildSharedPayload(['Message-Id' => '<msg1@external.com>']);
        (new ProcessInboundEmailJob($payload1))->handle(new \App\Services\EmailThreadingService);

        // Reply to first
        $payload2 = buildSharedPayload([
            'Message-Id' => '<msg2@external.com>',
            'In-Reply-To' => '<msg1@external.com>',
            'References' => '<msg1@external.com>',
            'subject' => 'Re: Shared Inbox Test',
        ]);
        (new ProcessInboundEmailJob($payload2))->handle(new \App\Services\EmailThreadingService);

        expect(EmailMessage::count())->toBe(2);
        // Both should be in the same thread
        $threadIds = EmailMessage::pluck('email_thread_id')->unique();
        expect($threadIds)->toHaveCount(1);
    });
});

describe('Shared Inbox - Broadcasting', function () {
    it('broadcasts to all members for shared account', function () {
        $shared = EmailAccount::factory()->shared()->create(['address' => 'hello']);
        $member1 = User::factory()->internal()->create();
        $member2 = User::factory()->internal()->create();
        $shared->members()->attach([$member1->id, $member2->id]);

        $thread = EmailThread::factory()->create(['email_account_id' => $shared->id]);
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $shared->id,
        ]);

        $event = new EmailReceived($message);
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(2);
        $channelNames = array_map(fn ($ch) => $ch->name, $channels);
        expect($channelNames)->toContain('private-email.user.'.$member1->id);
        expect($channelNames)->toContain('private-email.user.'.$member2->id);
    });

    it('broadcasts to single user for personal account', function () {
        $account = EmailAccount::factory()->create(['address' => 'matt']);
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $account->id,
        ]);

        $event = new EmailReceived($message);
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1);
        expect($channels[0]->name)->toBe('private-email.user.'.$account->user_id);
    });

    it('EmailSent broadcasts to all members for shared account', function () {
        $shared = EmailAccount::factory()->shared()->create(['address' => 'hello']);
        $member1 = User::factory()->internal()->create();
        $member2 = User::factory()->internal()->create();
        $shared->members()->attach([$member1->id, $member2->id]);

        $thread = EmailThread::factory()->create(['email_account_id' => $shared->id]);
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $shared->id,
        ]);

        $event = new \App\Events\EmailSent($message);
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(2);
    });
});
