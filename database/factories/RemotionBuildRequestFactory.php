<?php

namespace Database\Factories;

use App\Models\RemotionBuildRequest;
use App\Models\User;
use App\Models\VideoRecording;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RemotionBuildRequest>
 */
class RemotionBuildRequestFactory extends Factory
{
    protected $model = RemotionBuildRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'prompt' => fake()->paragraph(),
            'status' => 'pending',
        ];
    }

    public function building(): static
    {
        return $this->state(fn () => [
            'status' => 'building',
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'video_recording_id' => VideoRecording::factory()->ready(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'error_message' => 'Build failed: timeout',
            'completed_at' => now(),
        ]);
    }
}
