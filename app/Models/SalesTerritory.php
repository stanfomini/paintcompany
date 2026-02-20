<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesTerritory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'description', 'color', 'boundary', 'assigned_user_id',
        'status', 'center_lat', 'center_lng',
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
