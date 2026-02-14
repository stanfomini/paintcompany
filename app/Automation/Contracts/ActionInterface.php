<?php

namespace App\Automation\Contracts;

use App\Models\WorkflowRun;

interface ActionInterface
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
     * Execute the action for the given workflow run.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function execute(WorkflowRun $run, array $config): array;
}
