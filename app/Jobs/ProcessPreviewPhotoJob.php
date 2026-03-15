<?php

namespace App\Jobs;

use App\Models\CompanySetting;
use App\Models\ProjectPreviewPhoto;
use App\Models\ProjectPreviewVisualization;
use App\Services\PythonBridgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessPreviewPhotoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    public function __construct(
        public ProjectPreviewPhoto $photo,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Processing preview photo', ['photo_id' => $this->photo->id]);

        try {
            // Step 1: Run substrate detection via Python service
            $python = app(PythonBridgeService::class);
            $photoUrl = Storage::disk($this->photo->disk)->url($this->photo->original_path);

            try {
                $analysis = $python->detectSubstrate($photoUrl);
                $this->photo->update([
                    'substrate_analysis' => $analysis,
                    'condition_notes' => $this->summarizeCondition($analysis),
                ]);
            } catch (\Exception $e) {
                // Substrate detection is optional — don't fail the whole job
                Log::warning('Substrate detection failed for preview photo', [
                    'photo_id' => $this->photo->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Step 2: Get default visualization colors
            $colors = CompanySetting::getInstance()->getDefaultVisualizationColors();

            // Step 3: Create visualization records and dispatch jobs
            foreach ($colors as $index => $color) {
                $visualization = ProjectPreviewVisualization::create([
                    'project_preview_photo_id' => $this->photo->id,
                    'result_image_path' => '',
                    'color_name' => $color['name'],
                    'color_hex' => $color['hex'],
                    'color_brand' => $color['brand'] ?? null,
                    'is_primary' => $index === 0,
                    'status' => ProjectPreviewVisualization::STATUS_PENDING,
                    'sort_order' => $index,
                ]);

                GeneratePreviewVisualizationJob::dispatch($visualization);
            }

            Log::info('Preview photo processed, dispatched visualization jobs', [
                'photo_id' => $this->photo->id,
                'visualization_count' => count($colors),
            ]);
        } catch (\Exception $e) {
            Log::error('Preview photo processing failed', [
                'photo_id' => $this->photo->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Summarize substrate analysis into human-readable condition notes.
     */
    protected function summarizeCondition(?array $analysis): ?string
    {
        if (empty($analysis)) {
            return null;
        }

        $conditions = [];

        if (isset($analysis['condition'])) {
            $conditions[] = $analysis['condition'];
        }

        if (isset($analysis['issues']) && is_array($analysis['issues'])) {
            $conditions = array_merge($conditions, $analysis['issues']);
        }

        return empty($conditions) ? null : implode('. ', $conditions);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('Preview photo processing job failed permanently', [
            'photo_id' => $this->photo->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
