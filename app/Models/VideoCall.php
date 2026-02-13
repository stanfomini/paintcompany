<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class VideoCall extends Model
{
    /** @use HasFactory<\Database\Factories\VideoCallFactory> */
    use HasFactory;

    protected $fillable = [
        'job_id',
        'callable_type',
        'callable_id',
        'initiated_by',
        'room_id',
        'status',
        'recording_path',
        'recording_disk',
        'duration_seconds',
        'participants',
        'started_at',
        'ended_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Get the callable model (Job, Deal, etc.) - polymorphic.
     */
    public function callable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the job for this video call (backward-compatible accessor).
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    /**
     * Get a temporary signed URL for the recording playback.
     */
    public function getRecordingUrl(): ?string
    {
        if (! $this->recording_path) {
            return null;
        }

        $disk = $this->recording_disk ?? 'video-recordings';

        // For local disk, return the storage path directly
        if (Storage::disk($disk)->getConfig()['driver'] === 'local') {
            return Storage::disk($disk)->url($this->recording_path);
        }

        return Storage::disk($disk)->temporaryUrl(
            $this->recording_path,
            now()->addHours(4)
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isEnded(): bool
    {
        return $this->status === 'ended';
    }

    /**
     * Generate a shareable signed URL for this recording.
     */
    public function getShareableUrl(): ?string
    {
        if (! $this->recording_path) {
            return null;
        }

        return URL::signedRoute('video-call.recording.public', [
            'videoCall' => $this->room_id,
        ], now()->addDays(7));
    }
}
