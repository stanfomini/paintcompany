<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRecording;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VideoProject>
 */
class VideoProjectFactory extends Factory
{
    protected $model = VideoProject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'status' => 'draft',
            'resolution_width' => 1920,
            'resolution_height' => 1080,
            'fps' => 30,
            'share_token' => Str::random(32),
        ];
    }

    public function rendering(): static
    {
        return $this->state(fn () => [
            'status' => 'rendering',
        ]);
    }

    public function rendered(): static
    {
        return $this->state(fn () => [
            'status' => 'rendered',
            'duration_seconds' => fake()->randomFloat(2, 10, 300),
            'video_recording_id' => VideoRecording::factory()->ready(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
        ]);
    }

    public function withDescription(): static
    {
        return $this->state(fn () => [
            'description' => fake()->paragraph(),
        ]);
    }
}
