<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInboundEmailJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        for ($i = 1; $i <= $attachmentCount; $i++) {
            $file = $request->file("attachment-{$i}");
            if (! $file) {
                continue;
            }

            $tempPath = 'email-attachments/pending/'.Str::uuid().'_'.$file->getClientOriginalName();
            Storage::put($tempPath, $file->getContent());

            $savedAttachments[$i] = [
                'path' => $tempPath,
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
                'content_id' => $payload["attachment-{$i}-content-id"] ?? null,
            ];
        }

        $payload['_saved_attachments'] = $savedAttachments;

        ProcessInboundEmailJob::dispatch($payload);

        return response()->json(['status' => 'queued']);
    }
}
