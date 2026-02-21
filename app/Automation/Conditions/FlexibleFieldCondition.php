<?php

namespace App\Automation\Conditions;

use App\Automation\Contracts\ConditionInterface;
use App\Automation\Registry;
use App\Models\WorkflowRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class FlexibleFieldCondition implements ConditionInterface
{
    public static function getName(): string
    {
        return 'Flexible Field Condition';
    }

    public static function getCategory(): string
    {
        return 'Field Checks';
    }

    public static function getDescription(): string
    {
        return 'A powerful condition that evaluates any field using dot notation paths with flexible operators. Supports subject fields, context fields, and webhook payload data.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'field' => [
                'type' => 'string',
                'label' => 'Field Path',
                'description' => 'Dot-notation path to the field (e.g., "status", "context.assigned_to", "webhook.payload.email").',
                'required' => true,
            ],
            'operator' => [
                'type' => 'string',
                'label' => 'Operator',
                'required' => true,
                'options' => [
                    'equals' => 'Equals',
                    'not_equals' => 'Not Equals',
                    'contains' => 'Contains',
                    'not_contains' => 'Does Not Contain',
                    'starts_with' => 'Starts With',
                    'ends_with' => 'Ends With',
                    'greater_than' => 'Greater Than',
                    'less_than' => 'Less Than',
                    'greater_or_equal' => 'Greater Than or Equal',
                    'less_or_equal' => 'Less Than or Equal',
                    'in' => 'In List',
                    'not_in' => 'Not In List',
                    'is_empty' => 'Is Empty',
                    'is_not_empty' => 'Is Not Empty',
                    'is_true' => 'Is True',
                    'is_false' => 'Is False',
                ],
            ],
            'value' => [
                'type' => 'mixed',
                'label' => 'Value',
                'description' => 'The value to compare against. Supports {{dynamic.values}} placeholders. For "in"/"not_in", use comma-separated values.',
                'required' => false,
            ],
            'logic' => [
                'type' => 'string',
                'label' => 'Logic',
                'description' => 'How to combine with other conditions in the same group.',
                'required' => false,
                'default' => 'and',
                'options' => ['and' => 'AND', 'or' => 'OR'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function evaluate(WorkflowRun $run, array $config): bool
    {
        $fieldPath = $config['field'] ?? null;
        $operator = $config['operator'] ?? 'equals';
        $compareValue = $config['value'] ?? null;

        if ($fieldPath === null) {
            return false;
        }

        // Resolve dynamic values in compare value
        if (is_string($compareValue) && str_contains($compareValue, '{{')) {
            $registry = app(Registry::class);
            $compareValue = $registry->resolveDynamicValues($compareValue, $run);
        }

        $fieldValue = $this->resolveFieldValue($fieldPath, $run);

        return $this->evaluateOperator($operator, $fieldValue, $compareValue);
    }

    protected function resolveFieldValue(string $path, WorkflowRun $run): mixed
    {
        // Handle context. prefix — look in run context
        if (str_starts_with($path, 'context.')) {
            return Arr::get($run->context ?? [], substr($path, 8));
        }

        // Handle webhook. prefix — look in context webhook_payload
        if (str_starts_with($path, 'webhook.')) {
            return Arr::get($run->context['webhook_payload'] ?? [], substr($path, 8));
        }

        // Default: look on subject model
        $subject = $run->subject;
        if (! $subject) {
            return null;
        }

        // Support dot notation for nested relationships/attributes
        if (str_contains($path, '.')) {
            return data_get($subject, $path);
        }

        return $subject->getAttribute($path);
    }

    protected function evaluateOperator(string $operator, mixed $fieldValue, mixed $compareValue): bool
    {
        return match ($operator) {
            'equals' => $fieldValue == $compareValue,
            'not_equals' => $fieldValue != $compareValue,
            'contains' => is_string($fieldValue) && Str::contains($fieldValue, (string) $compareValue, true),
            'not_contains' => is_string($fieldValue) && ! Str::contains($fieldValue, (string) $compareValue, true),
            'starts_with' => is_string($fieldValue) && Str::startsWith($fieldValue, (string) $compareValue),
            'ends_with' => is_string($fieldValue) && Str::endsWith($fieldValue, (string) $compareValue),
            'greater_than' => is_numeric($fieldValue) && is_numeric($compareValue) && $fieldValue > $compareValue,
            'less_than' => is_numeric($fieldValue) && is_numeric($compareValue) && $fieldValue < $compareValue,
            'greater_or_equal' => is_numeric($fieldValue) && is_numeric($compareValue) && $fieldValue >= $compareValue,
            'less_or_equal' => is_numeric($fieldValue) && is_numeric($compareValue) && $fieldValue <= $compareValue,
            'in' => in_array($fieldValue, $this->parseList($compareValue)),
            'not_in' => ! in_array($fieldValue, $this->parseList($compareValue)),
            'is_empty' => $fieldValue === null || $fieldValue === '' || $fieldValue === [],
            'is_not_empty' => $fieldValue !== null && $fieldValue !== '' && $fieldValue !== [],
            'is_true' => filter_var($fieldValue, FILTER_VALIDATE_BOOLEAN),
            'is_false' => ! filter_var($fieldValue, FILTER_VALIDATE_BOOLEAN),
            default => false,
        };
    }

    /**
     * @return array<int, string>
     */
    protected function parseList(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            return array_map('trim', explode(',', $value));
        }

        return [(string) $value];
    }
}
