<?php

use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
});

describe('Email Attachment Authorization & Preview', function () {
    it('allows personal account owner to download', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $account = EmailAccount::factory()->create(['user_id' => $user->id]);
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);
        $message = EmailMessage::factory()->create([
            'email_account_id' => $account->id,
            'email_thread_id' => $thread->id,
        ]);

        $attachment = EmailAttachment::factory()->create([
            'email_message_id' => $message->id,
        ]);
        Storage::put($attachment->path, 'test file content');

        $this->actingAs($user)
            ->get(route('email.attachment.download', $attachment))
            ->assertSuccessful();
    });

    it('allows personal account owner to preview', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $account = EmailAccount::factory()->create(['user_id' => $user->id]);
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);
        $message = EmailMessage::factory()->create([
            'email_account_id' => $account->id,
            'email_thread_id' => $thread->id,
        ]);

        $attachment = EmailAttachment::factory()->create([
            'email_message_id' => $message->id,
            'mime_type' => 'image/png',
        ]);
        Storage::put($attachment->path, 'fake image bytes');

        $response = $this->actingAs($user)
            ->get(route('email.attachment.preview', $attachment));

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'image/png');
    });

    it('allows shared account member to download', function () {
        $member = User::factory()->internal()->withFullAccess()->create();
        $shared = EmailAccount::factory()->shared()->create();
        $shared->members()->attach($member, ['can_send' => true, 'receives_notifications' => true]);

        $thread = EmailThread::factory()->create(['email_account_id' => $shared->id]);
        $message = EmailMessage::factory()->create([
            'email_account_id' => $shared->id,
            'email_thread_id' => $thread->id,
        ]);

        $attachment = EmailAttachment::factory()->create([
            'email_message_id' => $message->id,
        ]);
        Storage::put($attachment->path, 'test file content');

        $this->actingAs($member)
            ->get(route('email.attachment.download', $attachment))
            ->assertSuccessful();
    });

    it('allows shared account member to preview', function () {
        $member = User::factory()->internal()->withFullAccess()->create();
        $shared = EmailAccount::factory()->shared()->create();
        $shared->members()->attach($member, ['can_send' => true, 'receives_notifications' => true]);

        $thread = EmailThread::factory()->create(['email_account_id' => $shared->id]);
        $message = EmailMessage::factory()->create([
            'email_account_id' => $shared->id,
            'email_thread_id' => $thread->id,
        ]);

        $attachment = EmailAttachment::factory()->create([
            'email_message_id' => $message->id,
            'mime_type' => 'application/pdf',
        ]);
        Storage::put($attachment->path, 'fake pdf bytes');

        $response = $this->actingAs($member)
            ->get(route('email.attachment.preview', $attachment));

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'application/pdf');
    });

    it('denies non-member access with 403', function () {
        $nonMember = User::factory()->internal()->withFullAccess()->create();
        $owner = User::factory()->internal()->create();
        $account = EmailAccount::factory()->create(['user_id' => $owner->id]);

        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);
        $message = EmailMessage::factory()->create([
            'email_account_id' => $account->id,
            'email_thread_id' => $thread->id,
        ]);

        $attachment = EmailAttachment::factory()->create([
            'email_message_id' => $message->id,
        ]);
        Storage::put($attachment->path, 'test file content');

        $this->actingAs($nonMember)
            ->get(route('email.attachment.download', $attachment))
            ->assertForbidden();

        $this->actingAs($nonMember)
            ->get(route('email.attachment.preview', $attachment))
            ->assertForbidden();
    });

    it('redirects unauthenticated user', function () {
        $owner = User::factory()->internal()->create();
        $account = EmailAccount::factory()->create(['user_id' => $owner->id]);
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);
        $message = EmailMessage::factory()->create([
            'email_account_id' => $account->id,
            'email_thread_id' => $thread->id,
        ]);

        $attachment = EmailAttachment::factory()->create([
            'email_message_id' => $message->id,
        ]);

        $this->get(route('email.attachment.download', $attachment))
            ->assertRedirect();

        $this->get(route('email.attachment.preview', $attachment))
            ->assertRedirect();
    });

    it('returns 404 when file is missing from storage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $account = EmailAccount::factory()->create(['user_id' => $user->id]);
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);
        $message = EmailMessage::factory()->create([
            'email_account_id' => $account->id,
            'email_thread_id' => $thread->id,
        ]);

        $attachment = EmailAttachment::factory()->create([
            'email_message_id' => $message->id,
        ]);
        // Deliberately don't put the file in storage

        $this->actingAs($user)
            ->get(route('email.attachment.download', $attachment))
            ->assertNotFound();

        $this->actingAs($user)
            ->get(route('email.attachment.preview', $attachment))
            ->assertNotFound();
    });
});
