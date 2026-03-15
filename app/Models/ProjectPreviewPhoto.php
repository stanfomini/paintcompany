<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class ProjectPreviewPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_preview_id',
        'original_path',
        'disk',
        'room_label',
        'substrate_analysis',
        'condition_notes',
        'mime_type',
        'file_size',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'substrate_analysis' => 'array',
            'sort_order' => 'integer',
            'file_size' => 'integer',
        ];
    }

    public function preview(): BelongsTo
    {
        return $this->belongsTo(ProjectPreview::class, 'project_preview_id');
    }

    public function visualizations(): HasMany
    {
        return $this->hasMany(ProjectPreviewVisualization::class);
    }

    /**
     * Get the URL for the original photo.
     */
    public function getOriginalUrl(): string
    {
        return Storage::disk($this->disk)->url($this->original_path);
    }
}
