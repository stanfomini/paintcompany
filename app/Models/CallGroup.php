<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CallGroup extends Model
{
    /** @use HasFactory<\Database\Factories\CallGroupFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'ring_timeout_seconds',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'ring_timeout_seconds' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(CallGroupMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'call_group_members')
            ->withPivot('forwarding_number', 'is_active', 'sort_order')
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get forwarding numbers for all active members.
     *
     * @return array<string>
     */
    public function getActiveForwardingNumbers(): array
    {
        return $this->members()
            ->where('is_active', true)
            ->with('user')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (CallGroupMember $member) => $member->getForwardingNumber())
            ->filter()
            ->values()
            ->toArray();
    }
}
