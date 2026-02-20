<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTrigger extends Model
{
    use HasFactory;

    protected $fillable = [
        'workflow_id',
        'trigger_model',
        'trigger_event',
        'trigger_field',
        'trigger_conditions',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'trigger_conditions' => 'array',
            'order' => 'integer',
        ];
    }

    /**
     * Get the workflow this trigger belongs to.
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
