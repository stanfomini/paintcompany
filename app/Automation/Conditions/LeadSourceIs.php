<?php

namespace App\Automation\Conditions;

use App\Automation\Contracts\ConditionInterface;
use App\Models\Deal;
use App\Models\LeadSource;
use App\Models\WorkflowRun;

class LeadSourceIs implements ConditionInterface
{
    public static function getName(): string
    {
        return 'Lead Source Is';
    }

    public static function getCategory(): string
    {
        return 'Deal Conditions';
    }

    public static function getDescription(): string
    {
        return 'Checks whether the deal lead source matches one of the specified slugs.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'lead_source_slugs' => [
                'type' => 'string',
                'label' => 'Lead Source',
                'description' => 'The lead source to match against the deal.',
                'required' => true,
                'options' => LeadSource::where('is_active', true)->pluck('name', 'slug')->toArray(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function evaluate(WorkflowRun $run, array $config): bool
    {
        $slugsStr = $config['lead_source_slugs'] ?? '';
        $slugs = array_filter(array_map('trim', explode(',', $slugsStr)));

        if (empty($slugs)) {
            return false;
        }

        $deal = $this->resolveDeal($run);

        if (! $deal) {
            return false;
        }

        return in_array($deal->lead_source, $slugs, true);
    }

    protected function resolveDeal(WorkflowRun $run): ?Deal
    {
        $subject = $run->subject;

        if ($subject instanceof Deal) {
            return $subject;
        }

        $dealId = $run->context['deal_id'] ?? null;

        if ($dealId) {
            return Deal::find($dealId);
        }

        return null;
    }
}
