<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProjectPreviewVisualization extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'project_preview_photo_id',
        'result_image_path',
        'disk',
        'color_name',
        'color_hex',
        'color_brand',
        'is_primary',
        'status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(ProjectPreviewPhoto::class, 'project_preview_photo_id');
    }

    /**
     * Get the URL for the result visualization image.
     */
    public function getResultUrl(): string
    {
        return Storage::disk($this->disk)->url($this->result_image_path);
    }

    /**
     * Mark this visualization as currently processing.
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Mark this visualization as completed with the result path.
     */
    public function markAsCompleted(string $path): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'result_image_path' => $path,
        ]);
    }

    /**
     * Mark this visualization as failed.
     */
    public function markAsFailed(): void
    {
        $this->update(['status' => self::STATUS_FAILED]);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
