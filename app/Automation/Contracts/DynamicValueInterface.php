<?php

namespace App\Automation\Contracts;

use App\Models\WorkflowRun;

interface DynamicValueInterface
{
    /**
     * The prefix used to identify this resolver (e.g., 'contact', 'deal').
     */
    public static function getPrefix(): string;

    /**
     * The keys this resolver can provide (e.g., ['name', 'email', 'phone']).
     *
     * @return array<int, string>
     */
    public static function getAvailableKeys(): array;

    /**
     * Resolve a dynamic value for the given key and workflow run.
     */
    public function resolve(string $key, WorkflowRun $run): ?string;
}
