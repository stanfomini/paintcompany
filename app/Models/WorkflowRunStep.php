<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowRunStep extends Model
{
    /** @use HasFactory<\Database\Factories\WorkflowRunStepFactory> */
    use HasFactory;

    protected $fillable = [
        'workflow_run_id',
        'workflow_node_id',
        'status',
        'scheduled_for',
        'started_at',
        'completed_at',
        'result',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the workflow run this step belongs to.
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    /**
     * Get the workflow node for this step.
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class, 'workflow_node_id');
    }

    /**
     * Scope to filter pending steps.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to filter waiting steps.
     */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', 'waiting');
    }

    /**
     * Scope to filter due steps (waiting and past their scheduled time).
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', 'waiting')
            ->where('scheduled_for', '<=', now());
    }
}
