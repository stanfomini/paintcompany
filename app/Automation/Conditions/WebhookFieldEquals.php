<?php

namespace App\Automation\Conditions;

use App\Automation\Contracts\ConditionInterface;
use App\Models\WorkflowRun;

class WebhookFieldEquals implements ConditionInterface
{
    public static function getName(): string
    {
        return 'Webhook Field Equals';
    }

    public static function getCategory(): string
    {
        return 'Webhook Conditions';
    }

    public static function getDescription(): string
    {
        return 'Checks a field from the webhook payload against a value using various comparison operators.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'field_path' => [
                'type' => 'string',
                'label' => 'Field Path',
                'description' => 'Dot-notation path to the webhook payload field (e.g., source, lead.type).',
                'required' => true,
            ],
            'operator' => [
                'type' => 'string',
                'label' => 'Operator',
                'description' => 'Comparison operator.',
                'required' => true,
                'default' => '=',
                'options' => ['=' => 'Equals', '!=' => 'Not Equals', 'contains' => 'Contains', 'starts_with' => 'Starts With', 'is_set' => 'Is Set', 'is_empty' => 'Is Empty'],
            ],
            'value' => [
                'type' => 'string',
                'label' => 'Value',
                'description' => 'The value to compare against (not needed for is_set/is_empty).',
                'required' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function evaluate(WorkflowRun $run, array $config): bool
    {
        $fieldPath = $config['field_path'] ?? '';
        $operator = $config['operator'] ?? '=';
        $value = $config['value'] ?? '';

        if (empty($fieldPath)) {
            return false;
        }

        $payload = $run->context['payload'] ?? [];
        $fieldValue = data_get($payload, $fieldPath);

        return match ($operator) {
            '=' => (string) $fieldValue === $value,
            '!=' => (string) $fieldValue !== $value,
            'contains' => str_contains((string) $fieldValue, $value),
            'starts_with' => str_starts_with((string) $fieldValue, $value),
            'is_set' => $fieldValue !== null,
            'is_empty' => $fieldValue === null || $fieldValue === '',
            default => false,
        };
    }
}
