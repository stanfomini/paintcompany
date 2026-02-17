<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'file_size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'show_on_proposal' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VideoRecording $recording) {
            if (empty($recording->share_token)) {
                $recording->share_token = Str::random(32);
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
