<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingProgress extends Model
{
    /** @use HasFactory<\Database\Factories\TrainingProgressFactory> */
    use HasFactory;

    protected $table = 'training_progress';

    protected $fillable = [
        'user_id',
        'training_path_step_id',
        'watch_percentage',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'watch_percentage' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(TrainingPathStep::class, 'training_path_step_id');
    }

    public function markCompleted(): void
    {
        if (! $this->completed_at) {
            $this->update([
                'watch_percentage' => 100,
                'completed_at' => now(),
            ]);
        }
    }
}
