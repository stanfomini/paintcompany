<?php

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Services\EmailThreadingService;

beforeEach(function () {
    $this->service = new EmailThreadingService;
    $this->account = EmailAccount::factory()->create();
});

describe('EmailThreadingService', function () {
    it('threads by in-reply-to header', function () {
        $existingThread = EmailThread::factory()->create([
            'email_account_id' => $this->account->id,
        ]);
        $existingMessage = EmailMessage::factory()->create([
            'email_thread_id' => $existingThread->id,
            'email_account_id' => $this->account->id,
            'message_id' => '<original-msg@example.com>',
        ]);

        $thread = $this->service->findOrCreateThread(
            messageId: '<new-msg@example.com>',
            inReplyTo: '<original-msg@example.com>',
            references: null,
            subject: 'Re: Test Subject',
            account: $this->account
        );

        expect($thread->id)->toBe($existingThread->id);
    });

    it('threads by references chain', function () {
        $existingThread = EmailThread::factory()->create([
            'email_account_id' => $this->account->id,
        ]);
        $existingMessage = EmailMessage::factory()->create([
            'email_thread_id' => $existingThread->id,
            'email_account_id' => $this->account->id,
            'message_id' => '<ref-msg-2@example.com>',
        ]);

        $thread = $this->service->findOrCreateThread(
            messageId: '<new-msg@example.com>',
            inReplyTo: null,
            references: ['<ref-msg-1@example.com>', '<ref-msg-2@example.com>'],
            subject: 'Re: Test Subject',
            account: $this->account
        );

        expect($thread->id)->toBe($existingThread->id);
    });

    it('threads by subject match within 7 days', function () {
        $existingThread = EmailThread::factory()->create([
            'email_account_id' => $this->account->id,
            'subject' => 'Project Update',
            'last_message_at' => now()->subDays(3),
        ]);

        $thread = $this->service->findOrCreateThread(
            messageId: '<new-msg@example.com>',
            inReplyTo: null,
            references: null,
            subject: 'Re: Project Update',
            account: $this->account
        );

        expect($thread->id)->toBe($existingThread->id);
    });

    it('does not match subjects older than 7 days', function () {
        $oldThread = EmailThread::factory()->create([
            'email_account_id' => $this->account->id,
            'subject' => 'Old Topic',
            'last_message_at' => now()->subDays(8),
        ]);

        $thread = $this->service->findOrCreateThread(
            messageId: '<new-msg@example.com>',
            inReplyTo: null,
            references: null,
            subject: 'Re: Old Topic',
            account: $this->account
        );

        expect($thread->id)->not->toBe($oldThread->id);
        expect(EmailThread::count())->toBe(2);
    });

    it('creates new thread when no match', function () {
        $initialCount = EmailThread::count();

        $thread = $this->service->findOrCreateThread(
            messageId: '<brand-new@example.com>',
            inReplyTo: null,
            references: null,
            subject: 'Brand New Conversation',
            account: $this->account
        );

        expect(EmailThread::count())->toBe($initialCount + 1);
        expect($thread->subject)->toBe('Brand New Conversation');
        expect($thread->email_account_id)->toBe($this->account->id);
        expect($thread->original_message_id)->toBe('<brand-new@example.com>');
    });

    it('normalizes subject by stripping re/fwd prefixes', function () {
        expect($this->service->normalizeSubject('Re: Hello'))
            ->toBe('Hello');

        expect($this->service->normalizeSubject('Fwd: Hello'))
            ->toBe('Hello');

        expect($this->service->normalizeSubject('FW: Hello'))
            ->toBe('Hello');

        expect($this->service->normalizeSubject('re: fw: Hello'))
            ->toBe('fw: Hello');

        expect($this->service->normalizeSubject('Hello'))
            ->toBe('Hello');
    });

    it('builds references chain for replies', function () {
        $originalMessage = EmailMessage::factory()->create([
            'email_account_id' => $this->account->id,
            'message_id' => '<original@example.com>',
            'references' => ['<root@example.com>'],
        ]);

        $chain = $this->service->buildReferencesChain($originalMessage);

        expect($chain)->toBe(['<root@example.com>', '<original@example.com>']);
    });
});
