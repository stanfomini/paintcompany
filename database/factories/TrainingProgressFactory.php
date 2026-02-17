<?php

namespace Database\Factories;

use App\Models\TrainingPathStep;
use App\Models\TrainingProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingProgress>
 */
class TrainingProgressFactory extends Factory
{
    protected $model = TrainingProgress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'training_path_step_id' => TrainingPathStep::factory(),
            'watch_percentage' => fake()->numberBetween(0, 90),
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'watch_percentage' => 100,
            'completed_at' => now(),
        ]);
    }
}
