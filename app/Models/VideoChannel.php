<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Str;

class VideoChannel extends Model
{
    /** @use HasFactory<\Database\Factories\VideoChannelFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'thumbnail_path',
        'visibility',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VideoChannel $channel) {
            if (empty($channel->slug)) {
                $channel->slug = static::generateUniqueSlug($channel->name);
            }
        });
    }

    public static function generateUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        $original = $slug;
        $counter = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $original.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VideoChannelItem::class)->orderBy('sort_order');
    }

    public function videos(): HasManyThrough
    {
        return $this->hasManyThrough(
            VideoRecording::class,
            VideoChannelItem::class,
            'video_channel_id',
            'id',
            'id',
            'video_recording_id'
        );
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('visibility', 'public');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
