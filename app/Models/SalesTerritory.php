<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class SalesTerritory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'description', 'color', 'boundary', 'assigned_user_id',
        'status', 'level', 'parent_id', 'manager_id', 'center_lat', 'center_lng',
    ];

    protected function casts(): array
    {
        return [
            'boundary' => 'array',
            'center_lat' => 'decimal:7',
            'center_lng' => 'decimal:7',
        ];
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(TerritoryOffer::class, 'territory_id');
    }

    public function doorKnocks(): HasMany
    {
        return $this->hasMany(DoorKnock::class, 'territory_id');
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(TerritoryPreference::class, 'territory_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(SalesTerritory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(SalesTerritory::class, 'parent_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    public function scopeRegions($query)
    {
        return $query->where('level', 'region');
    }

    public function scopeZones($query)
    {
        return $query->where('level', 'zone');
    }

    public function scopeBlocks($query)
    {
        return $query->where('level', 'block');
    }

    public function scopeForLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Get all descendant IDs (children + grandchildren) for permission scoping.
     */
    public function getDescendantIds(): array
    {
        $ids = [];
        foreach ($this->children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $child->getDescendantIds());
        }

        return $ids;
    }

    /**
     * Get the full ancestor chain (parent, grandparent, etc).
     */
    public function getAncestors(): Collection
    {
        $ancestors = collect();
        $current = $this->parent;
        while ($current) {
            $ancestors->push($current);
            $current = $current->parent;
        }

        return $ancestors;
    }

    public function teamMembers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'territory_team_members', 'territory_id', 'user_id')
            ->withPivot('role', 'added_by')
            ->withTimestamps();
    }

    public function knockers(): BelongsToMany
    {
        return $this->teamMembers()->wherePivot('role', 'knocker');
    }

    public function estimators(): BelongsToMany
    {
        return $this->teamMembers()->wherePivot('role', 'estimator');
    }

    /**
     * Check if a point is inside this territory's polygon boundary using ray casting algorithm.
     */
    public function containsPoint(float $lat, float $lng): bool
    {
        $boundary = $this->boundary;

        if (! $boundary || ! isset($boundary['coordinates'])) {
            return false;
        }

        // GeoJSON polygon: coordinates[0] is the outer ring, each point is [lng, lat]
        $polygon = $boundary['coordinates'][0] ?? [];

        if (count($polygon) < 3) {
            return false;
        }

        $inside = false;
        $n = count($polygon);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $polygon[$i][1]; // lat
            $yi = $polygon[$i][0]; // lng
            $xj = $polygon[$j][1]; // lat
            $yj = $polygon[$j][0]; // lng

            if (($yi > $lng) !== ($yj > $lng)
                && ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
