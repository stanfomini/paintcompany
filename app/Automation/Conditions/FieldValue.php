<?php

namespace App\Automation\Conditions;

use App\Automation\Contracts\ConditionInterface;
use App\Models\WorkflowRun;
use Illuminate\Support\Str;

class FieldValue implements ConditionInterface
{
    public static function getName(): string
    {
        return 'Field Value';
    }

    public static function getCategory(): string
    {
        return 'Field Checks';
    }

    public static function getDescription(): string
    {
        return 'Compares a field on the subject model against a configured value using the specified operator.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'field' => [
                'type' => 'string',
                'label' => 'Field Name',
                'description' => 'The model attribute to evaluate (e.g. "status", "amount").',
                'required' => true,
            ],
            'operator' => [
                'type' => 'string',
                'label' => 'Operator',
                'description' => 'The comparison operator.',
                'required' => true,
                'options' => [
                    '=' => 'Equals',
                    '!=' => 'Not Equals',
                    '>' => 'Greater Than',
                    '<' => 'Less Than',
                    '>=' => 'Greater Than or Equal',
                    '<=' => 'Less Than or Equal',
                    'contains' => 'Contains',
                    'starts_with' => 'Starts With',
                    'is_empty' => 'Is Empty',
                    'is_not_empty' => 'Is Not Empty',
                ],
            ],
            'value' => [
                'type' => 'mixed',
                'label' => 'Value',
                'description' => 'The value to compare against.',
                'required' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function evaluate(WorkflowRun $run, array $config): bool
    {
        $subject = $run->subject;

        if ($subject === null) {
            return false;
        }

        $field = $config['field'] ?? null;
        $operator = $config['operator'] ?? '=';
        $value = $config['value'] ?? null;

        if ($field === null) {
            return false;
        }

        $fieldValue = $subject->getAttribute($field);

        return match ($operator) {
            '=' => $fieldValue == $value,
            '!=' => $fieldValue != $value,
            '>' => $fieldValue > $value,
            '<' => $fieldValue < $value,
            '>=' => $fieldValue >= $value,
            '<=' => $fieldValue <= $value,
            'contains' => is_string($fieldValue) && Str::contains($fieldValue, (string) $value),
            'starts_with' => is_string($fieldValue) && Str::startsWith($fieldValue, (string) $value),
            'is_empty' => $fieldValue === null || $fieldValue === '',
            'is_not_empty' => $fieldValue !== null && $fieldValue !== '',
            default => false,
        };
    }
}
