<?php

namespace App\Observers;

use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Payment;
use App\Services\WorkflowService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class WorkflowModelObserver
{
    /**
     * Handle the "created" event.
     */
    public function created(Model $model): void
    {
        $this->safeEvaluate($model, 'created');
    }

    /**
     * Handle the "updated" event.
     */
    public function updated(Model $model): void
    {
        $changed = array_keys($model->getChanges());

        // Filter out timestamp columns
        $changed = array_diff($changed, ['created_at', 'updated_at', 'deleted_at']);

        if (empty($changed)) {
            return;
        }

        $statusFields = ['stage', 'status'];
        $hasStatusChange = ! empty(array_intersect($changed, $statusFields));
        $event = $hasStatusChange ? 'status_changed' : 'field_changed';

        $this->safeEvaluate($model, $event, $changed);
    }

    /**
     * Safely evaluate workflows, catching any errors to prevent
     * observer failures from breaking the main operation.
     *
     * @param  array<int, string>  $changedFields
     */
    protected function safeEvaluate(Model $model, string $event, array $changedFields = []): void
    {
        try {
            $modelType = $this->getModelType($model);

            app(WorkflowService::class)->evaluate($modelType, $model, $event, $changedFields);
        } catch (\Throwable $e) {
            Log::error("WorkflowModelObserver error: {$e->getMessage()}", [
                'model' => get_class($model),
                'model_id' => $model->getKey(),
                'event' => $event,
            ]);
        }
    }

    /**
     * Get the short model type name for workflow matching.
     */
    protected function getModelType(Model $model): string
    {
        return match (true) {
            $model instanceof Deal => 'deal',
            $model instanceof Job => 'job',
            $model instanceof Estimate => 'estimate',
            $model instanceof Invoice => 'invoice',
            $model instanceof DoorKnock => 'door_knock',
            $model instanceof Payment => 'payment',
            default => strtolower(class_basename($model)),
        };
    }
}
