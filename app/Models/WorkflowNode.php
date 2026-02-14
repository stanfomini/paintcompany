<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowNode extends Model
{
    /** @use HasFactory<\Database\Factories\WorkflowNodeFactory> */
    use HasFactory;

    protected $fillable = [
        'workflow_id',
        'type',
        'node_class',
        'config',
        'position_x',
        'position_y',
        'label',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'position_x' => 'float',
            'position_y' => 'float',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * Get the workflow this node belongs to.
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Get the outgoing edges from this node.
     */
    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(WorkflowEdge::class, 'from_node_id');
    }

    /**
     * Get the incoming edges to this node.
     */
    public function incomingEdges(): HasMany
    {
        return $this->hasMany(WorkflowEdge::class, 'to_node_id');
    }
}
