<?php

namespace App\Jobs;

use App\Models\MeetingMinutes;
use App\Services\AudioMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessMeetingAudioJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public MeetingMinutes $minutes,
        public string $tempAudioPath,
        public string $speakerName,
    ) {
        $this->onConnection('redis');
    }

    public function handle(): void
    {
        $this->minutes->update(['transcript_status' => 'processing']);

        try {
            $audioService = app(AudioMessageService::class);
            $audioResult = $audioService->processAudioMessage(
                new \Illuminate\Http\UploadedFile(
                    Storage::path($this->tempAudioPath),
                    basename($this->tempAudioPath),
                ),
                'meeting-audio',
            );

            $segments = $audioResult['segments'] ?? [];
            $segments = $this->tagSegmentsWithSpeaker($segments, $this->speakerName);

            $this->minutes->update([
                'audio_path' => $audioResult['audio_path'],
                'audio_duration' => $audioResult['audio_duration'],
                'transcript' => $audioResult['transcription'],
                'transcript_segments' => $segments ?: null,
                'transcript_status' => $audioResult['transcription'] ? 'completed' : 'failed',
                'summary_status' => $audioResult['transcription'] ? 'pending' : 'failed',
            ]);

            if ($audioResult['transcription']) {
                GenerateMeetingSummaryJob::dispatch($this->minutes);
            }
        } catch (\Exception $e) {
            Log::error('ProcessMeetingAudioJob failed', [
                'minutes_id' => $this->minutes->id,
                'error' => $e->getMessage(),
            ]);

            $this->minutes->update([
                'transcript_status' => 'failed',
                'summary_status' => 'failed',
            ]);

            throw $e;
        } finally {
            Storage::delete($this->tempAudioPath);
        }
    }

    /**
     * Merge consecutive segments into groups based on pauses, then tag with speaker.
     */
    private function tagSegmentsWithSpeaker(array $segments, string $speakerName): array
    {
        if (empty($segments)) {
            return [];
        }

        $grouped = [];
        $currentGroup = [
            'start' => $segments[0]['start'] ?? 0,
            'end' => $segments[0]['end'] ?? 0,
            'text' => trim($segments[0]['text'] ?? ''),
            'speaker' => $speakerName,
        ];

        for ($i = 1; $i < count($segments); $i++) {
            $gap = ($segments[$i]['start'] ?? 0) - ($currentGroup['end']);

            if ($gap > 2.0) {
                $grouped[] = $currentGroup;
                $currentGroup = [
                    'start' => $segments[$i]['start'] ?? 0,
                    'end' => $segments[$i]['end'] ?? 0,
                    'text' => trim($segments[$i]['text'] ?? ''),
                    'speaker' => $speakerName,
                ];
            } else {
                $currentGroup['end'] = $segments[$i]['end'] ?? $currentGroup['end'];
                $currentGroup['text'] .= ' '.trim($segments[$i]['text'] ?? '');
            }
        }

        $grouped[] = $currentGroup;

        return $grouped;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessMeetingAudioJob permanently failed', [
            'minutes_id' => $this->minutes->id,
            'error' => $exception->getMessage(),
        ]);

        $this->minutes->update([
            'transcript_status' => 'failed',
            'summary_status' => 'failed',
        ]);

        Storage::delete($this->tempAudioPath);
    }
}
