<?php

namespace App\Automation\DynamicValues;

use App\Automation\Contracts\DynamicValueInterface;
use App\Models\WorkflowRun;

class WebhookValues implements DynamicValueInterface
{
    public static function getPrefix(): string
    {
        return 'webhook';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableKeys(): array
    {
        return [];
    }

    public function resolve(string $key, WorkflowRun $run): ?string
    {
        $payload = $run->context['payload'] ?? [];

        $value = data_get($payload, $key);

        if ($value === null) {
            return null;
        }

        return is_array($value) ? json_encode($value) : (string) $value;
    }
}
