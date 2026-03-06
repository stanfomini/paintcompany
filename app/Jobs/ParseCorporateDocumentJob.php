<?php

namespace App\Jobs;

use App\Models\CorporateDocument;
use App\Services\PythonBridgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ParseCorporateDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public CorporateDocument $document
    ) {
        $this->onConnection('redis');
    }

    public function handle(PythonBridgeService $python): void
    {
        if (! $python->isEnabled()) {
            $this->document->update([
                'status' => 'error',
                'parse_error' => 'Python service is not enabled',
            ]);

            return;
        }

        if (! Storage::exists($this->document->original_file_path)) {
            $this->document->update([
                'status' => 'error',
                'parse_error' => 'PDF file not found in storage',
            ]);

            return;
        }

        // Download to a temp file so Python can read it (works with any disk driver)
        $tempFile = tempnam(sys_get_temp_dir(), 'corp_doc_').'.pdf';
        file_put_contents($tempFile, Storage::get($this->document->original_file_path));

        try {
            $result = $python->parsePdf($tempFile);
        } finally {
            @unlink($tempFile);
        }

        if (! empty($result['success']) && $result['success'] === true) {
            $this->document->update([
                'status' => 'ready',
                'parsed_content' => $result['sections'] ?? [],
                'page_count' => $result['page_count'] ?? null,
                'parse_error' => null,
            ]);
        } else {
            $this->document->update([
                'status' => 'error',
                'parse_error' => $result['error'] ?? 'Unknown parsing error',
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ParseCorporateDocumentJob failed', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);

        $this->document->update([
            'status' => 'error',
            'parse_error' => 'Job failed: '.$exception->getMessage(),
        ]);
    }
}
