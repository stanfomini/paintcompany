<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WorkflowLog extends Model
{
    use HasFactory;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * Only created_at is used; no updated_at.
     */
    public $timestamps = false;

    protected $fillable = [
        'workflow_id',
        'trigger_model_type',
        'trigger_model_id',
        'target_user_id',
        'status',
        'trigger_snapshot',
        'actions_executed',
        'error_message',
        'is_manual',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger_snapshot' => 'array',
            'actions_executed' => 'array',
            'is_manual' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the workflow that was executed.
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Get the target user for this log entry.
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * Get the model that triggered this workflow execution.
     */
    public function triggerModel(): MorphTo
    {
        return $this->morphTo('trigger_model');
    }
}
