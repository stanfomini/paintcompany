<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\EmployeeProfile;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use App\Models\WorkflowLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class WorkflowService
{
    public function __construct(
        private CommissionService $commissionService,
        private JobPayableService $jobPayableService,
    ) {}

    /**
     * Evaluate all active workflows for a model event.
     *
     * @param  array<int, string>  $changedFields
     * @return array<int, WorkflowLog>
     */
    public function evaluate(string $modelType, Model $model, string $event, array $changedFields = []): array
    {
        $workflows = Workflow::active()
            ->forModel($modelType)
            ->orderBy('priority')
            ->with(['triggers', 'actions', 'serviceCategory'])
            ->get();

        $results = [];

        foreach ($workflows as $workflow) {
            if (! $this->matchesUserScope($workflow, $model)) {
                continue;
            }

            if (! $this->matchesServiceScope($workflow, $model)) {
                continue;
            }

            if (! $this->triggerMatches($workflow, $modelType, $event, $model, $changedFields)) {
                continue;
            }

            $log = $this->executeWorkflow($workflow, $model);
            $results[] = $log;
        }

        return $results;
    }

    /**
     * Manually run a workflow against a deal (from AR portal).
     */
    public function runManually(Workflow $workflow, Deal $deal): WorkflowLog
    {
        return $this->executeWorkflow($workflow, $deal, isManual: true);
    }

    /**
     * Execute a workflow's actions against a model.
     */
    protected function executeWorkflow(Workflow $workflow, Model $model, bool $isManual = false): WorkflowLog
    {
        $actionsExecuted = [];
        $targetUser = null;
        $errorMessage = null;
        $status = WorkflowLog::STATUS_SUCCESS;

        try {
            foreach ($workflow->actions as $action) {
                $result = $this->executeAction($action, $model);
                $actionsExecuted[] = $result;

                if (isset($result['target_user_id']) && ! $targetUser) {
                    $targetUser = $result['target_user_id'];
                }
            }
        } catch (\Throwable $e) {
            $status = WorkflowLog::STATUS_FAILED;
            $errorMessage = $e->getMessage();
            Log::warning("Workflow #{$workflow->id} failed: {$e->getMessage()}", [
                'workflow_id' => $workflow->id,
                'model_type' => get_class($model),
                'model_id' => $model->getKey(),
            ]);
        }

        return WorkflowLog::create([
            'workflow_id' => $workflow->id,
            'trigger_model_type' => get_class($model),
            'trigger_model_id' => $model->getKey(),
            'target_user_id' => $targetUser,
            'status' => $status,
            'trigger_snapshot' => $model->toArray(),
            'actions_executed' => $actionsExecuted,
            'error_message' => $errorMessage,
            'is_manual' => $isManual,
            'created_at' => now(),
        ]);
    }

    /**
     * Execute a single workflow action.
     *
     * @return array<string, mixed>
     */
    protected function executeAction(WorkflowAction $action, Model $model): array
    {
        $config = $action->action_config ?? [];

        return match ($action->action_type) {
            WorkflowAction::ACTION_CREATE_COMMISSION => $this->executeCreateCommission($action, $model, $config),
            WorkflowAction::ACTION_CREATE_FLAT_RATE => $this->executeCreateFlatRate($action, $model, $config),
            WorkflowAction::ACTION_CREATE_PAYABLE => $this->executeCreatePayable($action, $model, $config),
            WorkflowAction::ACTION_UPDATE_FIELD => $this->executeUpdateField($model, $config),
            WorkflowAction::ACTION_SEND_NOTIFICATION => $this->executeNotificationPlaceholder($action, $model, $config),
            WorkflowAction::ACTION_CREATE_INVOICE => $this->executeInvoicePlaceholder($action, $model, $config),
            default => ['action' => $action->action_type, 'status' => 'unknown_action'],
        };
    }

    /**
     * Create a commission entry using CommissionService.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function executeCreateCommission(WorkflowAction $action, Model $model, array $config): array
    {
        $targetUserId = $this->resolveTargetUserId($action->target_user_field, $model);

        if (! $targetUserId) {
            return ['action' => 'create_commission', 'status' => 'skipped', 'reason' => 'no_target_user'];
        }

        $employee = EmployeeProfile::query()
            ->where('user_id', $targetUserId)
            ->where('employment_status', 'active')
            ->first();

        if (! $employee) {
            return ['action' => 'create_commission', 'status' => 'skipped', 'reason' => 'no_employee_profile'];
        }

        // Determine source model for CommissionService
        $entries = match (true) {
            $model instanceof Deal => $this->commissionService->createFromDeal($model),
            $model instanceof Estimate => $this->commissionService->createFromEstimate($model),
            $model instanceof Job => $this->commissionService->createFromJob($model),
            default => [],
        };

        return [
            'action' => 'create_commission',
            'status' => 'executed',
            'target_user_id' => $targetUserId,
            'entries_created' => count($entries),
        ];
    }

    /**
     * Create a flat rate commission entry directly.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function executeCreateFlatRate(WorkflowAction $action, Model $model, array $config): array
    {
        $targetUserId = $this->resolveTargetUserId($action->target_user_field, $model);

        if (! $targetUserId) {
            return ['action' => 'create_flat_rate', 'status' => 'skipped', 'reason' => 'no_target_user'];
        }

        $employee = EmployeeProfile::query()
            ->where('user_id', $targetUserId)
            ->where('employment_status', 'active')
            ->first();

        if (! $employee) {
            return ['action' => 'create_flat_rate', 'status' => 'skipped', 'reason' => 'no_employee_profile'];
        }

        $amount = (float) ($config['amount'] ?? 0);

        if ($amount <= 0) {
            return ['action' => 'create_flat_rate', 'status' => 'skipped', 'reason' => 'zero_amount'];
        }

        $entry = CommissionEntry::create([
            'employee_profile_id' => $employee->id,
            'source_type' => get_class($model),
            'source_id' => $model->getKey(),
            'source_label' => 'Workflow: '.$model->getAttribute('title') ?? "#{$model->getKey()}",
            'commission_type' => 'flat',
            'basis_amount' => 0,
            'rate' => 0,
            'amount' => round($amount, 2),
            'status' => 'pending',
            'earned_date' => now()->toDateString(),
            'notes' => $config['notes'] ?? null,
        ]);

        return [
            'action' => 'create_flat_rate',
            'status' => 'executed',
            'target_user_id' => $targetUserId,
            'commission_entry_id' => $entry->id,
            'amount' => $amount,
        ];
    }

    /**
     * Create a job payable via JobPayableService.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function executeCreatePayable(WorkflowAction $action, Model $model, array $config): array
    {
        $job = $this->resolveJob($model);

        if (! $job) {
            return ['action' => 'create_payable', 'status' => 'skipped', 'reason' => 'no_job'];
        }

        $targetUserId = $this->resolveTargetUserId($action->target_user_field, $model);

        if (! $targetUserId) {
            return ['action' => 'create_payable', 'status' => 'skipped', 'reason' => 'no_target_user'];
        }

        $user = \App\Models\User::find($targetUserId);

        if (! $user) {
            return ['action' => 'create_payable', 'status' => 'skipped', 'reason' => 'user_not_found'];
        }

        $formula = $config['formula'] ?? 'flat';
        $amount = (float) ($config['amount'] ?? 0);

        if ($formula === 'crew_deposit' || $formula === 'crew_final') {
            $payables = $this->jobPayableService->createCrewPayables($job, $user);

            return [
                'action' => 'create_payable',
                'status' => 'executed',
                'target_user_id' => $targetUserId,
                'formula' => $formula,
                'payables_created' => count($payables),
            ];
        }

        // Flat amount payable
        if ($amount <= 0) {
            return ['action' => 'create_payable', 'status' => 'skipped', 'reason' => 'zero_amount'];
        }

        $payable = $this->jobPayableService->createEmployeePayable($job, $user, [
            'description' => $config['description'] ?? 'Workflow payable',
            'amount' => $amount,
        ]);

        return [
            'action' => 'create_payable',
            'status' => 'executed',
            'target_user_id' => $targetUserId,
            'payable_id' => $payable->id,
            'amount' => $amount,
        ];
    }

    /**
     * Update a field on the model.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function executeUpdateField(Model $model, array $config): array
    {
        $field = $config['field'] ?? null;
        $value = $config['value'] ?? null;

        if (! $field) {
            return ['action' => 'update_field', 'status' => 'skipped', 'reason' => 'no_field'];
        }

        $model->setAttribute($field, $value);
        $model->saveQuietly();

        return [
            'action' => 'update_field',
            'status' => 'executed',
            'field' => $field,
            'value' => $value,
        ];
    }

    /**
     * Placeholder for send_notification action.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function executeNotificationPlaceholder(WorkflowAction $action, Model $model, array $config): array
    {
        return [
            'action' => 'send_notification',
            'status' => 'placeholder',
            'message' => $config['message'] ?? 'No message configured',
        ];
    }

    /**
     * Placeholder for create_invoice action.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function executeInvoicePlaceholder(WorkflowAction $action, Model $model, array $config): array
    {
        return [
            'action' => 'create_invoice',
            'status' => 'placeholder',
        ];
    }

    // =========================================================================
    // Matching Methods
    // =========================================================================

    /**
     * Check if the workflow's user scope matches the model.
     */
    protected function matchesUserScope(Workflow $workflow, Model $model): bool
    {
        if ($workflow->user_scope_type === Workflow::USER_SCOPE_ANY) {
            return true;
        }

        $targetUser = $this->resolveTargetUserForScope($workflow, $model);

        if (! $targetUser) {
            return false;
        }

        return match ($workflow->user_scope_type) {
            Workflow::USER_SCOPE_SPECIFIC_USER => $targetUser->id == $workflow->user_scope_value,
            Workflow::USER_SCOPE_ROLE => $targetUser->hasRole($workflow->user_scope_value),
            Workflow::USER_SCOPE_USER_TYPE => $targetUser->type === $workflow->user_scope_value,
            default => false,
        };
    }

    /**
     * Check if the workflow's service category scope matches the model.
     */
    protected function matchesServiceScope(Workflow $workflow, Model $model): bool
    {
        if (! $workflow->service_category_id) {
            return true;
        }

        // Walk through model chain to find a job with service categories
        $job = $this->resolveJob($model);

        if (! $job) {
            return true; // No job context = no service filtering
        }

        // Check if any crew assignment has this service category
        return $job->crews()
            ->wherePivot('service_category_id', $workflow->service_category_id)
            ->exists();
    }

    /**
     * Check if any trigger on the workflow matches the current event.
     *
     * @param  array<int, string>  $changedFields
     */
    protected function triggerMatches(
        Workflow $workflow,
        string $modelType,
        string $event,
        Model $model,
        array $changedFields
    ): bool {
        foreach ($workflow->triggers as $trigger) {
            if ($trigger->trigger_model !== $modelType) {
                continue;
            }

            if ($trigger->trigger_event !== $event) {
                continue;
            }

            // Check trigger conditions
            if ($trigger->trigger_conditions && ! $this->evaluateConditions($trigger->trigger_conditions, $model, $changedFields)) {
                continue;
            }

            return true;
        }

        return false;
    }

    // =========================================================================
    // Condition Evaluation
    // =========================================================================

    /**
     * Evaluate a set of conditions against a model.
     *
     * @param  array<string, mixed>  $conditionSet
     * @param  array<int, string>  $changedFields
     */
    public function evaluateConditions(array $conditionSet, Model $model, array $changedFields = []): bool
    {
        $logic = $conditionSet['logic'] ?? 'all';
        $conditions = $conditionSet['conditions'] ?? [];

        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            $result = $this->evaluateSingleCondition($condition, $model, $changedFields);

            if ($logic === 'all' && ! $result) {
                return false;
            }

            if ($logic === 'any' && $result) {
                return true;
            }
        }

        return $logic === 'all';
    }

    /**
     * Evaluate a single condition against a model.
     *
     * @param  array<string, mixed>  $condition
     * @param  array<int, string>  $changedFields
     */
    protected function evaluateSingleCondition(array $condition, Model $model, array $changedFields = []): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $value = $condition['value'] ?? null;

        if (! $field) {
            return false;
        }

        $currentValue = $model->getAttribute($field);

        return match ($operator) {
            'equals' => $currentValue == $value,
            'not_equals' => $currentValue != $value,
            'changed_to' => in_array($field, $changedFields) && $currentValue == $value,
            'changed_from' => in_array($field, $changedFields) && $model->getOriginal($field) == $value,
            'is_null' => is_null($currentValue),
            'is_not_null' => ! is_null($currentValue),
            'greater_than' => (float) $currentValue > (float) $value,
            'less_than' => (float) $currentValue < (float) $value,
            'in' => is_array($value) && in_array($currentValue, $value),
            'not_in' => is_array($value) && ! in_array($currentValue, $value),
            'contains' => is_string($currentValue) && is_string($value) && str_contains($currentValue, $value),
            default => false,
        };
    }

    // =========================================================================
    // Resolution Helpers
    // =========================================================================

    /**
     * Resolve the target user for scope matching.
     * Walks through model relationships to find the right user.
     */
    protected function resolveTargetUserForScope(Workflow $workflow, Model $model): ?\App\Models\User
    {
        // Try common user fields on the model
        $userFields = ['assigned_to', 'lead_generator_id', 'created_by', 'user_id', 'sales_person_id', 'production_manager_id'];

        foreach ($userFields as $field) {
            $userId = $model->getAttribute($field);
            if ($userId) {
                return \App\Models\User::find($userId);
            }
        }

        // Walk through model chain
        if ($model instanceof Job) {
            return $model->deal?->assignedTo;
        }

        if ($model instanceof Estimate) {
            return $model->deal?->assignedTo;
        }

        return null;
    }

    /**
     * Resolve the target user ID from a field path on the model.
     * Supports dotted paths like 'deal.assigned_to'.
     */
    protected function resolveTargetUserId(?string $targetUserField, Model $model): ?int
    {
        if (! $targetUserField) {
            return null;
        }

        // Direct field on the model
        $value = $model->getAttribute($targetUserField);
        if ($value) {
            return (int) $value;
        }

        // Walk through model chain for common fields
        $deal = $this->resolveDeal($model);
        if ($deal) {
            $value = $deal->getAttribute($targetUserField);
            if ($value) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Resolve the Deal from any model in the chain.
     */
    protected function resolveDeal(Model $model): ?Deal
    {
        if ($model instanceof Deal) {
            return $model;
        }

        if ($model instanceof Job) {
            return $model->deal;
        }

        if ($model instanceof Estimate) {
            return $model->deal;
        }

        if (method_exists($model, 'deal')) {
            return $model->deal;
        }

        return null;
    }

    /**
     * Resolve the Job from any model in the chain.
     */
    protected function resolveJob(Model $model): ?Job
    {
        if ($model instanceof Job) {
            return $model;
        }

        if ($model instanceof Deal) {
            return $model->job;
        }

        if ($model instanceof Estimate) {
            return $model->job;
        }

        return null;
    }
}
