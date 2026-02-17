<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoView extends Model
{
    /** @use HasFactory<\Database\Factories\VideoViewFactory> */
    use HasFactory;

    protected $fillable = [
        'video_recording_id',
        'viewer_id',
        'viewer_email',
        'ip_address',
        'user_agent',
        'watch_duration_seconds',
        'watch_percentage',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'watch_duration_seconds' => 'integer',
            'watch_percentage' => 'integer',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(VideoRecording::class, 'video_recording_id');
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewer_id');
    }
}
