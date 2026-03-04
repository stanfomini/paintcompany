<?php

namespace App\Jobs;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
     * Process email attachments that were pre-saved by the webhook controller.
     */
    public function handle(): void
    {
        $savedAttachments = $this->payload['_saved_attachments'] ?? [];

        if (empty($savedAttachments)) {
            Log::info('Email attachment job: no saved attachments found', [
                'message_id' => $this->message->id,
            ]);

            return;
        }

        $bodyHtml = $this->message->body_html ?? '';

        foreach ($savedAttachments as $i => $attachmentData) {
            $tempPath = $attachmentData['path'] ?? null;
            if (! $tempPath || ! Storage::exists($tempPath)) {
                Log::warning('Email attachment: temp file not found', [
                    'message_id' => $this->message->id,
                    'attachment' => $i,
                    'path' => $tempPath,
                ]);

                continue;
            }

            $originalFilename = $attachmentData['filename'] ?? "attachment-{$i}";
            $mimeType = $attachmentData['mime_type'] ?? 'application/octet-stream';
            $contentId = $attachmentData['content_id'] ?? null;
            $fileSize = $attachmentData['size'] ?? Storage::size($tempPath);

            // Only mark as inline if the CID is actually referenced in the body HTML
            $trimmedCid = $contentId ? trim($contentId, '<>') : null;
            $isInline = $trimmedCid && str_contains($bodyHtml, 'cid:'.$trimmedCid);

            $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $sluggedName = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME));
            $filename = Str::uuid().'-'.$sluggedName.($extension ? '.'.$extension : '');
            $finalPath = 'email-attachments/'.$this->message->id.'/'.$filename;

            // Move from pending to final location
            Storage::move($tempPath, $finalPath);

            EmailAttachment::create([
                'email_message_id' => $this->message->id,
                'path' => $finalPath,
                'filename' => $filename,
                'original_filename' => $originalFilename,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'content_id' => $trimmedCid,
                'is_inline' => $isInline,
            ]);
        }
    }
}
