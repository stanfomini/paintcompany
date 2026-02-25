<?php

namespace Database\Factories;

use App\Models\VideoProject;
use App\Models\VideoProjectScene;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoProjectScene>
 */
class VideoProjectSceneFactory extends Factory
{
    protected $model = VideoProjectScene::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_project_id' => VideoProject::factory(),
            'sort_order' => 0,
            'layout' => 'solo',
            'transition_type' => 'cut',
            'transition_duration_ms' => 0,
            'background_color' => '#000000',
            'trim_start_ms' => 0,
        ];
    }

    public function split(): static
    {
        return $this->state(fn () => [
            'layout' => 'split',
        ]);
    }

    public function pip(): static
    {
        return $this->state(fn () => [
            'layout' => 'pip',
        ]);
    }

    public function withName(): static
    {
        return $this->state(fn () => [
            'name' => fake()->words(2, true),
        ]);
    }

    public function withDuration(int $ms = 10000): static
    {
        return $this->state(fn () => [
            'duration_ms' => $ms,
        ]);
    }

    public function withTransition(string $type = 'fade', int $duration = 500): static
    {
        return $this->state(fn () => [
            'transition_type' => $type,
            'transition_duration_ms' => $duration,
        ]);
    }
}
