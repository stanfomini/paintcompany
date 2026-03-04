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

        foreach ($scene->clips()->get() as $clip) {
            if ($clip->source_type !== 'video-recording') {
                continue;
            }

            // Load recording directly instead of via morph relation
            // to avoid stale morph map issues in queue workers
            $recording = VideoRecording::find($clip->source_id);
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
                $clipOffset = $clip->in_point_ms ?? 0;
                $startMs = $clipOffset + (int) ($segment['start'] * 1000);
                $endMs = $clipOffset + (int) ($segment['end'] * 1000);

                $segments[] = [
                    'text' => trim($segment['text']),
                    'start_ms' => $startMs,
                    'end_ms' => $endMs,
                ];
            }
        }

        return $segments;
    }

    /**
     * Generate caption segments from a voiceover recording.
     *
     * @return array<int, array{text: string, start_ms: int, end_ms: int}>
     */
    public function generateFromVoiceover(VideoProjectScene $scene): array
    {
        $project = $scene->project;
        $voiceover = $project->voiceovers()->with('recording')->first();

        if (! $voiceover || ! $voiceover->recording?->getVideoUrl()) {
            return [];
        }

        try {
            $result = $this->python->transcribeAudio($voiceover->recording->getVideoUrl());
        } catch (\Exception $e) {
            report($e);

            return [];
        }

        if (! isset($result['segments'])) {
            return [];
        }

        $segments = [];
        $voOffset = $voiceover->start_ms ?? 0;
        $inPoint = $voiceover->in_point_ms ?? 0;

        foreach ($result['segments'] as $segment) {
            $segStartMs = (int) ($segment['start'] * 1000);
            $segEndMs = (int) ($segment['end'] * 1000);

            // Skip segments before the in_point trim
            if ($segEndMs <= $inPoint) {
                continue;
            }

            // If out_point is set, skip segments beyond it
            if ($voiceover->out_point_ms && $segStartMs >= $voiceover->out_point_ms) {
                continue;
            }

            // Adjust for in_point trim and voiceover offset on timeline
            $startMs = $voOffset + max(0, $segStartMs - $inPoint);
            $endMs = $voOffset + ($segEndMs - $inPoint);

            $segments[] = [
                'text' => trim($segment['text']),
                'start_ms' => $startMs,
                'end_ms' => $endMs,
            ];
        }

        return $segments;
    }
}
