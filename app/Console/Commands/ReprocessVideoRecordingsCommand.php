<?php

namespace App\Console\Commands;

use App\Jobs\Python\ProcessVideoRecordingJob;
use App\Models\VideoRecording;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReprocessVideoRecordingsCommand extends Command
{
    protected $signature = 'video:reprocess {--ids= : Comma-separated list of recording IDs to reprocess}';

    protected $description = 'Re-process existing video recordings through the Python transcoding pipeline with updated FFmpeg settings';

    public function handle(): int
    {
        $idsOption = $this->option('ids');

        if (! $idsOption) {
            $this->error('Please provide recording IDs with --ids=1,2,3');

            return self::FAILURE;
        }

        $ids = array_map('intval', array_filter(explode(',', $idsOption)));

        if (empty($ids)) {
            $this->error('No valid IDs provided.');

            return self::FAILURE;
        }

        $recordings = VideoRecording::whereIn('id', $ids)->get();

        if ($recordings->isEmpty()) {
            $this->error('No recordings found for the given IDs.');

            return self::FAILURE;
        }

        $this->info("Found {$recordings->count()} recording(s) to reprocess.");

        foreach ($recordings as $recording) {
            $this->reprocess($recording);
        }

        $this->info('Done. All recordings have been queued for reprocessing.');

        return self::SUCCESS;
    }

    private function reprocess(VideoRecording $recording): void
    {
        $disk = Storage::disk($recording->disk);

        // Determine source: prefer existing processed video, fall back to original
        $sourcePath = $recording->video_path ?? $recording->original_path;

        if (! $sourcePath || ! $disk->exists($sourcePath)) {
            $this->warn("Recording #{$recording->id}: No source file found at '{$sourcePath}', skipping.");

            return;
        }

        $this->info("Recording #{$recording->id} ({$recording->title}): downloading from '{$sourcePath}'...");

        // Download current file to use as the "original" for reprocessing
        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'mp4';
        $newOriginalPath = 'video-recordings/originals/'.Str::uuid().'.'.$extension;

        // Copy the current file to a new original path for the job to pick up
        $disk->copy($sourcePath, $newOriginalPath);

        // Update the recording to point to the new original and reset for processing
        $recording->update([
            'original_path' => $newOriginalPath,
            'status' => 'processing',
            'error_message' => null,
        ]);

        // Dispatch the standard processing job
        ProcessVideoRecordingJob::dispatch($recording);

        $this->info("Recording #{$recording->id}: queued for reprocessing.");
    }
}
