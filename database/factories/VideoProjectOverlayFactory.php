<?php

namespace Database\Factories;

use App\Models\VideoProjectOverlay;
use App\Models\VideoProjectScene;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoProjectOverlay>
 */
class VideoProjectOverlayFactory extends Factory
{
    protected $model = VideoProjectOverlay::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_project_scene_id' => VideoProjectScene::factory(),
            'type' => 'text',
            'content' => fake()->sentence(),
            'position_x' => 0.5,
            'position_y' => 0.5,
            'sort_order' => 0,
            'start_ms' => 0,
        ];
    }

    public function caption(): static
    {
        return $this->state(fn () => [
            'type' => 'caption',
            'position_x' => 0.1,
            'position_y' => 0.85,
            'width' => 0.8,
            'style' => [
                'font_size' => 24,
                'color' => '#FFFFFF',
                'bg_color' => 'rgba(0,0,0,0.7)',
            ],
        ]);
    }

    public function image(): static
    {
        return $this->state(fn () => [
            'type' => 'image',
            'content' => 'https://example.com/image.png',
        ]);
    }

    public function withTiming(int $start, int $end): static
    {
        return $this->state(fn () => [
            'start_ms' => $start,
            'end_ms' => $end,
        ]);
    }

    /**
     * @param  array<string, mixed>  $style
     */
    public function withStyle(array $style): static
    {
        return $this->state(fn () => [
            'style' => $style,
        ]);
    }
}
