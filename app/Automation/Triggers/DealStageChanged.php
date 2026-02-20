<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class DealStageChanged implements TriggerInterface
{
    public static function getName(): string
    {
        return 'Deal Stage Changed';
    }

    public static function getCategory(): string
    {
        return 'Deals';
    }

    public static function getDescription(): string
    {
        return 'Fires when a deal moves to a specific pipeline stage.';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableDynamicPrefixes(): array
    {
        return ['deal', 'contact', 'workflow', 'datetime'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'target_stage' => [
                'type' => 'string',
                'label' => 'Target Stage',
                'description' => 'The stage the deal must move to for this trigger to fire.',
                'required' => true,
                'options' => \App\Models\Deal::STAGES,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $config
     */
    public function matches(string $event, Model $subject, array $context, array $config): bool
    {
        if ($event !== 'deal.stage_changed') {
            return false;
        }

        $targetStage = $config['target_stage'] ?? null;

        if ($targetStage === null) {
            return true;
        }

        return ($context['to_stage'] ?? null) === $targetStage;
    }
}
