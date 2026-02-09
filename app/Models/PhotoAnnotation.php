<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoAnnotation extends Model
{
    use HasFactory;

    public const AUTHOR_TYPES = [
        'internal' => 'Team',
        'crew' => 'Crew',
        'customer' => 'Customer',
    ];

    public const AUTHOR_COLORS = [
        'internal' => '#3b82f6',
        'crew' => '#f59e0b',
        'customer' => '#10b981',
    ];

    protected $fillable = [
        'property_photo_id',
        'created_by',
        'author_type',
        'canvas_json',
        'canvas_version',
        'rendered_path',
    ];

    protected function casts(): array
    {
        return [
            'canvas_json' => 'array',
        ];
    }

    /**
     * Get the photo this annotation belongs to.
     */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(PropertyPhoto::class, 'property_photo_id');
    }

    /**
     * Get the user who created this annotation.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the display label for the author type.
     */
    public function getAuthorTypeLabelAttribute(): string
    {
        return self::AUTHOR_TYPES[$this->author_type] ?? ucfirst($this->author_type);
    }

    /**
     * Get the color associated with this author type.
     */
    public function getAuthorColorAttribute(): string
    {
        return self::AUTHOR_COLORS[$this->author_type] ?? '#ff0000';
    }

    /**
     * Scope to a specific author type.
     */
    public function scopeForAuthorType($query, string $authorType)
    {
        return $query->where('author_type', $authorType);
    }

    /**
     * Determine the author type from a User model.
     */
    public static function resolveAuthorType(User $user): string
    {
        return match ($user->type) {
            'internal' => 'internal',
            'crew' => 'crew',
            'customer' => 'customer',
            default => 'internal',
        };
    }
}
