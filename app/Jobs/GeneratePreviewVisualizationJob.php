<?php

namespace App\Jobs;

use App\Models\CompanySetting;
use App\Models\ProjectPreviewVisualization;
use App\Services\SmsBotFlowService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image as AiImage;
use Laravel\Ai\Image;

class GeneratePreviewVisualizationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 180;

    public function __construct(
        public ProjectPreviewVisualization $visualization,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting preview visualization generation', [
            'visualization_id' => $this->visualization->id,
            'color' => $this->visualization->color_name,
            'hex' => $this->visualization->color_hex,
        ]);

        $this->visualization->markAsProcessing();

        try {
            $photo = $this->visualization->photo;
            $disk = $photo->disk ?? config('filesystems.default');

            // Build prompt for image generation
            $prompt = $this->buildPrompt();

            // Generate the recolored image using Laravel AI
            $imageResponse = Image::of($prompt)
                ->attachments([
                    AiImage::fromStorage($photo->original_path, $disk),
                ])
                ->quality('high')
                ->generate(
                    provider: config('services.color_visualizer.image_provider', 'openai'),
                    model: config('services.color_visualizer.image_model', 'gpt-image-1'),
                );

            // Store result
            $filename = "preview-visualizations/{$this->visualization->id}-".Str::slug($this->visualization->color_name).'.png';
            $imageResponse->storeAs('preview-visualizations', basename($filename), $disk);
            Storage::disk($disk)->setVisibility($filename, 'public');

            $this->visualization->markAsCompleted($filename);

            Log::info('Preview visualization completed', [
                'visualization_id' => $this->visualization->id,
                'result_path' => $filename,
            ]);

            // Check if all visualizations for this photo are complete
            $this->checkAllVisualizationsComplete();

        } catch (\Exception $e) {
            Log::error('Preview visualization generation failed', [
                'visualization_id' => $this->visualization->id,
                'error' => $e->getMessage(),
            ]);
            $this->visualization->markAsFailed();

            throw $e;
        }
    }

    /**
     * Build the image prompt with color details.
     */
    protected function buildPrompt(): string
    {
        $template = CompanySetting::getInstance()->visualizer_image_prompt
            ?? 'Repaint the walls in the provided room photo with the exact color {color_hex} ({color_name} by {color_brand}). Keep all furniture, fixtures, and architectural details exactly as they are. Only change the wall color. Produce a photorealistic result.';

        return str_replace(
            ['{color_hex}', '{color_name}', '{color_brand}'],
            [$this->visualization->color_hex, $this->visualization->color_name, $this->visualization->color_brand ?? 'unknown brand'],
            $template
        );
    }

    /**
     * When all visualizations for a photo are done, notify the bot service.
     */
    protected function checkAllVisualizationsComplete(): void
    {
        $photo = $this->visualization->photo;
        $allDone = $photo->visualizations()
            ->whereNotIn('status', [
                ProjectPreviewVisualization::STATUS_COMPLETED,
                ProjectPreviewVisualization::STATUS_FAILED,
            ])
            ->doesntExist();

        if ($allDone) {
            $preview = $photo->preview;
            $conversation = $preview->smsConversation;

            if ($conversation && $conversation->isActive()) {
                app(SmsBotFlowService::class)->onVisualizationComplete(
                    $conversation,
                    $this->visualization->result_image_path ?? ''
                );
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('Preview visualization job failed permanently', [
            'visualization_id' => $this->visualization->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->visualization->markAsFailed();
    }
}
