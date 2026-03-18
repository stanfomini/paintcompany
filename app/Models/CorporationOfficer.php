<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporationOfficer extends Model
{
    use HasFactory;

    protected $fillable = [
        'corporation_id',
        'name',
        'title',
        'sort_order',
    ];

    public const TITLES = [
        'President' => 'President',
        'Vice President' => 'Vice President',
        'Secretary' => 'Secretary',
        'Treasurer' => 'Treasurer',
        'CEO' => 'Chief Executive Officer',
        'CFO' => 'Chief Financial Officer',
        'COO' => 'Chief Operating Officer',
        'Director' => 'Director',
        'Managing Member' => 'Managing Member',
        'General Counsel' => 'General Counsel',
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
