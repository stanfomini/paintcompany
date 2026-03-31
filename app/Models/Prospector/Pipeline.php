<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Pipeline extends Model
{
    use HasFactory;

    protected $table = 'prospector_pipelines';

    protected $fillable = [
        'name',
        'color',
        'sort_order',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class, 'pipeline_id');
    }

    public function contacts(): HasManyThrough
    {
        return $this->hasManyThrough(
            Contact::class,
            PipelineStage::class,
            'pipeline_id',
            'pipeline_stage_id',
            'id',
            'id'
        );
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeDefault(Builder $query): void
    {
        $query->where('is_default', true);
    }
}
