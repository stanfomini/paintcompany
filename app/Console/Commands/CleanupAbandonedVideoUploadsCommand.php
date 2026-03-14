<?php

namespace App\Console\Commands;

use App\Jobs\Python\ProcessVideoRecordingJob;
use App\Models\VideoRecording;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupAbandonedVideoUploadsCommand extends Command
{
    protected $signature = 'video:cleanup-abandoned {--minutes=30 : Minutes before an upload is considered abandoned}';

    protected $description = 'Clean up video recordings stuck in uploading status';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');

        $abandoned = VideoRecording::query()
            ->where('status', 'uploading')
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->get();

        if ($abandoned->isEmpty()) {
            $this->info('No abandoned uploads found.');
            $this->cleanupOrphanedTempFiles();

            return self::SUCCESS;
        }

        $this->info("Found {$abandoned->count()} abandoned upload(s).");

        foreach ($abandoned as $recording) {
            $this->processAbandonedRecording($recording);
        }

        $this->cleanupOrphanedTempFiles();

        return self::SUCCESS;
    }

    private function processAbandonedRecording(VideoRecording $recording): void
    {
        $tempPath = $recording->metadata['temp_chunk_path'] ?? null;

        if ($tempPath && file_exists($tempPath) && filesize($tempPath) > 0) {
            $this->info("Recording {$recording->id}: temp file found ({$this->formatBytes(filesize($tempPath))}), finalizing...");

            try {
                Storage::put($recording->original_path, file_get_contents($tempPath));

                $fileSize = filesize($tempPath);
                @unlink($tempPath);

                $metadata = $recording->metadata ?? [];
                unset($metadata['temp_chunk_path']);

                $recording->update([
                    'status' => 'processing',
                    'file_size_bytes' => $fileSize,
                    'metadata' => $metadata ?: null,
                ]);

                ProcessVideoRecordingJob::dispatch($recording);

                $this->info("Recording {$recording->id}: dispatched for processing.");
            } catch (\Exception $e) {
                $this->error("Recording {$recording->id}: finalize failed - {$e->getMessage()}");

                $recording->update([
                    'status' => 'failed',
                    'error_message' => 'Abandoned upload cleanup failed: '.$e->getMessage(),
                ]);
            }
        } else {
            $this->warn("Recording {$recording->id}: no temp file found, marking as failed.");

            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }

            $metadata = $recording->metadata ?? [];
            unset($metadata['temp_chunk_path']);

            $recording->update([
                'status' => 'failed',
                'error_message' => 'Upload incomplete - recording was abandoned',
                'metadata' => $metadata ?: null,
            ]);
        }
    }

    private function cleanupOrphanedTempFiles(): void
    {
        $tempDir = storage_path('app/tmp/video-chunks');

        if (! is_dir($tempDir)) {
            return;
        }

        $files = glob($tempDir.'/*');
        $threshold = now()->subHours(2)->getTimestamp();
        $cleaned = 0;

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $threshold) {
                @unlink($file);
                $cleaned++;
            }
        }

        if ($cleaned > 0) {
            $this->info("Cleaned up {$cleaned} orphaned temp file(s).");
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).'MB';
        }

        return round($bytes / 1024, 1).'KB';
    }
}
