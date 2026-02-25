<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoProjectScene extends Model
{
    /** @use HasFactory<\Database\Factories\VideoProjectSceneFactory> */
    use HasFactory;

    protected $fillable = [
        'video_project_id',
        'sort_order',
        'name',
        'layout',
        'duration_ms',
        'trim_start_ms',
        'trim_end_ms',
        'transition_type',
        'transition_duration_ms',
        'background_color',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'duration_ms' => 'integer',
            'trim_start_ms' => 'integer',
            'trim_end_ms' => 'integer',
            'transition_duration_ms' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    public function clips(): HasMany
    {
        return $this->hasMany(VideoProjectClip::class, 'video_project_scene_id')->orderBy('sort_order');
    }

    public function overlays(): HasMany
    {
        return $this->hasMany(VideoProjectOverlay::class, 'video_project_scene_id')->orderBy('sort_order');
    }

    /**
     * Get the effective duration of this scene in milliseconds.
     *
     * If duration_ms is explicitly set, use that. Otherwise compute from
     * the longest clip duration (out_point - in_point), factoring in trim.
     * Falls back to 5000ms if no clips exist.
     */
    public function getEffectiveDurationMs(): int
    {
        if ($this->duration_ms) {
            return $this->duration_ms;
        }

        if ($this->clips->isEmpty()) {
            return 5000;
        }

        $longestClipMs = $this->clips->max(fn (VideoProjectClip $clip) => $clip->getDurationMs());

        $trimStart = $this->trim_start_ms ?? 0;
        $trimEnd = $this->trim_end_ms ?? 0;
        $trimmed = $longestClipMs - $trimStart - $trimEnd;

        return max($trimmed, 0);
    }
}
