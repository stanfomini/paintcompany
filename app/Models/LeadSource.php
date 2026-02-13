<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LeadSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'icon',
        'color',
        'is_active',
        'is_system',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LeadSource $source) {
            if (empty($source->slug)) {
                $source->slug = Str::slug($source->name, '_');
            }
        });

        static::saved(function () {
            static::clearCache();
        });

        static::deleted(function () {
            static::clearCache();
        });
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function webhookEndpoint(): HasOne
    {
        return $this->hasOne(WebhookEndpoint::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Check if this source can be deleted (not system, no references).
     */
    public function isDeletable(): bool
    {
        if ($this->is_system) {
            return false;
        }

        return $this->customers()->count() === 0
            && $this->deals()->count() === 0;
    }

    /**
     * Get reference count for this source.
     */
    public function getReferenceCount(): int
    {
        return $this->customers()->count() + $this->deals()->count();
    }

    /**
     * Get all active lead sources as slug => name for dropdowns.
     */
    public static function getOptions(): array
    {
        return Cache::remember('lead_source_options', 3600, function () {
            return static::query()
                ->active()
                ->ordered()
                ->pluck('name', 'slug')
                ->toArray();
        });
    }

    /**
     * Find a lead source by its slug.
     */
    public static function findBySlug(string $slug): ?static
    {
        return static::where('slug', $slug)->first();
    }

    /**
     * Clear the cached options.
     */
    public static function clearCache(): void
    {
        Cache::forget('lead_source_options');
    }
}
