<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoadmapCategory extends Model
{
    /** @use HasFactory<\Database\Factories\RoadmapCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'roadmap_phase_id',
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

    public function phase(): BelongsTo
    {
        return $this->belongsTo(RoadmapPhase::class, 'roadmap_phase_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RoadmapItem::class)->orderBy('sort_order');
    }

    public function getProgressPercentAttribute(): float
    {
        $total = $this->items()->count();
        if ($total === 0) {
            return 0;
        }

        $done = $this->items()->where('status', RoadmapItem::STATUS_DONE)->count();

        return round(($done / $total) * 100, 1);
    }

    public function getCompletedCountAttribute(): int
    {
        return $this->items()->where('status', RoadmapItem::STATUS_DONE)->count();
    }

    public function getTotalCountAttribute(): int
    {
        return $this->items()->count();
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
