<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class InboundMessengerReceived implements TriggerInterface
{
    public static function getName(): string
    {
        return 'Inbound Messenger Received';
    }

    public static function getCategory(): string
    {
        return 'Communication';
    }

    public static function getDescription(): string
    {
        return 'Fires when a Facebook Messenger message is received from a known customer. Optionally filter by keyword.';
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
            'keyword_filter' => [
                'type' => 'string',
                'label' => 'Keyword Filter',
                'description' => 'Only trigger when the message contains this word (case-insensitive). Leave empty for all messages.',
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
        if ($event !== 'messenger.received') {
            return false;
        }

        $keyword = $config['keyword_filter'] ?? null;

        if ($keyword === null || $keyword === '') {
            return true;
        }

        $text = $context['message_text'] ?? '';

        return str_contains(strtolower($text), strtolower($keyword));
    }
}
