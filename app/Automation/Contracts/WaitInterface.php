<?php

namespace App\Automation\Contracts;

use App\Models\WorkflowRun;
use App\Models\WorkflowRunStep;
use Carbon\Carbon;

interface WaitInterface
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
     * Calculate when this wait should resume.
     *
     * @param  array<string, mixed>  $config
     */
    public function getResumeAt(WorkflowRun $run, array $config): Carbon;

    /**
     * Determine if the wait condition is satisfied and the step should resume.
     *
     * @param  array<string, mixed>  $config
     */
    public function shouldResume(WorkflowRunStep $step, WorkflowRun $run, array $config): bool;
}
