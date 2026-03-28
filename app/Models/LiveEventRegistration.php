<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveEventRegistration extends Model
{
    /** @use HasFactory<\Database\Factories\LiveEventRegistrationFactory> */
    use HasFactory;

    protected $fillable = [
        'live_event_id',
        'user_id',
        'name',
        'email',
        'phone',
        'attended',
    ];

    protected function casts(): array
    {
        return [
            'attended' => 'boolean',
        ];
    }

    /**
     * Get the live event this registration belongs to.
     */
    public function liveEvent(): BelongsTo
    {
        return $this->belongsTo(LiveEvent::class);
    }

    /**
     * Get the user for this registration.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
