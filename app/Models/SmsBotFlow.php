<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsBotFlow extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'status',
        'trigger_type',
        'trigger_keyword',
        'lead_source_ids',
        'completion_action',
        'timeout_minutes',
        'business_hours_only',
        'delay_after_lead_minutes',
    ];

    protected function casts(): array
    {
        return [
            'lead_source_ids' => 'array',
            'business_hours_only' => 'boolean',
            'timeout_minutes' => 'integer',
            'delay_after_lead_minutes' => 'integer',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(SmsBotStep::class, 'flow_id')->orderBy('sort_order');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(SmsConversation::class, 'flow_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where('status', 'disabled');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Find an active flow matching the given trigger type and optional lead source.
     */
    public static function findForTrigger(string $triggerType, ?int $leadSourceId = null): ?self
    {
        return static::query()
            ->active()
            ->where('trigger_type', $triggerType)
            ->get()
            ->first(function (self $flow) use ($leadSourceId) {
                if (empty($flow->lead_source_ids)) {
                    return true;
                }

                return $leadSourceId && in_array($leadSourceId, $flow->lead_source_ids);
            });
    }

    /**
     * Find an active keyword-triggered flow by keyword.
     */
    public static function findByKeyword(string $keyword): ?self
    {
        return static::query()
            ->active()
            ->where('trigger_type', 'keyword')
            ->where('trigger_keyword', strtoupper(trim($keyword)))
            ->first();
    }
}
