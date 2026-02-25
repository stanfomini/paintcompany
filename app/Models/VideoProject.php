<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class VideoProject extends Model
{
    /** @use HasFactory<\Database\Factories\VideoProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'status',
        'resolution_width',
        'resolution_height',
        'fps',
        'duration_seconds',
        'video_recording_id',
        'share_token',
        'visibility',
        'thumbnail_path',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'resolution_width' => 'integer',
            'resolution_height' => 'integer',
            'fps' => 'integer',
            'duration_seconds' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VideoProject $project) {
            if (empty($project->share_token)) {
                $project->share_token = Str::random(32);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(VideoProjectScene::class)->orderBy('sort_order');
    }

    public function voiceovers(): HasMany
    {
        return $this->hasMany(VideoProjectVoiceover::class)->orderBy('sort_order');
    }

    public function renderedVideo(): BelongsTo
    {
        return $this->belongsTo(VideoRecording::class, 'video_recording_id');
    }

    /**
     * Get the computed duration in milliseconds from all scenes.
     */
    public function getComputedDurationMs(): int
    {
        return (int) $this->scenes->sum(fn (VideoProjectScene $scene) => $scene->getEffectiveDurationMs());
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isRendering(): bool
    {
        return $this->status === 'rendering';
    }

    public function isRendered(): bool
    {
        return $this->status === 'rendered';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
