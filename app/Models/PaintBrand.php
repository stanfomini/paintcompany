<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaintBrand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'logo_path',
        'description',
        'website_url',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get all products for this brand.
     */
    public function products(): HasMany
    {
        return $this->hasMany(PaintProduct::class);
    }

    /**
     * Get active products for this brand.
     */
    public function activeProducts(): HasMany
    {
        return $this->hasMany(PaintProduct::class)->where('is_active', true);
    }

    /**
     * Scope to only active brands.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order by sort order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Get products grouped by sheen.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, PaintProduct>>
     */
    public function getProductsBySheenAttribute()
    {
        return $this->activeProducts->groupBy('sheen');
    }
}
