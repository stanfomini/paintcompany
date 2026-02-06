<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KpiCategory extends Model
{
    /** @use HasFactory<\Database\Factories\KpiCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(KpiMetric::class)->orderBy('sort_order');
    }

    public function activeMetrics(): HasMany
    {
        return $this->hasMany(KpiMetric::class)->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
