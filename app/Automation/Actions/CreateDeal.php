<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\WorkflowRun;

class CreateDeal implements ActionInterface
{
    public static function getName(): string
    {
        return 'Create Deal';
    }

    public static function getCategory(): string
    {
        return 'Record Management';
    }

    public static function getDescription(): string
    {
        return 'Creates a new deal linked to the current customer. Supports dynamic values in title and description.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'title' => [
                'type' => 'string',
                'label' => 'Deal Title',
                'description' => 'The deal title. Supports {{dynamic.values}} placeholders.',
                'required' => false,
                'default' => 'New Lead',
            ],
            'stage' => [
                'type' => 'string',
                'label' => 'Initial Stage',
                'description' => 'The initial deal stage.',
                'required' => false,
                'default' => 'lead',
                'options' => Deal::STAGES,
            ],
            'lead_source' => [
                'type' => 'string',
                'label' => 'Lead Source',
                'description' => 'Lead source slug (e.g., facebook, google_ads).',
                'required' => false,
            ],
            'description' => [
                'type' => 'string',
                'label' => 'Description',
                'description' => 'Deal description. Supports {{dynamic.values}} placeholders.',
                'required' => false,
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

        $title = $registry->resolveDynamicValues($config['title'] ?? 'New Lead', $run);
        $stage = $config['stage'] ?? 'lead';
        $leadSource = $registry->resolveDynamicValues($config['lead_source'] ?? '', $run);
        $description = $registry->resolveDynamicValues($config['description'] ?? '', $run);

        $customer = $this->resolveCustomer($run);

        $deal = Deal::create(array_filter([
            'customer_id' => $customer?->id,
            'title' => $title,
            'stage' => $stage,
            'lead_source' => $leadSource ?: null,
            'description' => $description ?: null,
            'created_by' => $run->triggered_by,
        ], fn ($v) => $v !== null && $v !== ''));

        // Set deal_id in context for downstream actions
        $context = $run->context ?? [];
        $context['deal_id'] = $deal->id;
        $run->update(['context' => $context]);

        // Link DoorKnock back if this was triggered by one
        if ($knockId = $run->context['doorknock_id'] ?? null) {
            \App\Models\DoorKnock::where('id', $knockId)->update([
                'deal_id' => $deal->id,
                'customer_id' => $run->context['customer_id'] ?? null,
                'property_id' => $run->context['property_id'] ?? null,
            ]);
        }

        return ['deal_id' => $deal->id];
    }

    protected function resolveCustomer(WorkflowRun $run): ?Customer
    {
        $subject = $run->subject;

        if ($subject instanceof Customer) {
            return $subject;
        }

        $customerId = $run->context['customer_id'] ?? null;

        if ($customerId) {
            return Customer::find($customerId);
        }

        return null;
    }
}
