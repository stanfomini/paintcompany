<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoView extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'property_photo_id',
        'user_id',
        'viewed_at',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(PropertyPhoto::class, 'property_photo_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record that a user viewed a photo. Upserts the viewed_at timestamp.
     */
    public static function markViewed(PropertyPhoto $photo, User $user): self
    {
        return static::updateOrCreate(
            [
                'property_photo_id' => $photo->id,
                'user_id' => $user->id,
            ],
            [
                'viewed_at' => now(),
            ]
        );
    }

    /**
     * Check if a user has unviewed changes on a photo.
     */
    public static function hasUnviewedChanges(PropertyPhoto $photo, User $user): bool
    {
        if (! $photo->content_updated_at) {
            return false;
        }

        $view = static::where('property_photo_id', $photo->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $view) {
            return true;
        }

        return $photo->content_updated_at->isAfter($view->viewed_at);
    }
}
