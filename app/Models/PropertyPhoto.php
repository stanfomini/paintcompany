<?php

namespace App\Models;

use App\Models\Concerns\HasStars;
use App\Services\PhotoUrlService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class PropertyPhoto extends Model
{
    use HasFactory, HasStars;

    public const CATEGORIES = [
        'before' => 'Before',
        'during' => 'During',
        'after' => 'After',
        'reference' => 'Reference',
        'general' => 'General',
    ];

    protected $fillable = [
        'property_id',
        'uploaded_by',
        'path',
        'filename',
        'caption',
        'category',
        'sort_order',
        'disk',
        'original_path',
        'thumbnail_path',
        'medium_path',
        'annotated_path',
        'original_filename',
        'mime_type',
        'file_size',
        'width',
        'height',
        'show_on_proposal',
        'estimate_id',
        'estimate_area_id',
        'job_id',
        'processing_status',
        'taken_at',
        'latitude',
        'longitude',
        'content_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'show_on_proposal' => 'boolean',
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'taken_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'content_updated_at' => 'datetime',
        ];
    }

    /**
     * Get the property that owns this photo.
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Get the user who uploaded this photo.
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get the estimate this photo is associated with.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the job this photo is associated with.
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id');
    }

    /**
     * Get the estimate area this photo is associated with.
     */
    public function estimateArea(): BelongsTo
    {
        return $this->belongsTo(EstimateArea::class);
    }

    /**
     * Get all annotations for this photo (one per author_type).
     */
    public function annotations(): HasMany
    {
        return $this->hasMany(PhotoAnnotation::class, 'property_photo_id');
    }

    /**
     * Get a single annotation (backwards compat - returns first/latest).
     */
    public function annotation(): HasOne
    {
        return $this->hasOne(PhotoAnnotation::class, 'property_photo_id')->latestOfMany();
    }

    /**
     * Get annotation for a specific author type.
     */
    public function annotationForType(string $authorType): ?PhotoAnnotation
    {
        return $this->annotations->firstWhere('author_type', $authorType);
    }

    /**
     * Get author types that have annotations on this photo.
     */
    public function getAnnotationTypesAttribute(): array
    {
        return $this->annotations->pluck('author_type')->unique()->values()->all();
    }

    /**
     * Check if photo has any annotations.
     */
    public function getHasAnnotationsAttribute(): bool
    {
        return $this->annotations->isNotEmpty();
    }

    /**
     * Get the comments for this photo.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(PhotoComment::class, 'property_photo_id');
    }

    /**
     * Get view tracking records for this photo.
     */
    public function views(): HasMany
    {
        return $this->hasMany(PhotoView::class, 'property_photo_id');
    }

    /**
     * Check if a user has unviewed changes on this photo.
     */
    public function hasUnviewedChanges(?User $user): bool
    {
        if (! $user || ! $this->content_updated_at) {
            return false;
        }

        $view = $this->views->firstWhere('user_id', $user->id);

        if (! $view) {
            return true;
        }

        return $this->content_updated_at->isAfter($view->viewed_at);
    }

    /**
     * Get the category label.
     */
    public function getCategoryLabelAttribute(): string
    {
        return __(self::CATEGORIES[$this->category] ?? $this->category);
    }

    /**
     * Get the URL for this photo.
     */
    public function getUrlAttribute(): string
    {
        return app(PhotoUrlService::class)->getUrl($this, 'original') ?? '';
    }

    /**
     * Get the display URL, preferring the annotated version when available.
     */
    public function getDisplayUrlAttribute(): string
    {
        if ($this->annotated_path) {
            return app(PhotoUrlService::class)->getUrl($this, 'annotated') ?? $this->url;
        }

        return $this->url;
    }

    /**
     * Get the full path to the photo.
     */
    public function getFullPathAttribute(): string
    {
        $disk = $this->disk ?? config('filesystems.default');

        return Storage::disk($disk)->path($this->path);
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
     * Scope to get during photos.
     */
    public function scopeDuring($query)
    {
        return $query->where('category', 'during');
    }

    /**
     * Scope to get reference photos.
     */
    public function scopeReference($query)
    {
        return $query->where('category', 'reference');
    }

    /**
     * Scope to filter photos for a specific estimate.
     */
    public function scopeForEstimate($query, int $id)
    {
        return $query->where('estimate_id', $id);
    }

    /**
     * Scope to filter photos for a specific job.
     */
    public function scopeForJob($query, int $id)
    {
        return $query->where('job_id', $id);
    }

    /**
     * Scope to filter photos shown on proposal.
     */
    public function scopeForProposal($query)
    {
        return $query->where('show_on_proposal', true);
    }

    /**
     * Delete all storage variants when the model is deleted.
     */
    protected static function booted(): void
    {
        static::deleting(function (PropertyPhoto $photo) {
            $disk = $photo->disk ?? config('filesystems.default');
            $storage = Storage::disk($disk);

            $paths = array_filter([
                $photo->path,
                $photo->original_path,
                $photo->thumbnail_path,
                $photo->medium_path,
                $photo->annotated_path,
            ]);

            foreach ($paths as $path) {
                if ($storage->exists($path)) {
                    $storage->delete($path);
                }
            }
        });
    }
}
