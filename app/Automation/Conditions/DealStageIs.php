<?php

namespace App\Automation\Conditions;

use App\Automation\Contracts\ConditionInterface;
use App\Models\Deal;
use App\Models\WorkflowRun;

class DealStageIs implements ConditionInterface
{
    public static function getName(): string
    {
        return 'Deal Stage Is';
    }

    public static function getCategory(): string
    {
        return 'Deal Conditions';
    }

    public static function getDescription(): string
    {
        return 'Checks whether the deal is currently in one of the specified stages.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'stages' => [
                'type' => 'array',
                'label' => 'Stages',
                'description' => 'One or more stages to match against the current deal stage.',
                'required' => true,
                'items' => [
                    'type' => 'string',
                    'options' => Deal::STAGES,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function evaluate(WorkflowRun $run, array $config): bool
    {
        $stages = $config['stages'] ?? [];

        if (empty($stages)) {
            return false;
        }

        $deal = $this->resolveDeal($run);

        if ($deal === null) {
            return false;
        }

        return in_array($deal->stage, $stages, true);
    }

    protected function resolveDeal(WorkflowRun $run): ?Deal
    {
        if ($run->subject instanceof Deal) {
            return $run->subject;
        }

        $context = $run->context ?? [];

        if (isset($context['deal_id'])) {
            return Deal::find($context['deal_id']);
        }

        return null;
    }
}
