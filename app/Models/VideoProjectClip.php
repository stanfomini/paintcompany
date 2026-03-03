<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class VideoProjectClip extends Model
{
    /** @use HasFactory<\Database\Factories\VideoProjectClipFactory> */
    use HasFactory;

    protected $fillable = [
        'video_project_scene_id',
        'slot',
        'source_type',
        'source_id',
        'in_point_ms',
        'out_point_ms',
        'start_ms',
        'volume',
        'fit_mode',
        'metadata',
        'transition_type',
        'transition_duration_ms',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'in_point_ms' => 'integer',
            'out_point_ms' => 'integer',
            'start_ms' => 'integer',
            'volume' => 'integer',
            'metadata' => 'array',
            'transition_duration_ms' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(VideoProjectScene::class, 'video_project_scene_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the duration of this clip in milliseconds.
     *
     * Uses out_point_ms if set, otherwise falls back to the source's
     * duration. Returns the range from in_point_ms to the end point.
     */
    public function getDurationMs(): int
    {
        $inPoint = $this->in_point_ms ?? 0;

        if ($this->out_point_ms) {
            return $this->out_point_ms - $inPoint;
        }

        $source = $this->source;

        if ($source && isset($source->duration_seconds)) {
            return (int) ($source->duration_seconds * 1000) - $inPoint;
        }

        return 5000 - $inPoint;
    }
}
