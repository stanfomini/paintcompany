<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'name',
        'address',
        'city',
        'state',
        'zip',
        'type',
        'square_footage',
        'year_built',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'square_footage' => 'integer',
            'year_built' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the display name for the property.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name ?? $this->address;
    }

    /**
     * Get the full address of the property.
     */
    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address,
            $this->city,
            $this->state ? "{$this->state} {$this->zip}" : $this->zip,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get the customer that owns this property.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the photos for this property.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(PropertyPhoto::class)->orderBy('sort_order');
    }

    /**
     * Get the deals associated with this property.
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /**
     * Get photos by category.
     */
    public function photosByCategory(string $category): HasMany
    {
        return $this->photos()->where('category', $category);
    }

    /**
     * Scope to filter by property type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter residential properties.
     */
    public function scopeResidential($query)
    {
        return $query->where('type', 'residential');
    }

    /**
     * Scope to filter commercial properties.
     */
    public function scopeCommercial($query)
    {
        return $query->where('type', 'commercial');
    }

    /**
     * Scope to search properties by address, name, city, state, or zip.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('address', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")
                ->orWhere('state', 'like', "%{$search}%")
                ->orWhere('zip', 'like', "%{$search}%");
        });
    }
}
