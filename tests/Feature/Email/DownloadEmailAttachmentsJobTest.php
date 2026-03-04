<?php

use App\Jobs\DownloadEmailAttachmentsJob;
use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
});

describe('DownloadEmailAttachmentsJob inline detection', function () {
    it('marks attachment as inline when CID is referenced in body HTML', function () {
        $account = EmailAccount::factory()->create();
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);
        $cid = 'image001@example.com';

        $message = EmailMessage::factory()->create([
            'email_account_id' => $account->id,
            'email_thread_id' => $thread->id,
            'body_html' => '<p>Hello</p><img src="cid:'.$cid.'" /><p>World</p>',
        ]);

        $tempPath = 'email-attachments/pending/test-inline.png';
        Storage::put($tempPath, 'fake image bytes');

        $payload = [
            '_saved_attachments' => [
                1 => [
                    'path' => $tempPath,
                    'filename' => 'logo.png',
                    'mime_type' => 'image/png',
                    'size' => 1024,
                    'content_id' => '<'.$cid.'>',
                ],
            ],
        ];

        $job = new DownloadEmailAttachmentsJob($message, $payload);
        $job->handle();

        $attachment = EmailAttachment::where('email_message_id', $message->id)->first();
        expect($attachment)->not->toBeNull();
        expect($attachment->is_inline)->toBeTrue();
        expect($attachment->content_id)->toBe($cid);
    });

    it('marks attachment as NOT inline when CID is present but not referenced in body', function () {
        $account = EmailAccount::factory()->create();
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);
        $cid = 'attachment001@example.com';

        $message = EmailMessage::factory()->create([
            'email_account_id' => $account->id,
            'email_thread_id' => $thread->id,
            'body_html' => '<p>Please see the attached PDF.</p>',
        ]);

        $tempPath = 'email-attachments/pending/test-regular.pdf';
        Storage::put($tempPath, 'fake pdf bytes');

        $payload = [
            '_saved_attachments' => [
                1 => [
                    'path' => $tempPath,
                    'filename' => 'contract.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 2048,
                    'content_id' => '<'.$cid.'>',
                ],
            ],
        ];

        $job = new DownloadEmailAttachmentsJob($message, $payload);
        $job->handle();

        $attachment = EmailAttachment::where('email_message_id', $message->id)->first();
        expect($attachment)->not->toBeNull();
        expect($attachment->is_inline)->toBeFalse();
        expect($attachment->content_id)->toBe($cid);
    });

    it('marks attachment as NOT inline when no content ID', function () {
        $account = EmailAccount::factory()->create();
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);

        $message = EmailMessage::factory()->create([
            'email_account_id' => $account->id,
            'email_thread_id' => $thread->id,
        ]);

        $tempPath = 'email-attachments/pending/test-plain.txt';
        Storage::put($tempPath, 'text content');

        $payload = [
            '_saved_attachments' => [
                1 => [
                    'path' => $tempPath,
                    'filename' => 'notes.txt',
                    'mime_type' => 'text/plain',
                    'size' => 512,
                    'content_id' => null,
                ],
            ],
        ];

        $job = new DownloadEmailAttachmentsJob($message, $payload);
        $job->handle();

        $attachment = EmailAttachment::where('email_message_id', $message->id)->first();
        expect($attachment)->not->toBeNull();
        expect($attachment->is_inline)->toBeFalse();
        expect($attachment->content_id)->toBeNull();
    });

    it('handles missing temp files gracefully', function () {
        $account = EmailAccount::factory()->create();
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);

        $message = EmailMessage::factory()->create([
            'email_account_id' => $account->id,
            'email_thread_id' => $thread->id,
        ]);

        $payload = [
            '_saved_attachments' => [
                1 => [
                    'path' => 'email-attachments/pending/nonexistent.pdf',
                    'filename' => 'missing.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 1024,
                    'content_id' => null,
                ],
            ],
        ];

        $job = new DownloadEmailAttachmentsJob($message, $payload);
        $job->handle();

        expect(EmailAttachment::where('email_message_id', $message->id)->count())->toBe(0);
    });

    it('moves files from pending to final path', function () {
        $account = EmailAccount::factory()->create();
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);

        $message = EmailMessage::factory()->create([
            'email_account_id' => $account->id,
            'email_thread_id' => $thread->id,
        ]);

        $tempPath = 'email-attachments/pending/test-move.pdf';
        Storage::put($tempPath, 'test content for move');

        $payload = [
            '_saved_attachments' => [
                1 => [
                    'path' => $tempPath,
                    'filename' => 'report.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 512,
                    'content_id' => null,
                ],
            ],
        ];

        $job = new DownloadEmailAttachmentsJob($message, $payload);
        $job->handle();

        // Temp file should be gone
        Storage::assertMissing($tempPath);

        // Final file should exist
        $attachment = EmailAttachment::where('email_message_id', $message->id)->first();
        expect($attachment)->not->toBeNull();
        Storage::assertExists($attachment->path);
        expect(str_starts_with($attachment->path, 'email-attachments/'.$message->id.'/'))->toBeTrue();
    });

    it('processes multiple attachments with mixed inline status', function () {
        $account = EmailAccount::factory()->create();
        $thread = EmailThread::factory()->create(['email_account_id' => $account->id]);
        $inlineCid = 'inline-img@example.com';

        $message = EmailMessage::factory()->create([
            'email_account_id' => $account->id,
            'email_thread_id' => $thread->id,
            'body_html' => '<p>See image:</p><img src="cid:'.$inlineCid.'" />',
        ]);

        Storage::put('email-attachments/pending/img.png', 'inline image');
        Storage::put('email-attachments/pending/doc.pdf', 'regular pdf');
        Storage::put('email-attachments/pending/photo.jpg', 'regular photo with cid');

        $payload = [
            '_saved_attachments' => [
                1 => [
                    'path' => 'email-attachments/pending/img.png',
                    'filename' => 'inline-image.png',
                    'mime_type' => 'image/png',
                    'size' => 1024,
                    'content_id' => '<'.$inlineCid.'>',
                ],
                2 => [
                    'path' => 'email-attachments/pending/doc.pdf',
                    'filename' => 'document.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 2048,
                    'content_id' => null,
                ],
                3 => [
                    'path' => 'email-attachments/pending/photo.jpg',
                    'filename' => 'photo.jpg',
                    'mime_type' => 'image/jpeg',
                    'size' => 3072,
                    'content_id' => '<unreferenced-cid@example.com>',
                ],
            ],
        ];

        $job = new DownloadEmailAttachmentsJob($message, $payload);
        $job->handle();

        $attachments = EmailAttachment::where('email_message_id', $message->id)->get();
        expect($attachments)->toHaveCount(3);

        $inline = $attachments->firstWhere('original_filename', 'inline-image.png');
        expect($inline->is_inline)->toBeTrue();

        $pdf = $attachments->firstWhere('original_filename', 'document.pdf');
        expect($pdf->is_inline)->toBeFalse();

        $photo = $attachments->firstWhere('original_filename', 'photo.jpg');
        expect($photo->is_inline)->toBeFalse();
    });
});
