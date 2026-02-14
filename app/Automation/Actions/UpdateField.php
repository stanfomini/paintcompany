<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Models\WorkflowRun;

class UpdateField implements ActionInterface
{
    public static function getName(): string
    {
        return 'Update Field';
    }

    public static function getCategory(): string
    {
        return 'Data Management';
    }

    public static function getDescription(): string
    {
        return 'Updates a specific field on the workflow subject model.';
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
                'description' => 'The database field to update on the subject.',
                'required' => true,
            ],
            'value' => [
                'type' => 'mixed',
                'label' => 'New Value',
                'description' => 'The value to set on the field.',
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
        $field = $config['field'] ?? null;
        $value = $config['value'] ?? null;
        $subject = $run->subject;

        if (! $subject || ! $field) {
            return ['field' => $field, 'old_value' => null, 'new_value' => null];
        }

        $oldValue = $subject->getAttribute($field);

        $subject->update([$field => $value]);

        return ['field' => $field, 'old_value' => $oldValue, 'new_value' => $value];
    }
}
