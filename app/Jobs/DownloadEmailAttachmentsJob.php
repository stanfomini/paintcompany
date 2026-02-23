<?php

namespace App\Jobs;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DownloadEmailAttachmentsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [30, 60, 120];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public EmailMessage $message,
        public array $payload,
    ) {
        $this->onConnection('redis');
    }

    /**
     * Download and store email attachments from the Mailgun webhook payload.
     */
    public function handle(): void
    {
        $attachmentCount = (int) ($this->payload['attachment-count'] ?? 0);

        for ($i = 1; $i <= $attachmentCount; $i++) {
            $url = $this->payload["attachment-{$i}"] ?? null;
            if (! $url) {
                continue;
            }

            try {
                $response = Http::withBasicAuth('api', config('services.mailgun.secret'))
                    ->timeout(60)
                    ->get($url);

                if (! $response->successful()) {
                    Log::warning('Email attachment download failed', [
                        'message_id' => $this->message->id,
                        'attachment' => $i,
                        'status' => $response->status(),
                    ]);

                    continue;
                }
            } catch (\Throwable $e) {
                Log::warning('Email attachment download error', [
                    'message_id' => $this->message->id,
                    'attachment' => $i,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $originalFilename = $this->payload["attachment-{$i}-filename"] ?? "attachment-{$i}";
            $mimeType = $this->payload["attachment-{$i}-content-type"] ?? 'application/octet-stream';
            $contentId = $this->payload["attachment-{$i}-content-id"] ?? null;

            $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $sluggedName = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME));
            $filename = Str::uuid().'-'.$sluggedName.($extension ? '.'.$extension : '');
            $path = 'email-attachments/'.$this->message->id.'/'.$filename;

            Storage::put($path, $response->body());

            EmailAttachment::create([
                'email_message_id' => $this->message->id,
                'path' => $path,
                'filename' => $filename,
                'original_filename' => $originalFilename,
                'mime_type' => $mimeType,
                'file_size' => strlen($response->body()),
                'content_id' => $contentId ? trim($contentId, '<>') : null,
                'is_inline' => (bool) $contentId,
            ]);
        }
    }
}
