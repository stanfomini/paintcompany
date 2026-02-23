<?php

use App\Events\EmailReceived;
use App\Jobs\AutoLinkEmailJob;
use App\Jobs\DownloadEmailAttachmentsJob;
use App\Jobs\ProcessInboundEmailJob;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Notifications\EmailReceivedNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(\Database\Seeders\EmailLabelSeeder::class);
    $this->account = EmailAccount::factory()->create(['address' => 'matt']);
    $this->validSecret = 'test-mailgun-secret';
    config([
        'services.mailgun.secret' => $this->validSecret,
        'services.mailgun.webhook_signing_key' => null,
    ]);
});

/**
 * Build a valid Mailgun webhook payload with correct HMAC signature.
 */
function buildPayload(array $overrides = [], ?string $secret = null): array
{
    $secret = $secret ?? config('services.mailgun.secret');
    $timestamp = (string) time();
    $token = bin2hex(random_bytes(16));
    $signature = hash_hmac('sha256', $timestamp.$token, $secret);

    return array_merge([
        'recipient' => 'matt@'.config('email.domain'),
        'sender' => 'sender@external.com',
        'from' => 'John Doe <sender@external.com>',
        'To' => 'matt@'.config('email.domain'),
        'subject' => 'Test Inbound Email',
        'body-html' => '<p>Hello from outside</p>',
        'body-plain' => 'Hello from outside',
        'Message-Id' => '<inbound-'.fake()->uuid().'@external.com>',
        'timestamp' => $timestamp,
        'token' => $token,
        'signature' => $signature,
    ], $overrides);
}

describe('ProcessInboundEmailJob', function () {
    it('processes valid inbound email and creates message', function () {
        Queue::fake([AutoLinkEmailJob::class, DownloadEmailAttachmentsJob::class]);
        Event::fake([EmailReceived::class]);

        $payload = buildPayload();

        (new ProcessInboundEmailJob($payload))->handle(new \App\Services\EmailThreadingService);

        expect(EmailMessage::count())->toBe(1);

        $message = EmailMessage::first();
        expect($message->direction)->toBe('inbound');
        expect($message->from_address)->toBe('sender@external.com');
        expect($message->subject)->toBe('Test Inbound Email');
        expect($message->email_account_id)->toBe($this->account->id);
        expect($message->body_html)->toBe('<p>Hello from outside</p>');
    });

    it('rejects invalid Mailgun signature', function () {
        Queue::fake();
        Event::fake();

        $payload = buildPayload([
            'signature' => 'invalid-signature-value',
        ]);

        (new ProcessInboundEmailJob($payload))->handle(new \App\Services\EmailThreadingService);

        expect(EmailMessage::count())->toBe(0);
    });

    it('skips unknown recipient', function () {
        Queue::fake();
        Event::fake();

        $payload = buildPayload([
            'recipient' => 'unknown@'.config('email.domain'),
        ]);

        (new ProcessInboundEmailJob($payload))->handle(new \App\Services\EmailThreadingService);

        expect(EmailMessage::count())->toBe(0);
    });

    it('deduplicates by message_id', function () {
        Queue::fake([AutoLinkEmailJob::class, DownloadEmailAttachmentsJob::class]);
        Event::fake([EmailReceived::class]);

        $messageId = '<duplicate-'.fake()->uuid().'@external.com>';

        // Create existing message with same message_id
        $thread = EmailThread::factory()->create(['email_account_id' => $this->account->id]);
        EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $this->account->id,
            'message_id' => $messageId,
        ]);

        $payload = buildPayload(['Message-Id' => $messageId]);

        (new ProcessInboundEmailJob($payload))->handle(new \App\Services\EmailThreadingService);

        // Should still be just the one original message
        expect(EmailMessage::count())->toBe(1);
    });

    it('applies inbox label to inbound messages', function () {
        Queue::fake([AutoLinkEmailJob::class, DownloadEmailAttachmentsJob::class]);
        Event::fake([EmailReceived::class]);

        $payload = buildPayload();

        (new ProcessInboundEmailJob($payload))->handle(new \App\Services\EmailThreadingService);

        $message = EmailMessage::first();
        $labelSlugs = $message->labels->pluck('slug')->toArray();

        expect($labelSlugs)->toContain('inbox');
    });

    it('updates thread last_message_at', function () {
        Queue::fake([AutoLinkEmailJob::class, DownloadEmailAttachmentsJob::class]);
        Event::fake([EmailReceived::class]);

        $payload = buildPayload();

        $before = now()->subSecond();

        (new ProcessInboundEmailJob($payload))->handle(new \App\Services\EmailThreadingService);

        $message = EmailMessage::first();
        $thread = $message->thread;

        expect($thread->last_message_at->gte($before))->toBeTrue();
    });

    it('dispatches auto link and attachment jobs', function () {
        Queue::fake([AutoLinkEmailJob::class, DownloadEmailAttachmentsJob::class]);
        Event::fake([EmailReceived::class]);

        $payload = buildPayload(['attachment-count' => '2']);

        (new ProcessInboundEmailJob($payload))->handle(new \App\Services\EmailThreadingService);

        Queue::assertPushed(AutoLinkEmailJob::class);
        Queue::assertPushed(DownloadEmailAttachmentsJob::class);
    });

    it('sends push notification to account owner', function () {
        Queue::fake([AutoLinkEmailJob::class, DownloadEmailAttachmentsJob::class]);
        Event::fake([EmailReceived::class]);
        Notification::fake();

        // User needs a push subscription for via() to return the WebPush channel
        \App\Models\PushSubscription::create([
            'user_id' => $this->account->user_id,
            'endpoint' => 'https://push.example.com/test',
            'public_key' => 'test-public-key',
            'auth_token' => 'test-auth-token',
            'content_encoding' => 'aes128gcm',
        ]);

        $payload = buildPayload();

        (new ProcessInboundEmailJob($payload))->handle(new \App\Services\EmailThreadingService);

        Notification::assertSentTo(
            $this->account->user,
            EmailReceivedNotification::class,
            function (EmailReceivedNotification $notification) {
                return $notification->message->from_address === 'sender@external.com'
                    && $notification->message->subject === 'Test Inbound Email';
            }
        );
    });
});
