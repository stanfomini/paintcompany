<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WorkflowRun extends Model
{
    /** @use HasFactory<\Database\Factories\WorkflowRunFactory> */
    use HasFactory;

    protected $fillable = [
        'workflow_id',
        'subject_type',
        'subject_id',
        'status',
        'context',
        'current_node_id',
        'triggered_at',
        'completed_at',
        'error_message',
        'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'triggered_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the workflow for this run.
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Get the current node for this run.
     */
    public function currentNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class, 'current_node_id');
    }

    /**
     * Get the user who triggered this run.
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * Get the steps for this run.
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowRunStep::class);
    }

    /**
     * Get the subject (polymorphic) for this run.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to filter running workflow runs.
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', 'running');
    }

    /**
     * Scope to filter waiting workflow runs.
     */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', 'waiting');
    }

    /**
     * Scope to filter completed workflow runs.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }
}
