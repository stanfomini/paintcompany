<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EstimatePhoto extends Model
{
    use HasFactory;

    public const CATEGORIES = [
        'before' => 'Before',
        'during' => 'During',
        'reference' => 'Reference',
        'other' => 'Other',
    ];

    protected $fillable = [
        'estimate_id',
        'area_id',
        'uploaded_by',
        'path',
        'filename',
        'caption',
        'category',
        'show_on_proposal',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'show_on_proposal' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the estimate that owns this photo.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the area this photo is associated with.
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(EstimateArea::class, 'area_id');
    }

    /**
     * Get the user who uploaded this photo.
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the category label.
     */
    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /**
     * Get the URL for this photo.
     */
    public function getUrlAttribute(): string
    {
        return Storage::url($this->path);
    }

    /**
     * Scope to filter photos shown on proposal.
     */
    public function scopeForProposal($query)
    {
        return $query->where('show_on_proposal', true);
    }

    /**
     * Scope to filter by category.
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Delete the photo file when the model is deleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (EstimatePhoto $photo) {
            if (Storage::exists($photo->path)) {
                Storage::delete($photo->path);
            }
        });
    }
}
