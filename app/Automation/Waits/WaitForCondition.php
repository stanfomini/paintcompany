<?php

namespace App\Automation\Waits;

use App\Automation\Contracts\ConditionInterface;
use App\Automation\Contracts\WaitInterface;
use App\Automation\Registry;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunStep;
use Carbon\Carbon;

class WaitForCondition implements WaitInterface
{
    public static function getName(): string
    {
        return 'Wait for Condition';
    }

    public static function getCategory(): string
    {
        return 'Condition-Based';
    }

    public static function getDescription(): string
    {
        return 'Pauses the workflow until a condition is met or a timeout is reached. Checks periodically at the configured interval.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'condition_class' => [
                'type' => 'string',
                'label' => 'Condition',
                'description' => 'The fully qualified class name of the condition to evaluate.',
                'required' => true,
            ],
            'condition_config' => [
                'type' => 'array',
                'label' => 'Condition Configuration',
                'description' => 'Configuration to pass to the condition evaluator.',
                'required' => false,
                'default' => [],
            ],
            'timeout_hours' => [
                'type' => 'integer',
                'label' => 'Timeout (Hours)',
                'description' => 'Maximum hours to wait before timing out. Defaults to 168 (7 days).',
                'required' => false,
                'default' => 168,
            ],
            'check_interval_hours' => [
                'type' => 'integer',
                'label' => 'Check Interval (Hours)',
                'description' => 'How often to re-evaluate the condition. Defaults to 1 hour.',
                'required' => false,
                'default' => 1,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function getResumeAt(WorkflowRun $run, array $config): Carbon
    {
        $intervalHours = $config['check_interval_hours'] ?? 1;

        return Carbon::now()->addHours($intervalHours);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function shouldResume(WorkflowRunStep $step, WorkflowRun $run, array $config): bool
    {
        $timeoutHours = $config['timeout_hours'] ?? 168;

        if ($step->started_at && $step->started_at->addHours($timeoutHours)->lte(now())) {
            return true;
        }

        $conditionClass = $config['condition_class'] ?? null;
        $conditionConfig = $config['condition_config'] ?? [];

        if (! $conditionClass) {
            return true;
        }

        $registry = app(Registry::class);
        $condition = $registry->findCondition($conditionClass);

        if (! $condition instanceof ConditionInterface) {
            return true;
        }

        return $condition->evaluate($run, $conditionConfig);
    }
}
