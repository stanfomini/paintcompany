<?php

use App\Jobs\ProcessInboundEmailJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

describe('Email Webhook Controller', function () {
    it('returns 200 for POST to inbound webhook', function () {
        Queue::fake();

        $response = $this->postJson('/webhooks/email/inbound', [
            'recipient' => 'test@'.config('email.domain'),
            'sender' => 'someone@example.com',
            'from' => 'Someone <someone@example.com>',
            'subject' => 'Webhook Test',
            'body-plain' => 'Test body',
            'timestamp' => (string) time(),
            'token' => 'test-token',
            'signature' => 'test-sig',
        ]);

        $response->assertSuccessful();
        $response->assertJson(['status' => 'queued']);
    });

    it('dispatches ProcessInboundEmailJob', function () {
        Queue::fake();

        $this->postJson('/webhooks/email/inbound', [
            'recipient' => 'test@'.config('email.domain'),
            'sender' => 'someone@example.com',
            'from' => 'Someone <someone@example.com>',
            'subject' => 'Dispatch Test',
            'body-plain' => 'Dispatch body',
            'timestamp' => (string) time(),
            'token' => 'dispatch-token',
            'signature' => 'dispatch-sig',
        ]);

        Queue::assertPushed(ProcessInboundEmailJob::class, function ($job) {
            return $job->payload['subject'] === 'Dispatch Test';
        });
    });

    it('saves multiple attachments to temp storage', function () {
        Queue::fake();
        Storage::fake();

        $file1 = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
        $file2 = UploadedFile::fake()->image('photo.jpg', 640, 480);
        $file3 = UploadedFile::fake()->create('spreadsheet.xlsx', 50, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->call('POST', '/webhooks/email/inbound', [
            'recipient' => 'test@'.config('email.domain'),
            'sender' => 'someone@example.com',
            'from' => 'Someone <someone@example.com>',
            'subject' => 'Multi Attachment Test',
            'body-plain' => 'See attached files',
            'attachment-count' => '3',
            'timestamp' => (string) time(),
            'token' => 'test-token',
            'signature' => 'test-sig',
        ], [], [
            'attachment-1' => $file1,
            'attachment-2' => $file2,
            'attachment-3' => $file3,
        ]);

        $response->assertSuccessful();

        Queue::assertPushed(ProcessInboundEmailJob::class, function ($job) {
            $saved = $job->payload['_saved_attachments'] ?? [];

            return count($saved) === 3
                && $saved[1]['filename'] === 'document.pdf'
                && $saved[2]['filename'] === 'photo.jpg'
                && $saved[3]['filename'] === 'spreadsheet.xlsx';
        });
    });

    it('includes content-id-map data in payload', function () {
        Queue::fake();
        Storage::fake();

        $file = UploadedFile::fake()->image('logo.png', 100, 100);
        $cid = '<logo001@example.com>';

        $response = $this->call('POST', '/webhooks/email/inbound', [
            'recipient' => 'test@'.config('email.domain'),
            'sender' => 'someone@example.com',
            'from' => 'Someone <someone@example.com>',
            'subject' => 'CID Map Test',
            'body-plain' => 'Email with inline image',
            'attachment-count' => '1',
            'content-id-map' => json_encode([$cid => 'attachment-1']),
            'timestamp' => (string) time(),
            'token' => 'test-token',
            'signature' => 'test-sig',
        ], [], [
            'attachment-1' => $file,
        ]);

        $response->assertSuccessful();

        Queue::assertPushed(ProcessInboundEmailJob::class, function ($job) use ($cid) {
            $saved = $job->payload['_saved_attachments'] ?? [];

            return count($saved) === 1
                && $saved[1]['content_id'] === $cid;
        });
    });

    it('uses per-field content-id over content-id-map', function () {
        Queue::fake();
        Storage::fake();

        $file = UploadedFile::fake()->image('logo.png', 100, 100);
        $fieldCid = '<field-cid@example.com>';
        $mapCid = '<map-cid@example.com>';

        $response = $this->call('POST', '/webhooks/email/inbound', [
            'recipient' => 'test@'.config('email.domain'),
            'sender' => 'someone@example.com',
            'from' => 'Someone <someone@example.com>',
            'subject' => 'CID Priority Test',
            'body-plain' => 'Test',
            'attachment-count' => '1',
            'attachment-1-content-id' => $fieldCid,
            'content-id-map' => json_encode([$mapCid => 'attachment-1']),
            'timestamp' => (string) time(),
            'token' => 'test-token',
            'signature' => 'test-sig',
        ], [], [
            'attachment-1' => $file,
        ]);

        $response->assertSuccessful();

        Queue::assertPushed(ProcessInboundEmailJob::class, function ($job) use ($fieldCid) {
            $saved = $job->payload['_saved_attachments'] ?? [];

            return $saved[1]['content_id'] === $fieldCid;
        });
    });
});
