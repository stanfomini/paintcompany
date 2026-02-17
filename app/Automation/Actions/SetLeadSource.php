<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\Deal;
use App\Models\LeadSource;
use App\Models\WorkflowRun;

class SetLeadSource implements ActionInterface
{
    public static function getName(): string
    {
        return 'Set Lead Source';
    }

    public static function getCategory(): string
    {
        return 'Record Management';
    }

    public static function getDescription(): string
    {
        return 'Sets the lead source on a deal by slug. Finds or creates the lead source.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'lead_source_slug' => [
                'type' => 'string',
                'label' => 'Lead Source Slug',
                'description' => 'The slug of the lead source (e.g., facebook, google_ads).',
                'required' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function execute(WorkflowRun $run, array $config): array
    {
        $registry = app(Registry::class);
        $slug = $registry->resolveDynamicValues($config['lead_source_slug'] ?? '', $run);

        if (empty($slug)) {
            return ['lead_source_set' => false, 'error' => 'No slug provided'];
        }

        $leadSource = LeadSource::findBySlug($slug);

        $deal = $this->resolveDeal($run);

        if (! $deal) {
            return ['lead_source_set' => false, 'error' => 'No deal found'];
        }

        $updateData = ['lead_source' => $slug];

        if ($leadSource) {
            $updateData['lead_source_id'] = $leadSource->id;
        }

        $deal->update($updateData);

        return ['lead_source_set' => true, 'slug' => $slug];
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
