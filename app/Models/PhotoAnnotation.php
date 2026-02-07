<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoAnnotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_photo_id',
        'created_by',
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
}
