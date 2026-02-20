<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use App\Models\DoorKnock;
use Illuminate\Database\Eloquent\Model;

class DoorKnockRecorded implements TriggerInterface
{
    public static function getName(): string
    {
        return 'Door Knock Recorded';
    }

    public static function getCategory(): string
    {
        return 'Sales';
    }

    public static function getDescription(): string
    {
        return 'Fires when a door knock with customer contact info is recorded.';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableDynamicPrefixes(): array
    {
        return ['doorknock', 'workflow', 'datetime'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'status_filter' => [
                'type' => 'string',
                'label' => 'Status Filter',
                'description' => 'Only trigger for knocks with this status. Leave empty for all statuses.',
                'required' => false,
                'options' => DoorKnock::STATUSES,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $config
     */
    public function matches(string $event, Model $subject, array $context, array $config): bool
    {
        if ($event !== 'doorknock.recorded') {
            return false;
        }

        $statusFilter = $config['status_filter'] ?? null;

        if ($statusFilter === null || $statusFilter === '') {
            return true;
        }

        return ($context['status'] ?? null) === $statusFilter;
    }
}
