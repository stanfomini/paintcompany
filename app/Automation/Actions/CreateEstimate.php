<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\WorkflowRun;

class CreateEstimate implements ActionInterface
{
    public static function getName(): string
    {
        return 'Create Estimate';
    }

    public static function getCategory(): string
    {
        return 'Record Management';
    }

    public static function getDescription(): string
    {
        return 'Creates a blank draft estimate linked to the current deal. Supports dynamic values in the title.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'title' => [
                'type' => 'string',
                'label' => 'Estimate Title',
                'description' => 'The estimate title. Supports {{dynamic.values}} placeholders.',
                'required' => false,
                'default' => 'New Estimate',
            ],
            'project_type' => [
                'type' => 'string',
                'label' => 'Project Type',
                'description' => 'The type of painting project.',
                'required' => false,
                'default' => 'interior',
                'options' => [
                    'interior' => 'Interior',
                    'exterior' => 'Exterior',
                    'both' => 'Both',
                ],
            ],
            'estimator_mode' => [
                'type' => 'string',
                'label' => 'Estimator Mode',
                'description' => 'The estimator mode for the estimate.',
                'required' => false,
                'default' => 'template',
                'options' => [
                    'simple' => 'Simple',
                    'template' => 'Template',
                    'detailed' => 'Detailed',
                ],
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

        $title = $registry->resolveDynamicValues($config['title'] ?? 'New Estimate', $run);
        $projectType = $config['project_type'] ?? 'interior';
        $estimatorMode = $config['estimator_mode'] ?? 'template';

        $deal = $this->resolveDeal($run);

        $estimate = Estimate::create(array_filter([
            'deal_id' => $deal?->id,
            'created_by' => $run->triggered_by,
            'title' => $title,
            'status' => 'draft',
            'project_type' => $projectType,
            'estimator_mode' => $estimatorMode,
            'subtotal' => 0,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total' => 0,
        ], fn ($v) => $v !== null));

        // Store estimate_id in context for downstream actions
        $context = $run->context ?? [];
        $context['estimate_id'] = $estimate->id;
        $run->update(['context' => $context]);

        return ['estimate_id' => $estimate->id];
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
