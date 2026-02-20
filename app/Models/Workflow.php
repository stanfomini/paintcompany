<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workflow extends Model
{
    /** @use HasFactory<\Database\Factories\WorkflowFactory> */
    use HasFactory;

    use SoftDeletes;

    public const USER_SCOPE_ANY = 'any';

    public const USER_SCOPE_SPECIFIC_USER = 'specific_user';

    public const USER_SCOPE_ROLE = 'role';

    public const USER_SCOPE_USER_TYPE = 'user_type';

    protected $fillable = [
        'name',
        'description',
        'is_active',
        'is_system',
        'version',
        'priority',
        'user_scope_type',
        'user_scope_value',
        'service_category_id',
        'created_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'priority' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Get the user who created this workflow.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Alias for creator using the created_by convention.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the service category scope for this workflow.
     */
    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    // =========================================================================
    // Graph-based workflow relationships (existing)
    // =========================================================================

    /**
     * Get the nodes for this workflow.
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(WorkflowNode::class);
    }

    /**
     * Get the edges for this workflow.
     */
    public function edges(): HasMany
    {
        return $this->hasMany(WorkflowEdge::class);
    }

    /**
     * Get the runs for this workflow.
     */
    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    // =========================================================================
    // Engine-based workflow relationships (Phase 4a)
    // =========================================================================

    /**
     * Get the triggers for this workflow, ordered by priority.
     */
    public function triggers(): HasMany
    {
        return $this->hasMany(WorkflowTrigger::class)->orderBy('order');
    }

    /**
     * Get the actions for this workflow, ordered by priority.
     */
    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowAction::class)->orderBy('order');
    }

    /**
     * Get the execution logs for this workflow.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(WorkflowLog::class);
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope to filter active workflows.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter published workflows.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    /**
     * Scope to filter workflows that have triggers for a specific model type.
     */
    public function scopeForModel(Builder $query, string $modelType): Builder
    {
        return $query->whereHas('triggers', function (Builder $q) use ($modelType) {
            $q->where('trigger_model', $modelType);
        });
    }
}
