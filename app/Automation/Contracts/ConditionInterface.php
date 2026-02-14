<?php

namespace App\Automation\Contracts;

use App\Models\WorkflowRun;

interface ConditionInterface
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
     * Evaluate whether this condition passes for the given workflow run.
     *
     * @param  array<string, mixed>  $config
     */
    public function evaluate(WorkflowRun $run, array $config): bool;
}
