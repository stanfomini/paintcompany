<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgentListing extends Model
{
    /** @use HasFactory<\Database\Factories\AgentListingFactory> */
    use HasFactory, SoftDeletes;

    public const STATUSES = [
        'active' => 'Active',
        'pending' => 'Pending',
        'sold' => 'Sold',
        'inactive' => 'Inactive',
    ];

    protected $fillable = [
        'agent_id',
        'address',
        'city',
        'state',
        'zip',
        'status',
        'listing_price',
        'description',
        'notes',
        'open_house_date',
    ];

    protected function casts(): array
    {
        return [
            'listing_price' => 'decimal:2',
            'open_house_date' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the full address of the listing.
     */
    public function getFullAddressAttribute(): string
    {
        return "{$this->address}, {$this->city}, {$this->state} {$this->zip}";
    }

    /**
     * Get the agent who owns this listing.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Get the customers referred through this listing.
     */
    public function referredCustomers(): HasMany
    {
        return $this->hasMany(Customer::class, 'listing_id');
    }

    /**
     * Get the photos for this listing.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ListingPhoto::class, 'listing_id')->orderBy('sort_order');
    }

    /**
     * Get photos by category.
     */
    public function photosByCategory(string $category): HasMany
    {
        return $this->photos()->where('category', $category);
    }

    /**
     * Get the primary photo (first general or first photo).
     */
    public function primaryPhoto(): HasOne
    {
        return $this->hasOne(ListingPhoto::class, 'listing_id')
            ->orderByRaw("CASE WHEN category = 'general' THEN 0 ELSE 1 END")
            ->orderBy('sort_order');
    }

    /**
     * Check if listing is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if listing has upcoming open house.
     */
    public function hasUpcomingOpenHouse(): bool
    {
        return $this->open_house_date && $this->open_house_date->isFuture();
    }

    /**
     * Scope to filter by agent.
     */
    public function scopeForAgent($query, User $agent)
    {
        return $query->where('agent_id', $agent->id);
    }

    /**
     * Scope to filter active listings.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to filter by status.
     */
    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter listings with upcoming open houses.
     */
    public function scopeWithUpcomingOpenHouse($query)
    {
        return $query->whereNotNull('open_house_date')
            ->where('open_house_date', '>', now());
    }

    /**
     * Scope to search listings.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('address', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")
                ->orWhere('state', 'like', "%{$search}%")
                ->orWhere('zip', 'like', "%{$search}%");
        });
    }
}
