<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveEventResource extends Model
{
    /** @use HasFactory<\Database\Factories\LiveEventResourceFactory> */
    use HasFactory;

    protected $fillable = [
        'live_event_id',
        'title',
        'url',
        'description',
        'type',
        'is_pinned',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
        ];
    }

    /**
     * Get the live event this resource belongs to.
     */
    public function liveEvent(): BelongsTo
    {
        return $this->belongsTo(LiveEvent::class);
    }
}
