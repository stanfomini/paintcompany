<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class ApplicationReceived implements TriggerInterface
{
    public static function getName(): string
    {
        return 'Application Received';
    }

    public static function getCategory(): string
    {
        return 'Hiring';
    }

    public static function getDescription(): string
    {
        return 'Fires when someone submits a job application via the careers page.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'source' => [
                'type' => 'string',
                'label' => 'Application Source',
                'description' => 'Optionally limit to a specific source. Leave empty for all sources.',
                'required' => false,
                'options' => [
                    'web' => 'Website',
                    'qr_code' => 'QR Code',
                    'manual' => 'Manual Entry',
                    'referral' => 'Referral',
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
        if ($event !== 'application.received') {
            return false;
        }

        $source = $config['source'] ?? null;

        if ($source === null || $source === '') {
            return true;
        }

        return ($context['source'] ?? null) === $source;
    }
}
