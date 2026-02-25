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

    public function __construct(
        public VideoProjectScene $scene,
    ) {}

    public function handle(CaptionGenerationService $service): void
    {
        $segments = $service->generateForScene($this->scene);

        $this->scene->overlays()->where('type', 'caption')->delete();

        foreach ($segments as $index => $segment) {
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
                'end_ms' => $segment['end_ms'],
                'sort_order' => 900 + $index,
            ]);
        }
    }
}
