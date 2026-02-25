<?php

namespace App\Services;

use App\Models\VideoProjectScene;
use App\Models\VideoRecording;

class CaptionGenerationService
{
    public function __construct(
        private PythonBridgeService $python,
    ) {}

    /**
     * Generate caption segments for a scene's clips.
     *
     * @return array<int, array{text: string, start_ms: int, end_ms: int}>
     */
    public function generateForScene(VideoProjectScene $scene): array
    {
        $segments = [];

        foreach ($scene->clips()->with('source')->get() as $clip) {
            if ($clip->source_type !== VideoRecording::class) {
                continue;
            }

            $recording = $clip->source;
            if (! $recording || ! $recording->getVideoUrl()) {
                continue;
            }

            try {
                $result = $this->python->transcribeAudio($recording->getVideoUrl());
            } catch (\Exception $e) {
                report($e);

                continue;
            }

            if (! isset($result['segments'])) {
                continue;
            }

            foreach ($result['segments'] as $segment) {
                $startMs = $clip->in_point_ms + (int) ($segment['start'] * 1000);
                $endMs = $clip->in_point_ms + (int) ($segment['end'] * 1000);

                $segments[] = [
                    'text' => trim($segment['text']),
                    'start_ms' => $startMs,
                    'end_ms' => $endMs,
                ];
            }
        }

        return $segments;
    }
}
