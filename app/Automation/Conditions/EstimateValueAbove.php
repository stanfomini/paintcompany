<?php

namespace App\Automation\Conditions;

use App\Automation\Contracts\ConditionInterface;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\WorkflowRun;

class EstimateValueAbove implements ConditionInterface
{
    public static function getName(): string
    {
        return 'Estimate Value Above';
    }

    public static function getCategory(): string
    {
        return 'Estimate Conditions';
    }

    public static function getDescription(): string
    {
        return 'Checks whether the estimate total exceeds a specified dollar threshold.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'threshold' => [
                'type' => 'float',
                'label' => 'Threshold ($)',
                'description' => 'The dollar amount the estimate total must exceed.',
                'required' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function evaluate(WorkflowRun $run, array $config): bool
    {
        $threshold = $config['threshold'] ?? null;

        if ($threshold === null) {
            return false;
        }

        $estimate = $this->resolveEstimate($run);

        if ($estimate === null) {
            return false;
        }

        return (float) $estimate->total > (float) $threshold;
    }

    protected function resolveEstimate(WorkflowRun $run): ?Estimate
    {
        if ($run->subject instanceof Estimate) {
            return $run->subject;
        }

        if ($run->subject instanceof Deal) {
            return $run->subject->estimates()->latest()->first();
        }

        $context = $run->context ?? [];

        if (isset($context['estimate_id'])) {
            return Estimate::find($context['estimate_id']);
        }

        return null;
    }
}
