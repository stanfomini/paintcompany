<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Corporation extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_setting_id',
        'name',
        'entity_type',
        'state_of_formation',
        'ein',
        'formation_date',
        'registered_agent',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'zip',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'formation_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public const ENTITY_TYPES = [
        'LLC' => 'LLC',
        'S-Corp' => 'S-Corp',
        'C-Corp' => 'C-Corp',
        'Sole Prop' => 'Sole Proprietorship',
        'Partnership' => 'Partnership',
    ];

    public function companySetting(): BelongsTo
    {
        return $this->belongsTo(CompanySetting::class);
    }

    public function owners(): HasMany
    {
        return $this->hasMany(CorporationOwner::class)->orderBy('sort_order');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CorporateDocument::class);
    }

    public function meetingMinutes(): HasMany
    {
        return $this->hasMany(MeetingMinutes::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
