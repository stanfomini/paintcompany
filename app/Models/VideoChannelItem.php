<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoChannelItem extends Model
{
    /** @use HasFactory<\Database\Factories\VideoChannelItemFactory> */
    use HasFactory;

    protected $fillable = [
        'video_channel_id',
        'video_recording_id',
        'sort_order',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(VideoChannel::class, 'video_channel_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(VideoRecording::class, 'video_recording_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}
