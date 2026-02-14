<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class InboundWebhook implements TriggerInterface
{
    public static function getName(): string
    {
        return 'Inbound Webhook';
    }

    public static function getCategory(): string
    {
        return 'Integrations';
    }

    public static function getDescription(): string
    {
        return 'Fires when an external system sends a POST request to the automation webhook URL.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'webhook_token' => [
                'type' => 'string',
                'label' => 'Webhook Token',
                'description' => 'The specific webhook token to listen for. Leave empty to match all webhooks.',
                'required' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $config
     */
    public function matches(string $event, Model $subject, array $context, array $config): bool
    {
        if ($event !== 'webhook.received') {
            return false;
        }

        $targetToken = $config['webhook_token'] ?? null;

        if ($targetToken === null || $targetToken === '') {
            return true;
        }

        return ($context['webhook_token'] ?? null) === $targetToken;
    }
}
