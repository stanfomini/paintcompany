<?php

namespace App\Observers;

use App\Jobs\EvaluateWorkflowJob;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;

class WorkflowModelObserver
{
    /**
     * Handle the "created" event.
     */
    public function created(Model $model): void
    {
        $this->dispatchEvaluation($model, 'created');
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

        $this->dispatchEvaluation($model, $event, $changed);
    }

    /**
     * Dispatch workflow evaluation to the queue.
     *
     * @param  array<int, string>  $changedFields
     */
    protected function dispatchEvaluation(Model $model, string $event, array $changedFields = []): void
    {
        EvaluateWorkflowJob::dispatch(
            $this->getModelType($model),
            $model->getKey(),
            get_class($model),
            $event,
            $changedFields,
        );
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
