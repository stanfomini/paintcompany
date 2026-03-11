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
        'intended_use', 'is_popular', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rgb_r' => 'integer',
            'rgb_g' => 'integer',
            'rgb_b' => 'integer',
            'intended_use' => 'string',
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
        $term = trim($term);
        if ($term === '') {
            return $query->whereRaw('1 = 0');
        }

        $brandId = null;

        // Map of brand identifiers → brand codes (longest first to match "sherwin williams" before "sw")
        $brandAliases = [
            'sherwin williams' => 'SW',
            'sherwin-williams' => 'SW',
            'benjamin moore' => 'BM',
            'behr' => 'BEHR',
            'ppg' => 'PPG',
            'sw' => 'SW',
            'bm' => 'BM',
        ];

        // Extract brand from beginning of search term
        $lowerTerm = strtolower($term);
        foreach ($brandAliases as $alias => $brandCode) {
            if (str_starts_with($lowerTerm, $alias.' ')) {
                $brandId = PaintBrand::where('code', $brandCode)->value('id');
                $term = trim(substr($term, strlen($alias)));
                break;
            }
        }

        // If brand extracted but no remaining term, return all colors for that brand
        if ($term === '' && $brandId) {
            return $query->where('paint_brand_id', $brandId);
        }

        // Normalize hyphens to spaces for code matching (SW-7069 → SW 7069)
        $normalizedTerm = str_replace('-', ' ', $term);

        // Grey/gray normalization
        $greyVariant = null;
        if (stripos($term, 'grey') !== false) {
            $greyVariant = str_ireplace('grey', 'gray', $term);
        } elseif (stripos($term, 'gray') !== false) {
            $greyVariant = str_ireplace('gray', 'grey', $term);
        }

        // Build search conditions
        $query->where(function (Builder $q) use ($term, $normalizedTerm, $greyVariant) {
            // Primary: original term
            $q->where(function ($q2) use ($term) {
                $q2->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%");
            });

            // Hyphen-normalized variant (SW-7069 matches SW 7069)
            if ($normalizedTerm !== $term) {
                $q->orWhere(function ($q2) use ($normalizedTerm) {
                    $q2->where('name', 'like', "%{$normalizedTerm}%")
                        ->orWhere('code', 'like', "%{$normalizedTerm}%");
                });
            }

            // Grey/gray variant
            if ($greyVariant) {
                $q->orWhere(function ($q2) use ($greyVariant) {
                    $q2->where('name', 'like', "%{$greyVariant}%")
                        ->orWhere('code', 'like', "%{$greyVariant}%");
                });
                $normalizedGreyVariant = str_replace('-', ' ', $greyVariant);
                if ($normalizedGreyVariant !== $greyVariant) {
                    $q->orWhere(function ($q2) use ($normalizedGreyVariant) {
                        $q2->where('name', 'like', "%{$normalizedGreyVariant}%")
                            ->orWhere('code', 'like', "%{$normalizedGreyVariant}%");
                    });
                }
            }

            // Multi-word fallback: match all words individually (AND logic)
            $words = preg_split('/\s+/', $term);
            if (count($words) > 1) {
                $q->orWhere(function ($q2) use ($words) {
                    foreach ($words as $word) {
                        $q2->where(function ($q3) use ($word) {
                            $q3->where('name', 'like', "%{$word}%")
                                ->orWhere('code', 'like', "%{$word}%");
                        });
                    }
                });
            }
        });

        // Apply brand filter outside search conditions
        if ($brandId) {
            $query->where('paint_brand_id', $brandId);
        }

        return $query;
    }

    public function scopeInFamily(Builder $query, string $family): Builder
    {
        return $query->where('color_family', $family);
    }

    public function scopeForIntendedUse(Builder $query, string $use): Builder
    {
        return $query->whereIn('intended_use', [$use, 'both']);
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
    public static function getCachedPopularColors(?int $brandId = null, ?string $intendedUse = null): Collection
    {
        $key = 'paint_colors:popular:'.($brandId ?? 'all').':'.($intendedUse ?? 'all');

        return Cache::remember($key, 86400, function () use ($brandId, $intendedUse) {
            $query = static::active()->popular()->with('brand')->orderBy('name');

            if ($brandId) {
                $query->where('paint_brand_id', $brandId);
            }

            if ($intendedUse) {
                $query->forIntendedUse($intendedUse);
            }

            return $query->limit(10)->get();
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
        $uses = ['all', 'interior', 'exterior'];

        Cache::forget('paint_colors:families');

        $brandIds = PaintBrand::pluck('id')->push('all');

        foreach ($brandIds as $brandId) {
            foreach ($uses as $use) {
                Cache::forget("paint_colors:popular:{$brandId}:{$use}");
            }
        }
    }
}
