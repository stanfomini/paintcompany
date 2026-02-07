<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use HasFactory, SoftDeletes;

    public const LISTING_STATUSES = [
        'active' => 'Active',
        'pending' => 'Pending',
        'sold' => 'Sold',
        'inactive' => 'Inactive',
    ];

    public const SOURCES = [
        'direct' => 'Direct',
        'agent_referral' => 'Agent Referral',
        'open_house' => 'Open House',
        'manual' => 'Manual',
    ];

    protected $fillable = [
        'customer_id',
        'agent_id',
        'name',
        'address',
        'city',
        'state',
        'zip',
        'type',
        'square_footage',
        'year_built',
        'notes',
        'source',
        'listing_status',
        'listing_price',
        'description',
        'open_house_date',
    ];

    protected function casts(): array
    {
        return [
            'square_footage' => 'integer',
            'year_built' => 'integer',
            'listing_price' => 'decimal:2',
            'open_house_date' => 'datetime',
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
     * Get the agent who manages this property listing.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Get the customers referred through this property (when used as a listing).
     */
    public function referredCustomers(): HasMany
    {
        return $this->hasMany(Customer::class, 'property_id');
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
     * Get the estimates for this property through deals.
     */
    public function estimates(): HasManyThrough
    {
        return $this->hasManyThrough(Estimate::class, Deal::class);
    }

    /**
     * Get photos by category.
     */
    public function photosByCategory(string $category): HasMany
    {
        return $this->photos()->where('category', $category);
    }

    /**
     * Check if this property is an agent listing.
     */
    public function isListing(): bool
    {
        return $this->agent_id !== null;
    }

    /**
     * Check if this property has an upcoming open house.
     */
    public function hasUpcomingOpenHouse(): bool
    {
        return $this->open_house_date && $this->open_house_date->isFuture();
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
     * Scope to filter properties by agent.
     */
    public function scopeForAgent($query, User $agent)
    {
        return $query->where('agent_id', $agent->id);
    }

    /**
     * Scope to filter active listings.
     */
    public function scopeActiveListing($query)
    {
        return $query->where('listing_status', 'active');
    }

    /**
     * Scope to filter by listing status.
     */
    public function scopeOfListingStatus($query, string $status)
    {
        return $query->where('listing_status', $status);
    }

    /**
     * Scope to filter by source.
     */
    public function scopeOfSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Scope to filter properties with upcoming open houses.
     */
    public function scopeWithUpcomingOpenHouse($query)
    {
        return $query->whereNotNull('open_house_date')
            ->where('open_house_date', '>', now());
    }

    /**
     * Scope to search properties by address, name, city, state, zip, description, or customer name.
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
                ->orWhere('zip', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhereHas('customer', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                });
        });
    }
}
