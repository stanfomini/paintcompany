<?php

namespace App\Automation\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TriggerInterface
{
    public static function getName(): string;

    public static function getCategory(): string;

    public static function getDescription(): string;

    /**
     * JSON Schema for builder UI configuration.
     *
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array;

    /**
     * Determine if this trigger matches the given event and context.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $config
     */
    public function matches(string $event, Model $subject, array $context, array $config): bool;
}
