<?php

use App\Events\EmailSent;
use App\Jobs\AutoLinkEmailJob;
use App\Jobs\SendEmailJob;
use App\Models\EmailAccount;
use App\Models\EmailLabel;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\EmailService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(\Database\Seeders\EmailLabelSeeder::class);
    $this->account = EmailAccount::factory()->create(['address' => 'test.user']);
    $this->service = app(EmailService::class);
    Queue::fake([SendEmailJob::class, AutoLinkEmailJob::class]);
    Event::fake([EmailSent::class]);
});

describe('EmailService', function () {
    it('sends email and creates outbound message', function () {
        $message = $this->service->send(
            account: $this->account,
            to: [['address' => 'client@example.com', 'name' => 'Client']],
            subject: 'Hello Client',
            bodyHtml: '<p>Nice to meet you</p>',
        );

        expect($message)->toBeInstanceOf(EmailMessage::class);
        expect($message->direction)->toBe('outbound');
        expect($message->from_address)->toBe($this->account->full_address);
        expect($message->subject)->toBe('Hello Client');
        expect($message->is_read)->toBeTrue();
        expect($message->sent_at)->not->toBeNull();
        expect($message->email_account_id)->toBe($this->account->id);

        Queue::assertPushed(SendEmailJob::class);
        Queue::assertPushed(AutoLinkEmailJob::class);
    });

    it('applies sent label to outbound', function () {
        $message = $this->service->send(
            account: $this->account,
            to: [['address' => 'client@example.com', 'name' => 'Client']],
            subject: 'With Sent Label',
            bodyHtml: '<p>Test body</p>',
        );

        $labelSlugs = $message->labels->pluck('slug')->toArray();
        expect($labelSlugs)->toContain('sent');
    });

    it('generates RFC message-id', function () {
        $message = $this->service->send(
            account: $this->account,
            to: [['address' => 'client@example.com', 'name' => 'Client']],
            subject: 'Test Message ID',
            bodyHtml: '<p>Body</p>',
        );

        expect($message->message_id)->toStartWith('<');
        expect($message->message_id)->toEndWith('@'.config('email.domain').'>');
    });

    it('saves draft with drafts label', function () {
        $draft = $this->service->saveDraft(
            account: $this->account,
            existingDraft: null,
            to: [['address' => 'client@example.com', 'name' => 'Client']],
            subject: 'Draft Subject',
            bodyHtml: '<p>Draft content</p>',
        );

        expect($draft->direction)->toBe('draft');
        expect($draft->sent_at)->toBeNull();
        expect($draft->message_id)->toBeNull();

        $labelSlugs = $draft->labels->pluck('slug')->toArray();
        expect($labelSlugs)->toContain('drafts');
    });

    it('updates existing draft', function () {
        $draft = $this->service->saveDraft(
            account: $this->account,
            existingDraft: null,
            to: [['address' => 'client@example.com', 'name' => 'Client']],
            subject: 'Original Draft',
            bodyHtml: '<p>Original</p>',
        );

        $updated = $this->service->saveDraft(
            account: $this->account,
            existingDraft: $draft,
            to: [['address' => 'client@example.com', 'name' => 'Client']],
            subject: 'Updated Draft',
            bodyHtml: '<p>Updated body</p>',
        );

        expect($updated->id)->toBe($draft->id);
        expect($updated->subject)->toBe('Updated Draft');
        expect($updated->body_html)->toBe('<p>Updated body</p>');
        expect(EmailMessage::where('direction', 'draft')->count())->toBe(1);
    });

    it('sends draft and swaps labels', function () {
        $draft = $this->service->saveDraft(
            account: $this->account,
            existingDraft: null,
            to: [['address' => 'client@example.com', 'name' => 'Client']],
            subject: 'Draft to Send',
            bodyHtml: '<p>Draft body</p>',
        );

        expect($draft->labels->pluck('slug')->toArray())->toContain('drafts');

        $sent = $this->service->sendDraft($draft);

        expect($sent->direction)->toBe('outbound');
        expect($sent->message_id)->not->toBeNull();
        expect($sent->sent_at)->not->toBeNull();
        expect($sent->is_read)->toBeTrue();

        $labelSlugs = $sent->labels->pluck('slug')->toArray();
        expect($labelSlugs)->toContain('sent');
        expect($labelSlugs)->not->toContain('drafts');

        Queue::assertPushed(SendEmailJob::class);
    });

    it('moves message to trash', function () {
        $message = $this->service->send(
            account: $this->account,
            to: [['address' => 'client@example.com', 'name' => 'Client']],
            subject: 'To Be Trashed',
            bodyHtml: '<p>Body</p>',
        );

        expect($message->labels->pluck('slug')->toArray())->toContain('sent');

        $this->service->moveToTrash($message);

        $message->refresh();
        $message->load('labels');
        $labelSlugs = $message->labels->pluck('slug')->toArray();

        expect($labelSlugs)->toContain('trash');
        expect($labelSlugs)->not->toContain('sent');
    });

    it('restores from trash to inbox', function () {
        // Create an inbound message
        $thread = EmailThread::factory()->create(['email_account_id' => $this->account->id]);
        $message = EmailMessage::factory()->inbound()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $this->account->id,
        ]);

        // Attach inbox label, then move to trash
        $inboxLabel = EmailLabel::where('slug', 'inbox')->whereNull('email_account_id')->first();
        $message->labels()->attach($inboxLabel->id);

        $this->service->moveToTrash($message);

        $message->refresh();
        $message->load('labels');
        expect($message->labels->pluck('slug')->toArray())->toContain('trash');

        $this->service->restoreFromTrash($message);

        $message->refresh();
        $message->load('labels');
        $labelSlugs = $message->labels->pluck('slug')->toArray();

        expect($labelSlugs)->toContain('inbox');
        expect($labelSlugs)->not->toContain('trash');
    });

    it('permanently deletes messages', function () {
        $thread = EmailThread::factory()->create(['email_account_id' => $this->account->id]);
        $message = EmailMessage::factory()->create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $this->account->id,
        ]);
        $messageId = $message->id;

        $this->service->permanentDelete($message);

        // Soft-deleted, so withTrashed should find it but normal query shouldn't
        expect(EmailMessage::find($messageId))->toBeNull();
        expect(EmailMessage::withTrashed()->find($messageId))->not->toBeNull();
    });
});
