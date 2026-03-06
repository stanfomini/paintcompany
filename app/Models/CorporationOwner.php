<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporationOwner extends Model
{
    use HasFactory;

    protected $fillable = [
        'corporation_id',
        'name',
        'percentage',
        'role',
        'email',
        'phone',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:2',
        ];
    }

    public const ROLES = [
        'Member' => 'Member',
        'Managing Member' => 'Managing Member',
        'President' => 'President',
        'VP' => 'Vice President',
        'Secretary' => 'Secretary',
        'Treasurer' => 'Treasurer',
        'Director' => 'Director',
    ];

    public function corporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }
}
