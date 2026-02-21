<?php

namespace App\Traits;

use App\Models\CommissionRule;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasCommissionRules
{
    public function commissionRulesRelation(): MorphMany
    {
        return $this->morphMany(CommissionRule::class, 'commissionable');
    }

    /**
     * Get commission rules from the commission_rules table for a given source.
     *
     * @return array<int, array{source: string, type: string, rate: float, amount: float, conditions: array|null, active: bool, auto_trigger: bool}>
     */
    public function getTableCommissionRulesForSource(string $source, bool $autoOnly = false): array
    {
        $query = $this->commissionRulesRelation()
            ->where('source', $source)
            ->where('is_active', true)
            ->orderBy('sort_order');

        if ($autoOnly) {
            $query->where('auto_trigger', true);
        }

        return $query->get()->map(fn (CommissionRule $rule) => [
            'source' => $rule->source,
            'type' => $rule->commission_type,
            'rate' => (float) $rule->rate,
            'amount' => (float) $rule->amount,
            'conditions' => $rule->conditions,
            'active' => $rule->is_active,
            'auto_trigger' => $rule->auto_trigger,
        ])->all();
    }
}
