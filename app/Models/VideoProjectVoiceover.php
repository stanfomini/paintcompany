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
        'volume',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'start_ms' => 'integer',
            'volume' => 'integer',
            'sort_order' => 'integer',
        ];
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
