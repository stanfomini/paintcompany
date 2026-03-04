<?php

namespace App\Jobs;

use App\Models\VideoProjectScene;
use App\Services\CaptionGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateSceneCaptionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    /** @var array<int, int> */
    public array $backoff = [15, 60];

    public function __construct(
        public VideoProjectScene $scene,
        public string $source = 'video',
    ) {}

    public function handle(CaptionGenerationService $service): void
    {
        $segments = $this->source === 'voiceover'
            ? $service->generateFromVoiceover($this->scene)
            : $service->generateForScene($this->scene);

        $this->scene->overlays()->where('type', 'caption')->delete();

        $sceneDurationMs = $this->scene->getEffectiveDurationMs();

        foreach ($segments as $index => $segment) {
            // Cap end_ms to scene duration so captions don't overflow
            $endMs = min($segment['end_ms'], $sceneDurationMs);

            // Skip segments that start after the scene ends
            if ($segment['start_ms'] >= $sceneDurationMs) {
                continue;
            }

            $this->scene->overlays()->create([
                'type' => 'caption',
                'content' => $segment['text'],
                'style' => [
                    'font_size' => 24,
                    'color' => '#FFFFFF',
                    'bg_color' => 'rgba(0,0,0,0.7)',
                    'font_weight' => 'bold',
                    'alignment' => 'center',
                ],
                'position_x' => 0.1,
                'position_y' => 0.85,
                'width' => 0.8,
                'start_ms' => $segment['start_ms'],
                'end_ms' => $endMs,
                'sort_order' => 900 + $index,
            ]);
        }
    }
}
