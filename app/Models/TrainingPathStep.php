<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingPathStep extends Model
{
    /** @use HasFactory<\Database\Factories\TrainingPathStepFactory> */
    use HasFactory;

    protected $fillable = [
        'training_path_id',
        'video_recording_id',
        'title',
        'description',
        'sort_order',
        'is_required',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_required' => 'boolean',
        ];
    }

    public function path(): BelongsTo
    {
        return $this->belongsTo(TrainingPath::class, 'training_path_id');
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(VideoRecording::class, 'video_recording_id');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(TrainingProgress::class);
    }
}
