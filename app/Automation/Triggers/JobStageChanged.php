<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class JobStageChanged implements TriggerInterface
{
    public static function getName(): string
    {
        return 'Job Stage Changed';
    }

    public static function getCategory(): string
    {
        return 'Jobs';
    }

    public static function getDescription(): string
    {
        return 'Fires when a job moves to a specific progress stage.';
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
                'description' => 'The job stage that must be reached for this trigger to fire.',
                'required' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $config
     */
    public function matches(string $event, Model $subject, array $context, array $config): bool
    {
        if ($event !== 'job.stage_changed') {
            return false;
        }

        $targetStage = $config['target_stage'] ?? null;

        if ($targetStage === null) {
            return true;
        }

        return ($context['to_stage'] ?? null) === $targetStage;
    }
}
