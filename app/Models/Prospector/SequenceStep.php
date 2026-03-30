<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SequenceStep extends Model
{
    use HasFactory;

    protected $table = 'prospector_sequence_steps';

    protected $fillable = [
        'sequence_id',
        'sort_order',
        'type',
        'delay_value',
        'delay_unit',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'delay_value' => 'integer',
            'config' => 'array',
        ];
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class, 'sequence_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SequenceStepLog::class, 'step_id');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order');
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Check if this step is a wait/delay step.
     */
    public function isWait(): bool
    {
        return $this->type === 'wait';
    }

    /**
     * Check if this step is an SMS step.
     */
    public function isSms(): bool
    {
        return $this->type === 'sms';
    }

    /**
     * Check if this step is an email step.
     */
    public function isEmail(): bool
    {
        return $this->type === 'email';
    }

    /**
     * Check if this step is an RVM step.
     */
    public function isRvm(): bool
    {
        return $this->type === 'rvm';
    }

    /**
     * Check if this step is a call step.
     */
    public function isCall(): bool
    {
        return $this->type === 'call';
    }

    /**
     * Check if this step is a condition step.
     */
    public function isCondition(): bool
    {
        return $this->type === 'condition';
    }

    /**
     * Get the delay in minutes for this step.
     */
    public function getDelayInMinutes(): int
    {
        if ($this->delay_value === null || $this->delay_unit === null) {
            return 0;
        }

        return match ($this->delay_unit) {
            'minutes' => $this->delay_value,
            'hours' => $this->delay_value * 60,
            'days' => $this->delay_value * 1440,
            default => 0,
        };
    }
}
