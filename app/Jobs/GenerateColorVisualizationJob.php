<?php

namespace App\Jobs;

use App\Ai\Agents\ColorVisualizerAgent;
use App\Events\ColorVisualizationCompleted;
use App\Models\ColorVisualization;
use App\Models\CompanySetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Image as AiImage;
use Laravel\Ai\Image;

class GenerateColorVisualizationJob implements ShouldQueue
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
        public ColorVisualization $visualization,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting color visualization generation', [
            'uuid' => $this->visualization->uuid,
            'prompt' => $this->visualization->prompt,
        ]);

        $this->visualization->markAsProcessing();

        try {
            // Step 1: Resolve color names to hex values using AI agent
            $agent = new ColorVisualizerAgent;
            $agentResponse = $agent->prompt(
                "Resolve the following paint color description to exact hex values: {$this->visualization->prompt}"
            );

            $resolvedText = (string) $agentResponse;
            $parsedColors = $this->parseColorResponse($resolvedText);

            Log::info('Colors resolved', [
                'uuid' => $this->visualization->uuid,
                'parsed_colors' => $parsedColors,
            ]);

            // Step 2: Construct enhanced prompt with hex values
            $enhancedPrompt = $this->buildImagePrompt($parsedColors);

            // Step 3: Generate the recolored image
            $disk = $this->visualization->disk ?? config('filesystems.default');
            $provider = config('services.color_visualizer.image_provider', 'openai');
            $model = config('services.color_visualizer.image_model', 'gpt-image-1');

            $imageResponse = Image::of($enhancedPrompt)
                ->attachments([
                    AiImage::fromStorage($this->visualization->original_image_path, $disk),
                ])
                ->quality('high')
                ->generate(provider: $provider, model: $model);

            // Step 4: Store the result image with public visibility
            $resultPath = "visualizations/{$this->visualization->uuid}.png";
            $imageResponse->storeAs('visualizations', "{$this->visualization->uuid}.png", $disk);
            Storage::disk($disk)->setVisibility($resultPath, 'public');

            // Step 5: Update the visualization record
            $this->visualization->markAsCompleted($resultPath, $parsedColors);

            Log::info('Color visualization completed', [
                'uuid' => $this->visualization->uuid,
                'result_path' => $resultPath,
            ]);

            // Step 6: Broadcast completion
            event(new ColorVisualizationCompleted($this->visualization));

        } catch (\Exception $e) {
            Log::error('Color visualization generation failed', [
                'uuid' => $this->visualization->uuid,
                'error' => $e->getMessage(),
            ]);

            $this->visualization->markAsFailed($e->getMessage());

            throw $e;
        }
    }

    /**
     * Parse the agent's color resolution response into structured data.
     *
     * @return array<int, array{name: string, code: string, hex: string, brand: string, surface: string}>
     */
    protected function parseColorResponse(string $response): array
    {
        $colors = [];
        $lines = explode("\n", $response);

        // Known brand names for splitting brand from color name
        $knownBrands = ['Sherwin-Williams', 'Benjamin Moore', 'Behr', 'PPG'];
        $brandPattern = implode('|', array_map('preg_quote', $knownBrands));

        foreach ($lines as $line) {
            $line = trim($line, "- \t");

            // Try to extract: Surface: X | Color: Brand Name (Code) | Hex: #VALUE
            if (preg_match('/Surface:\s*(.+?)\s*\|\s*Color:\s*('.$brandPattern.')\s+(.+?)\s*\((.+?)\)\s*\|\s*Hex:\s*(#[A-Fa-f0-9]{6})/', $line, $matches)) {
                $colors[] = [
                    'surface' => trim($matches[1]),
                    'brand' => trim($matches[2]),
                    'name' => trim($matches[3]),
                    'code' => trim($matches[4]),
                    'hex' => strtoupper(trim($matches[5])),
                ];

                continue;
            }

            // Fallback: try to extract known brand + color name (Code): #HEX
            if (preg_match('/('.$brandPattern.')\s+(.+?)\s*\((.+?)\):\s*(#[A-Fa-f0-9]{6})/', $line, $matches)) {
                $colors[] = [
                    'surface' => '',
                    'brand' => trim($matches[1]),
                    'name' => trim($matches[2]),
                    'code' => trim($matches[3]),
                    'hex' => strtoupper(trim($matches[4])),
                ];
            }
        }

        return $colors;
    }

    /**
     * Build an enhanced image prompt with exact hex values.
     */
    protected function buildImagePrompt(array $parsedColors): string
    {
        $colorInstructions = collect($parsedColors)
            ->map(function (array $color) {
                $surface = $color['surface'] ?: 'specified surface';

                return "Paint the {$surface} with {$color['brand']} {$color['name']} (exact color: {$color['hex']})";
            })
            ->implode('. ');

        $template = CompanySetting::getInstance()->visualizer_image_prompt
            ?? config('services.color_visualizer.default_image_prompt');

        return str_replace('{color_instructions}', $colorInstructions, $template);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('Color visualization job failed permanently', [
            'uuid' => $this->visualization->uuid,
            'error' => $exception?->getMessage(),
        ]);

        $this->visualization->markAsFailed(
            $exception?->getMessage() ?? 'Unknown error'
        );
    }
}
