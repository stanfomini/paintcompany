<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoRecording extends Model
{
    /** @use HasFactory<\Database\Factories\VideoRecordingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'status',
        'original_path',
        'video_path',
        'thumbnail_path',
        'disk',
        'duration_seconds',
        'file_size_bytes',
        'width',
        'height',
        'mime_type',
        'recordable_type',
        'recordable_id',
        'share_token',
        'show_on_proposal',
        'error_message',
        'visibility',
        'description',
        'metadata',
        'content_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'file_size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'show_on_proposal' => 'boolean',
            'metadata' => 'array',
            'content_updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VideoRecording $recording) {
            if (empty($recording->share_token)) {
                $recording->share_token = Str::random(32);
            }
            $recording->content_updated_at = now();
        });

        static::updating(function (VideoRecording $recording) {
            if ($recording->isDirty('status') && $recording->status === 'ready') {
                $recording->content_updated_at = now();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recordable(): MorphTo
    {
        return $this->morphTo();
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(VideoChannel::class, 'video_channel_items')
            ->withPivot(['sort_order', 'published_at'])
            ->withTimestamps();
    }

    public function views(): HasMany
    {
        return $this->hasMany(VideoView::class);
    }

    public function emails(): HasMany
    {
        return $this->hasMany(VideoEmail::class);
    }

    public function scopePublicVisible(Builder $query): Builder
    {
        return $query->where('visibility', 'public');
    }

    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('visibility', 'internal');
    }

    public function scopeUnlisted(Builder $query): Builder
    {
        return $query->where('visibility', 'unlisted');
    }

    public function getVideoUrl(): ?string
    {
        $path = $this->video_path ?? $this->original_path;

        if (! $path) {
            return null;
        }

        return $this->getSignedOrPublicUrl($path, 'video');
    }

    public function getThumbnailUrl(): ?string
    {
        if (! $this->thumbnail_path) {
            return null;
        }

        return $this->getSignedOrPublicUrl($this->thumbnail_path, 'thumb');
    }

    /**
     * Get a signed URL for S3 disks, or a public URL for local disks.
     */
    private function getSignedOrPublicUrl(string $path, string $variant): string
    {
        $diskName = $this->disk ?? config('filesystems.default');
        $disk = Storage::disk($diskName);

        if (method_exists($disk, 'temporaryUrl')) {
            try {
                $cacheKey = "video_url:{$this->id}:{$variant}:{$this->updated_at?->timestamp}";

                return Cache::remember($cacheKey, now()->addMinutes(55), function () use ($disk, $path) {
                    return $disk->temporaryUrl($path, now()->addMinutes(60));
                });
            } catch (\RuntimeException $e) {
                // Local disk doesn't support temporaryUrl
            }
        }

        return $disk->url($path).'?v='.$this->updated_at?->timestamp;
    }

    public function getShareableUrl(): ?string
    {
        if (! $this->share_token) {
            return null;
        }

        return route('video-recording.public', $this->share_token);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, ['uploading', 'processing']);
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function hasUnviewedContent(User $user): bool
    {
        if (! $this->content_updated_at) {
            return false;
        }

        $latestView = $this->views()
            ->where('viewer_id', $user->id)
            ->latest()
            ->first();

        if (! $latestView) {
            return true;
        }

        return $this->content_updated_at->isAfter($latestView->created_at);
    }

    public function scopeForProposal($query)
    {
        return $query->where('status', 'ready')->where('show_on_proposal', true);
    }

    /**
     * Get formatted duration string.
     */
    public function getFormattedDurationAttribute(): ?string
    {
        if (! $this->duration_seconds) {
            return null;
        }

        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }
}
