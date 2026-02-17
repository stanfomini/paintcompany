<?php

namespace Database\Factories;

use App\Models\TrainingPath;
use App\Models\TrainingPathStep;
use App\Models\VideoRecording;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingPathStep>
 */
class TrainingPathStepFactory extends Factory
{
    protected $model = TrainingPathStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'training_path_id' => TrainingPath::factory(),
            'video_recording_id' => VideoRecording::factory()->ready(),
            'title' => fake()->optional(0.5)->sentence(3),
            'description' => fake()->optional(0.3)->sentence(),
            'sort_order' => 0,
            'is_required' => true,
        ];
    }

    public function optional(): static
    {
        return $this->state(fn () => [
            'is_required' => false,
        ]);
    }
}
