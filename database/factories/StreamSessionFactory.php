<?php

namespace Database\Factories;

use App\Models\StreamSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StreamSession>
 */
class StreamSessionFactory extends Factory
{
    protected $model = StreamSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'room_id' => 'studio-'.$this->faker->unique()->regexify('[a-zA-Z0-9]{12}'),
            'title' => $this->faker->optional()->sentence(3),
            'status' => 'preparing',
            'scenes' => StreamSession::defaultScenes(),
            'current_scene_index' => 0,
            'destinations' => null,
            'overlays' => null,
        ];
    }

    public function live(): static
    {
        return $this->state(fn () => [
            'status' => 'live',
            'started_at' => now()->subMinutes(rand(5, 60)),
        ]);
    }

    public function ended(): static
    {
        $startedAt = now()->subHours(rand(1, 3));
        $duration = rand(300, 7200);

        return $this->state(fn () => [
            'status' => 'ended',
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addSeconds($duration),
            'duration_seconds' => $duration,
        ]);
    }

    public function withDestinations(): static
    {
        return $this->state(fn () => [
            'destinations' => [
                [
                    'id' => 'yt-1',
                    'platform' => 'youtube',
                    'rtmp_url' => 'rtmp://a.rtmp.youtube.com/live2',
                    'stream_key' => $this->faker->regexify('[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}'),
                    'status' => 'idle',
                    'enabled' => true,
                ],
            ],
        ]);
    }
}
