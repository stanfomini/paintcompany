<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineStage extends Model
{
    use HasFactory;

    protected $table = 'prospector_pipeline_stages';

    protected $fillable = [
        'pipeline_id',
        'name',
        'color',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'pipeline_stage_id');
    }
}
