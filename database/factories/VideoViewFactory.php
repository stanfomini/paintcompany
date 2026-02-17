<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VideoRecording;
use App\Models\VideoView;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoView>
 */
class VideoViewFactory extends Factory
{
    protected $model = VideoView::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_recording_id' => VideoRecording::factory()->ready(),
            'viewer_id' => User::factory(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'watch_duration_seconds' => fake()->numberBetween(5, 300),
            'watch_percentage' => fake()->numberBetween(10, 100),
            'source' => 'link',
        ];
    }

    public function anonymous(): static
    {
        return $this->state(fn () => [
            'viewer_id' => null,
            'viewer_email' => fake()->safeEmail(),
        ]);
    }

    public function fullyWatched(): static
    {
        return $this->state(fn () => [
            'watch_percentage' => 100,
        ]);
    }

    public function halfWatched(): static
    {
        return $this->state(fn () => [
            'watch_percentage' => 50,
        ]);
    }

    public function fromEmail(): static
    {
        return $this->state(fn () => [
            'source' => 'email',
        ]);
    }

    public function fromChannel(): static
    {
        return $this->state(fn () => [
            'source' => 'channel',
        ]);
    }
}
