<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoorKnock extends Model
{
    use HasFactory;

    public const STATUSES = [
        'not_home' => 'Not Home',
        'interested' => 'Interested',
        'not_interested' => 'Not Interested',
        'appointment_set' => 'Appointment Set',
        'sold' => 'Sold',
        'come_back' => 'Come Back',
    ];

    public const STATUS_COLORS = [
        'not_home' => '#9CA3AF',       // gray
        'interested' => '#22C55E',      // green
        'not_interested' => '#EF4444',  // red
        'appointment_set' => '#3B82F6', // blue
        'sold' => '#EAB308',            // gold
        'come_back' => '#A855F7',       // purple
    ];

    protected $fillable = [
        'user_id', 'territory_id', 'property_id', 'customer_id', 'deal_id',
        'address', 'city', 'state', 'zip', 'lat', 'lng', 'status',
        'customer_name', 'phone', 'email', 'notes', 'vehicle_interest', 'knocked_at',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'knocked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(SalesTerritory::class, 'territory_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * Scope to filter knocks within a map viewport bounding box.
     */
    public function scopeInBounds($query, float $neLat, float $neLng, float $swLat, float $swLng)
    {
        return $query->whereBetween('lat', [$swLat, $neLat])
            ->whereBetween('lng', [$swLng, $neLng]);
    }
}
