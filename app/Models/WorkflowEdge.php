<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowEdge extends Model
{
    /** @use HasFactory<\Database\Factories\WorkflowEdgeFactory> */
    use HasFactory;

    protected $fillable = [
        'workflow_id',
        'from_node_id',
        'to_node_id',
        'branch',
        'condition_config',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'condition_config' => 'array',
        ];
    }

    /**
     * Get the workflow this edge belongs to.
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Get the source node of this edge.
     */
    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class, 'from_node_id');
    }

    /**
     * Get the destination node of this edge.
     */
    public function toNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class, 'to_node_id');
    }
}
