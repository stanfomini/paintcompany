<?php

namespace Database\Factories;

use App\Models\PropertyPhoto;
use App\Models\VideoProjectClip;
use App\Models\VideoProjectScene;
use App\Models\VideoRecording;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoProjectClip>
 */
class VideoProjectClipFactory extends Factory
{
    protected $model = VideoProjectClip::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_project_scene_id' => VideoProjectScene::factory(),
            'slot' => 'main',
            'source_type' => VideoRecording::class,
            'source_id' => VideoRecording::factory()->ready(),
            'in_point_ms' => 0,
            'volume' => 100,
            'fit_mode' => 'cover',
            'sort_order' => 0,
        ];
    }

    public function secondary(): static
    {
        return $this->state(fn () => [
            'slot' => 'secondary',
        ]);
    }

    public function pip(): static
    {
        return $this->state(fn () => [
            'slot' => 'pip',
        ]);
    }

    public function fromPhoto(): static
    {
        return $this->state(fn () => [
            'source_type' => PropertyPhoto::class,
            'source_id' => PropertyPhoto::factory(),
        ]);
    }

    public function withTrim(int $in = 0, int $out = 5000): static
    {
        return $this->state(fn () => [
            'in_point_ms' => $in,
            'out_point_ms' => $out,
        ]);
    }

    public function muted(): static
    {
        return $this->state(fn () => [
            'volume' => 0,
        ]);
    }
}
