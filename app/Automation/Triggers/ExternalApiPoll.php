<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use App\Models\ExternalApiSource;
use Illuminate\Database\Eloquent\Model;

class ExternalApiPoll implements TriggerInterface
{
    public static function getName(): string
    {
        return 'External API Poll';
    }

    public static function getCategory(): string
    {
        return 'Integrations';
    }

    public static function getDescription(): string
    {
        return 'Fires when data is fetched from an external API endpoint on a schedule.';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableDynamicPrefixes(): array
    {
        return ['webhook', 'workflow', 'datetime'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'api_source_token' => [
                'type' => 'string',
                'label' => 'API Source',
                'description' => 'The specific API source to listen for. Leave empty to match all API sources.',
                'required' => false,
                'options' => array_replace(['' => '-- All API Sources --'], ExternalApiSource::where('is_active', true)->pluck('name', 'token')->toArray()),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $config
     */
    public function matches(string $event, Model $subject, array $context, array $config): bool
    {
        if ($event !== 'api_poll.received') {
            return false;
        }

        $targetToken = $config['api_source_token'] ?? null;

        if ($targetToken === null || $targetToken === '') {
            return true;
        }

        return ($context['api_source_token'] ?? null) === $targetToken;
    }
}
