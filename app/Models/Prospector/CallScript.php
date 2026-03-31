<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallScript extends Model
{
    use HasFactory;

    protected $table = 'prospector_call_scripts';

    protected $fillable = [
        'company_id',
        'campaign_id',
        'name',
        'body',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeForCampaign(Builder $query, int $campaignId): void
    {
        $query->where('campaign_id', $campaignId);
    }
}
