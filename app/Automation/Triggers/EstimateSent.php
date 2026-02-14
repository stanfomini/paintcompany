<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class EstimateSent implements TriggerInterface
{
    public static function getName(): string
    {
        return 'Estimate Sent';
    }

    public static function getCategory(): string
    {
        return 'Estimates';
    }

    public static function getDescription(): string
    {
        return 'Fires when an estimate proposal is sent to a customer.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $config
     */
    public function matches(string $event, Model $subject, array $context, array $config): bool
    {
        return $event === 'estimate.sent';
    }
}
