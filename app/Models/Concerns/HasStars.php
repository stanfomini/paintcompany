<?php

namespace App\Models\Concerns;

use App\Models\MediaStar;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasStars
{
    public function stars(): MorphMany
    {
        return $this->morphMany(MediaStar::class, 'starrable');
    }

    public function isStarredBy(User $user): bool
    {
        return $this->stars()->where('user_id', $user->id)->exists();
    }

    public function toggleStar(User $user): bool
    {
        $existing = $this->stars()->where('user_id', $user->id)->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        $this->stars()->create(['user_id' => $user->id]);

        return true;
    }

    public function scopeStarredBy(Builder $query, User $user): Builder
    {
        return $query->whereHas('stars', fn (Builder $q) => $q->where('user_id', $user->id));
    }
}
