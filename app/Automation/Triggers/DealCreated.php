<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class DealCreated implements TriggerInterface
{
    public static function getName(): string
    {
        return 'Deal Created';
    }

    public static function getCategory(): string
    {
        return 'Deals';
    }

    public static function getDescription(): string
    {
        return 'Fires when a new deal is created, optionally filtered by initial stage.';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableDynamicPrefixes(): array
    {
        return ['deal', 'contact', 'preview', 'workflow', 'datetime'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'initial_stage' => [
                'type' => 'string',
                'label' => 'Initial Stage',
                'description' => 'Optionally limit to deals created in a specific stage. Leave empty for all.',
                'required' => false,
                'options' => [
                    'lead' => 'New Lead',
                    'not_contacted' => 'Not Contacted',
                    'follow_up' => 'Follow Up',
                    'quote_scheduled' => 'Quote Scheduled',
                    'quoted' => 'Quoted',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $config
     */
    public function matches(string $event, Model $subject, array $context, array $config): bool
    {
        if ($event !== 'deal.created') {
            return false;
        }

        $stage = $config['initial_stage'] ?? null;

        if ($stage === null || $stage === '') {
            return true;
        }

        return ($context['stage'] ?? null) === $stage;
    }
}
