<?php

namespace Database\Factories;

use App\Models\LiveEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LiveEvent>
 */
class LiveEventFactory extends Factory
{
    protected $model = LiveEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(4);

        return [
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numerify('###'),
            'description' => fake()->paragraph(),
            'host_user_id' => User::factory(),
            'scheduled_at' => fake()->dateTimeBetween('+1 day', '+30 days'),
            'status' => 'scheduled',
            'access_type' => 'public',
            'max_viewers' => 50,
            'enable_recording' => false,
            'livekit_room_name' => 'event-'.fake()->unique()->uuid(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => 'draft',
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => 'scheduled',
        ]);
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

        return $this->state(fn () => [
            'status' => 'ended',
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addHours(rand(1, 2)),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
        ]);
    }

    public function public(): static
    {
        return $this->state(fn () => [
            'access_type' => 'public',
        ]);
    }

    public function registration(): static
    {
        return $this->state(fn () => [
            'access_type' => 'registration',
        ]);
    }
}
