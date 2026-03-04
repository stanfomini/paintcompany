<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoProjectVoiceover extends Model
{
    /** @use HasFactory<\Database\Factories\VideoProjectVoiceoverFactory> */
    use HasFactory;

    protected $fillable = [
        'video_project_id',
        'video_recording_id',
        'start_ms',
        'in_point_ms',
        'out_point_ms',
        'volume',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'start_ms' => 'integer',
            'in_point_ms' => 'integer',
            'out_point_ms' => 'integer',
            'volume' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the effective duration of this voiceover in milliseconds.
     *
     * Uses out_point_ms if set, otherwise falls back to the recording's
     * duration. Returns the range from in_point_ms to the end point.
     */
    public function getDurationMs(): int
    {
        $inPoint = $this->in_point_ms ?? 0;

        if ($this->out_point_ms) {
            return $this->out_point_ms - $inPoint;
        }

        $recording = $this->recording;

        if ($recording && $recording->duration_seconds) {
            return (int) ($recording->duration_seconds * 1000) - $inPoint;
        }

        return 5000 - $inPoint;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    public function recording(): BelongsTo
    {
        return $this->belongsTo(VideoRecording::class, 'video_recording_id');
    }
}
