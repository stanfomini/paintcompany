<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class RoadmapPhase extends Model
{
    /** @use HasFactory<\Database\Factories\RoadmapPhaseFactory> */
    use HasFactory;

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_NOT_STARTED => 'Not Started',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_COMPLETED => 'Completed',
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'sort_order',
        'is_active',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(RoadmapCategory::class)->orderBy('sort_order');
    }

    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(RoadmapItem::class, RoadmapCategory::class);
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

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public function updateStatusFromItems(): void
    {
        $total = $this->items()->count();
        if ($total === 0) {
            $this->status = self::STATUS_NOT_STARTED;
            $this->save();

            return;
        }

        $done = $this->items()->where('status', RoadmapItem::STATUS_DONE)->count();
        $inProgress = $this->items()->where('status', RoadmapItem::STATUS_IN_PROGRESS)->count();

        if ($done === $total) {
            $this->status = self::STATUS_COMPLETED;
        } elseif ($done > 0 || $inProgress > 0) {
            $this->status = self::STATUS_IN_PROGRESS;
        } else {
            $this->status = self::STATUS_NOT_STARTED;
        }

        $this->save();
    }
}
