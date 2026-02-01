<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ListingPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'listing_id',
        'uploaded_by',
        'path',
        'filename',
        'caption',
        'category',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the listing that owns this photo.
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(AgentListing::class, 'listing_id');
    }

    /**
     * Get the user who uploaded this photo.
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the URL for this photo.
     */
    public function getUrlAttribute(): string
    {
        return Storage::url($this->path);
    }

    /**
     * Get the full path to the photo.
     */
    public function getFullPathAttribute(): string
    {
        return Storage::path($this->path);
    }

    /**
     * Scope to filter by category.
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to get before photos.
     */
    public function scopeBefore($query)
    {
        return $query->where('category', 'before');
    }

    /**
     * Scope to get after photos.
     */
    public function scopeAfter($query)
    {
        return $query->where('category', 'after');
    }

    /**
     * Delete the photo file when the model is deleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (ListingPhoto $photo) {
            if (Storage::exists($photo->path)) {
                Storage::delete($photo->path);
            }
        });
    }
}
