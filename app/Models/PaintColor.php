<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PaintColor extends Model
{
    use HasFactory;

    protected $fillable = [
        'paint_brand_id', 'name', 'code', 'hex_value',
        'rgb_r', 'rgb_g', 'rgb_b', 'color_family',
        'is_popular', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rgb_r' => 'integer',
            'rgb_g' => 'integer',
            'rgb_b' => 'integer',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(PaintBrand::class, 'paint_brand_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePopular(Builder $query): Builder
    {
        return $query->where('is_popular', true);
    }

    public function scopeForBrand(Builder $query, int $brandId): Builder
    {
        return $query->where('paint_brand_id', $brandId);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%");
        });
    }

    public function scopeInFamily(Builder $query, string $family): Builder
    {
        return $query->where('color_family', $family);
    }

    public function getFullNameAttribute(): string
    {
        return $this->brand ? "{$this->brand->name} {$this->name}" : $this->name;
    }

    public function getDisplayNameAttribute(): string
    {
        return "{$this->name} ({$this->code})";
    }

    /**
     * Get popular colors, cached for 24 hours.
     */
    public static function getCachedPopularColors(?int $brandId = null): Collection
    {
        $key = $brandId ? "paint_colors:popular:{$brandId}" : 'paint_colors:popular:all';

        return Cache::remember($key, 86400, function () use ($brandId) {
            $query = static::active()->popular()->with('brand')->orderBy('name');

            if ($brandId) {
                $query->where('paint_brand_id', $brandId);
            }

            return $query->get();
        });
    }

    /**
     * Get distinct color families, cached for 24 hours.
     */
    public static function getCachedColorFamilies(): Collection
    {
        return Cache::remember('paint_colors:families', 86400, function () {
            return static::active()
                ->distinct()
                ->orderBy('color_family')
                ->pluck('color_family')
                ->filter();
        });
    }

    /**
     * Clear all paint color caches (call after seeding).
     */
    public static function clearCache(): void
    {
        Cache::forget('paint_colors:popular:all');
        Cache::forget('paint_colors:families');

        PaintBrand::pluck('id')->each(function (int $id): void {
            Cache::forget("paint_colors:popular:{$id}");
        });
    }
}
