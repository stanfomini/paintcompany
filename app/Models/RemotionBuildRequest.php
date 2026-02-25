<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemotionBuildRequest extends Model
{
    /** @use HasFactory<\Database\Factories\RemotionBuildRequestFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'prompt',
        'system_context',
        'status',
        'video_recording_id',
        'composition_id',
        'props_json',
        'log_path',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'props_json' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videoRecording(): BelongsTo
    {
        return $this->belongsTo(VideoRecording::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'building']);
    }

    public function markBuilding(): void
    {
        $this->update(['status' => 'building', 'started_at' => now()]);
    }

    public function markCompleted(int $videoRecordingId): void
    {
        $this->update([
            'status' => 'completed',
            'video_recording_id' => $videoRecordingId,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
