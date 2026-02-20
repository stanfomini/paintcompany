<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class ContactCreated implements TriggerInterface
{
    public static function getName(): string
    {
        return 'Contact Created';
    }

    public static function getCategory(): string
    {
        return 'Contacts';
    }

    public static function getDescription(): string
    {
        return 'Fires when a new contact (user) is created, optionally filtered by type.';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableDynamicPrefixes(): array
    {
        return ['contact', 'workflow', 'datetime'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'user_type' => [
                'type' => 'string',
                'label' => 'User Type',
                'description' => 'Optionally limit to a specific user type. Leave empty for all types.',
                'required' => false,
                'options' => [
                    'internal' => 'Internal',
                    'agent' => 'Agent',
                    'customer' => 'Customer',
                    'crew' => 'Crew',
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
        if ($event !== 'contact.created') {
            return false;
        }

        $userType = $config['user_type'] ?? null;

        if ($userType === null || $userType === '') {
            return true;
        }

        return ($context['user_type'] ?? null) === $userType;
    }
}
