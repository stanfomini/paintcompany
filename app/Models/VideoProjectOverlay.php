<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoProjectOverlay extends Model
{
    /** @use HasFactory<\Database\Factories\VideoProjectOverlayFactory> */
    use HasFactory;

    protected $fillable = [
        'video_project_scene_id',
        'type',
        'content',
        'style',
        'position_x',
        'position_y',
        'width',
        'height',
        'start_ms',
        'end_ms',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'style' => 'array',
            'position_x' => 'decimal:4',
            'position_y' => 'decimal:4',
            'width' => 'decimal:4',
            'height' => 'decimal:4',
            'start_ms' => 'integer',
            'end_ms' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(VideoProjectScene::class, 'video_project_scene_id');
    }

    public function isCaption(): bool
    {
        return $this->type === 'caption';
    }

    public function isText(): bool
    {
        return $this->type === 'text';
    }

    public function isImage(): bool
    {
        return $this->type === 'image';
    }
}
