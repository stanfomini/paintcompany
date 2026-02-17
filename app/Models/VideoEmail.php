<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoEmail extends Model
{
    /** @use HasFactory<\Database\Factories\VideoEmailFactory> */
    use HasFactory;

    protected $fillable = [
        'video_recording_id',
        'sent_by',
        'recipient_email',
        'recipient_name',
        'subject',
        'message',
        'opened_at',
        'clicked_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(VideoRecording::class, 'video_recording_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function markOpened(): void
    {
        if (! $this->opened_at) {
            $this->update(['opened_at' => now()]);
        }
    }

    public function markClicked(): void
    {
        if (! $this->clicked_at) {
            $this->update(['clicked_at' => now()]);
        }
    }
}
