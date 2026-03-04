<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInboundEmailJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmailInboundWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        // Get only non-file input (UploadedFile objects cannot be serialized to queue)
        $payload = $request->except(
            collect(range(1, (int) ($request->input('attachment-count', 0))))
                ->map(fn ($i) => "attachment-{$i}")
                ->all()
        );

        // Save uploaded attachment files to storage before dispatching the job,
        // since file uploads are lost after the request ends.
        $attachmentCount = (int) ($payload['attachment-count'] ?? 0);
        $savedAttachments = [];

        // Parse Mailgun's content-id-map JSON as fallback for per-attachment content IDs
        $contentIdMap = [];
        if (! empty($payload['content-id-map'])) {
            $decoded = json_decode($payload['content-id-map'], true);
            if (is_array($decoded)) {
                // Mailgun format: {"<cid>": "attachment-N"} — invert to {"attachment-N": "<cid>"}
                $contentIdMap = array_flip($decoded);
            }
        }

        for ($i = 1; $i <= $attachmentCount; $i++) {
            $file = $request->file("attachment-{$i}");
            if (! $file) {
                continue;
            }

            $tempPath = 'email-attachments/pending/'.Str::uuid().'_'.$file->getClientOriginalName();
            Storage::put($tempPath, $file->getContent());

            // Use per-field content-id first, then fall back to content-id-map
            $contentId = $payload["attachment-{$i}-content-id"]
                ?? ($contentIdMap["attachment-{$i}"] ?? null);

            $savedAttachments[$i] = [
                'path' => $tempPath,
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
                'content_id' => $contentId,
            ];
        }

        $payload['_saved_attachments'] = $savedAttachments;

        Log::info('Email webhook: received inbound email', [
            'sender' => $payload['sender'] ?? 'unknown',
            'recipient' => $payload['recipient'] ?? 'unknown',
            'subject' => $payload['subject'] ?? 'no subject',
            'attachment_count' => $attachmentCount,
            'saved_filenames' => collect($savedAttachments)->pluck('filename')->all(),
        ]);

        ProcessInboundEmailJob::dispatch($payload);

        return response()->json(['status' => 'queued']);
    }
}
