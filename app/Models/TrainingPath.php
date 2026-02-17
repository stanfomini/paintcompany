<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingPath extends Model
{
    /** @use HasFactory<\Database\Factories\TrainingPathFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'visibility',
        'is_required',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(TrainingPathStep::class)->orderBy('sort_order');
    }

    public function getCompletionPercentageForUser(User $user): float
    {
        $steps = $this->steps()->where('is_required', true)->get();

        if ($steps->isEmpty()) {
            return 100.0;
        }

        $completedCount = TrainingProgress::query()
            ->whereIn('training_path_step_id', $steps->pluck('id'))
            ->where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->count();

        return round(($completedCount / $steps->count()) * 100, 1);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
