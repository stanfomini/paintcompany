<?php

namespace App\Models;

use App\Services\AllocationEvaluationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a business cost allocation (marketing, sales, loans, etc.).
 *
 * Allocations are deducted from gross profit to calculate net profit.
 * Three calculation types are supported:
 * - percent_of_revenue: A percentage of the customer price
 * - fixed_per_job: A fixed dollar amount per job
 * - per_labor_hour: A dollar amount per labor hour worked
 */
class CompanyAllocation extends Model
{
    /** @use HasFactory<\Database\Factories\CompanyAllocationFactory> */
    use HasFactory;

    public const TYPE_PERCENT_OF_REVENUE = 'percent_of_revenue';

    public const TYPE_FIXED_PER_JOB = 'fixed_per_job';

    public const TYPE_PER_LABOR_HOUR = 'per_labor_hour';

    protected $fillable = [
        'company_setting_id',
        'name',
        'slug',
        'type',
        'value',
        'is_active',
        'is_system',
        'is_informational',
        'description',
        'sort_order',
        'conditions',
        'conditions_logic',
        'value_tiers',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'is_informational' => 'boolean',
            'sort_order' => 'integer',
            'conditions' => 'array',
            'conditions_logic' => 'string',
            'value_tiers' => 'array',
        ];
    }

    /**
     * Get the company settings this allocation belongs to.
     */
    public function companySetting(): BelongsTo
    {
        return $this->belongsTo(CompanySetting::class);
    }

    /**
     * Get the job cost card allocations for this allocation.
     */
    public function cardAllocations(): HasMany
    {
        return $this->hasMany(JobCostCardAllocation::class);
    }

    /**
     * Check if this allocation has conditions.
     */
    public function hasConditions(): bool
    {
        return is_array($this->conditions) && count($this->conditions) > 0;
    }

    /**
     * Evaluate conditions against a given context.
     *
     * @param  array<string, mixed>  $context  Context data to evaluate against
     */
    public function evaluateConditions(array $context): bool
    {
        if (! $this->hasConditions()) {
            return true;
        }

        $logic = $this->conditions_logic ?? 'all';
        $results = [];

        foreach ($this->conditions as $condition) {
            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? 'equals';
            $conditionValue = $condition['value'] ?? null;

            if ($field === null) {
                continue;
            }

            $contextValue = $context[$field] ?? null;
            $passed = $this->evaluateSingleCondition($field, $operator, $conditionValue, $contextValue);
            $results[] = $passed;
        }

        if (empty($results)) {
            return true;
        }

        if ($logic === 'any') {
            return in_array(true, $results, true);
        }

        return ! in_array(false, $results, true);
    }

    /**
     * Boolean context fields that should use boolean comparison.
     */
    protected static array $booleanFields = [
        'has_agent_referral',
        'is_repeat_customer',
        'uses_premium_paint',
        'has_spray_application',
        'has_complex_substrate',
        'requires_prime',
        'has_subcontractor',
        'has_nested_areas',
        'has_options_selected',
    ];

    /**
     * Array context fields that should use array-aware comparison.
     */
    protected static array $arrayFields = [
        'has_scope',
        'payment_methods',
        'substrate_categories',
    ];

    /**
     * String context fields that should use strict string comparison.
     */
    protected static array $stringFields = [
        'lead_source',
        'customer_source',
        'season',
        'scope_type',
        'estimator_mode',
    ];

    /**
     * Evaluate a single condition rule.
     */
    protected function evaluateSingleCondition(string $field, string $operator, mixed $conditionValue, mixed $contextValue): bool
    {
        // Special handler for scope conditions
        if ($field === 'has_scope') {
            return $this->evaluateScopeCondition($operator, $conditionValue, (array) $contextValue);
        }

        // Array fields: payment_methods, substrate_categories
        if (in_array($field, ['payment_methods', 'substrate_categories'])) {
            return $this->evaluateArrayFieldCondition($operator, $conditionValue, (array) $contextValue);
        }

        // Boolean fields
        if (in_array($field, static::$booleanFields)) {
            return $this->evaluateBooleanCondition($operator, $conditionValue, $contextValue);
        }

        // String fields: use strict string comparison
        if (in_array($field, static::$stringFields)) {
            return match ($operator) {
                'equals' => (string) $contextValue === (string) $conditionValue,
                'not_equals' => (string) $contextValue !== (string) $conditionValue,
                'in' => in_array((string) $contextValue, (array) $conditionValue),
                default => false,
            };
        }

        return match ($operator) {
            'equals' => $contextValue == $conditionValue,
            'not_equals' => $contextValue != $conditionValue,
            'in' => in_array($conditionValue, (array) $contextValue) || in_array($contextValue, (array) $conditionValue),
            'greater_than' => (float) $contextValue > (float) $conditionValue,
            'less_than' => (float) $contextValue < (float) $conditionValue,
            default => false,
        };
    }

    /**
     * Evaluate a boolean condition (true/false matching).
     */
    protected function evaluateBooleanCondition(string $operator, mixed $conditionValue, mixed $contextValue): bool
    {
        $contextBool = filter_var($contextValue, FILTER_VALIDATE_BOOLEAN);
        $conditionBool = filter_var($conditionValue, FILTER_VALIDATE_BOOLEAN);

        return match ($operator) {
            'equals' => $contextBool === $conditionBool,
            'not_equals' => $contextBool !== $conditionBool,
            default => false,
        };
    }

    /**
     * Evaluate an array field condition (payment_methods, substrate_categories).
     */
    protected function evaluateArrayFieldCondition(string $operator, mixed $conditionValue, array $contextValues): bool
    {
        if ($operator === 'equals') {
            return in_array($conditionValue, $contextValues);
        }

        if ($operator === 'in') {
            foreach ((array) $conditionValue as $value) {
                if (in_array($value, $contextValues)) {
                    return true;
                }
            }

            return false;
        }

        if ($operator === 'not_equals') {
            return ! in_array($conditionValue, $contextValues);
        }

        return false;
    }

    /**
     * Evaluate a scope-specific condition.
     *
     * @param  array<int, string>  $contextScopes
     */
    protected function evaluateScopeCondition(string $operator, mixed $conditionValue, array $contextScopes): bool
    {
        if ($operator === 'equals') {
            return match ($conditionValue) {
                'interior' => in_array('interior', $contextScopes),
                'exterior' => in_array('exterior', $contextScopes),
                'both' => in_array('interior', $contextScopes) && in_array('exterior', $contextScopes),
                default => false,
            };
        }

        if ($operator === 'not_equals') {
            return match ($conditionValue) {
                'interior' => ! in_array('interior', $contextScopes),
                'exterior' => ! in_array('exterior', $contextScopes),
                'both' => ! (in_array('interior', $contextScopes) && in_array('exterior', $contextScopes)),
                default => true,
            };
        }

        return false;
    }

    /**
     * Get a human-readable summary of the conditions.
     */
    public function getConditionsSummary(): string
    {
        if (! $this->hasConditions()) {
            return '';
        }

        $logic = $this->conditions_logic ?? 'all';
        $parts = [];

        foreach ($this->conditions as $condition) {
            $field = $condition['field'] ?? 'unknown';
            $operator = $condition['operator'] ?? 'equals';
            $value = $condition['value'] ?? '';

            $fieldLabel = str_replace('_', ' ', $field);
            $operatorLabel = match ($operator) {
                'equals' => 'is',
                'not_equals' => 'is not',
                'in' => 'includes',
                'greater_than' => '>',
                'less_than' => '<',
                default => $operator,
            };

            $valueLabel = is_array($value) ? implode(', ', $value) : (string) $value;
            $parts[] = "{$fieldLabel} {$operatorLabel} {$valueLabel}";
        }

        $joiner = $logic === 'any' ? ' OR ' : ' AND ';

        return 'Applies when: '.implode($joiner, $parts);
    }

    /**
     * Check if this allocation has value tiers.
     */
    public function hasTiers(): bool
    {
        return is_array($this->value_tiers) && count($this->value_tiers) > 0;
    }

    /**
     * Get a human-readable summary of the value tiers.
     */
    public function getTiersSummary(): string
    {
        if (! $this->hasTiers()) {
            return '';
        }

        $parts = [];
        foreach ($this->value_tiers as $tier) {
            $label = $tier['label'] ?? '?';
            $formatted = match ($tier['type'] ?? '') {
                self::TYPE_PERCENT_OF_REVENUE => number_format((float) $tier['value'], 2).'%',
                self::TYPE_FIXED_PER_JOB => '$'.number_format((float) $tier['value'], 2),
                self::TYPE_PER_LABOR_HOUR => '$'.number_format((float) $tier['value'], 2).'/hr',
                default => (string) $tier['value'],
            };
            $parts[] = "{$label} → {$formatted}";
        }

        return implode(' | ', $parts);
    }

    /**
     * Resolve the first matching tier against a given context.
     *
     * Returns the matching tier's {label, type, value} or null if no tier matches.
     *
     * @param  array<string, mixed>  $context
     * @return array{label: string, type: string, value: float}|null
     */
    public function resolveTier(array $context): ?array
    {
        if (! $this->hasTiers()) {
            return null;
        }

        foreach ($this->value_tiers as $tier) {
            $tierConditions = $tier['conditions'] ?? [];

            if (empty($tierConditions)) {
                return [
                    'label' => $tier['label'] ?? '',
                    'type' => $tier['type'],
                    'value' => (float) $tier['value'],
                ];
            }

            $allPassed = true;
            foreach ($tierConditions as $condition) {
                $field = $condition['field'] ?? null;
                $operator = $condition['operator'] ?? 'equals';
                $conditionValue = $condition['value'] ?? null;

                if ($field === null) {
                    continue;
                }

                $contextValue = $context[$field] ?? null;

                if (! $this->evaluateSingleCondition($field, $operator, $conditionValue, $contextValue)) {
                    $allPassed = false;
                    break;
                }
            }

            if ($allPassed) {
                return [
                    'label' => $tier['label'] ?? '',
                    'type' => $tier['type'],
                    'value' => (float) $tier['value'],
                ];
            }
        }

        return null;
    }

    /**
     * Resolve the best tier (or fallback to base) and calculate the allocation amount.
     *
     * @param  array<string, mixed>  $context
     * @return array{type: string, value: float, amount: float, tier_label: string|null}
     */
    public function resolveAndCalculate(array $context, float $customerPrice, float $laborHours = 0): array
    {
        $tier = $this->resolveTier($context);

        if ($tier) {
            $amount = $this->calculateAmountForTypeAndValue($tier['type'], $tier['value'], $customerPrice, $laborHours);

            return [
                'type' => $tier['type'],
                'value' => $tier['value'],
                'amount' => $amount,
                'tier_label' => $tier['label'],
            ];
        }

        // Fallback to base type + value
        $amount = $this->calculateAmount($customerPrice, $laborHours);

        return [
            'type' => $this->type,
            'value' => (float) $this->value,
            'amount' => $amount,
            'tier_label' => null,
        ];
    }

    /**
     * Calculate amount for a given type and value (used by tier resolution).
     */
    protected function calculateAmountForTypeAndValue(string $type, float $value, float $customerPrice, float $laborHours): float
    {
        if (! $this->is_active) {
            return 0.0;
        }

        return match ($type) {
            self::TYPE_PERCENT_OF_REVENUE => round($customerPrice * ($value / 100), 2),
            self::TYPE_FIXED_PER_JOB => round($value, 2),
            self::TYPE_PER_LABOR_HOUR => round($laborHours * $value, 2),
            default => 0.0,
        };
    }

    /**
     * Scope to active allocations only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to allocations that reduce profit (not informational).
     */
    public function scopeReducesProfit(Builder $query): Builder
    {
        return $query->where('is_informational', false);
    }

    /**
     * Scope to system allocations only.
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope to custom (non-system) allocations.
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /**
     * Scope to order by sort order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Calculate the allocation amount for a given job.
     *
     * @param  float  $customerPrice  Total customer price (revenue)
     * @param  float  $laborHours  Total labor hours worked
     */
    public function calculateAmount(float $customerPrice, float $laborHours = 0): float
    {
        if (! $this->is_active) {
            return 0.0;
        }

        return match ($this->type) {
            self::TYPE_PERCENT_OF_REVENUE => round($customerPrice * ((float) $this->value / 100), 2),
            self::TYPE_FIXED_PER_JOB => round((float) $this->value, 2),
            self::TYPE_PER_LABOR_HOUR => round($laborHours * (float) $this->value, 2),
            default => 0.0,
        };
    }

    /**
     * Get the type label for display.
     */
    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_PERCENT_OF_REVENUE => 'Percent of Revenue',
            self::TYPE_FIXED_PER_JOB => 'Fixed per Job',
            self::TYPE_PER_LABOR_HOUR => 'Per Labor Hour',
            default => 'Unknown',
        };
    }

    /**
     * Get the formatted value for display.
     */
    public function getFormattedValueAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_PERCENT_OF_REVENUE => number_format((float) $this->value, 2).'%',
            self::TYPE_FIXED_PER_JOB => '$'.number_format((float) $this->value, 2),
            self::TYPE_PER_LABOR_HOUR => '$'.number_format((float) $this->value, 2).'/hr',
            default => (string) $this->value,
        };
    }

    /**
     * Check if this allocation can be deleted.
     */
    public function canBeDeleted(): bool
    {
        return ! $this->is_system;
    }

    /**
     * Get available allocation types.
     *
     * @return array<string, string>
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_PERCENT_OF_REVENUE => 'Percent of Revenue',
            self::TYPE_FIXED_PER_JOB => 'Fixed per Job',
            self::TYPE_PER_LABOR_HOUR => 'Per Labor Hour',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::deleting(function (CompanyAllocation $allocation) {
            if ($allocation->is_system) {
                throw new \RuntimeException('System allocations cannot be deleted.');
            }
        });

        static::saved(function (CompanyAllocation $allocation) {
            try {
                app(AllocationEvaluationService::class)->recalculateAllCards();
            } catch (\Throwable $e) {
                report($e);
            }
        });

        static::deleted(function (CompanyAllocation $allocation) {
            try {
                $allocation->cardAllocations()->delete();
                app(AllocationEvaluationService::class)->recalculateAllCards();
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
