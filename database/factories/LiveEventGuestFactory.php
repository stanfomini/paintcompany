<?php

namespace Database\Factories;

use App\Models\LiveEvent;
use App\Models\LiveEventGuest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LiveEventGuest>
 */
class LiveEventGuestFactory extends Factory
{
    protected $model = LiveEventGuest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'live_event_id' => LiveEvent::factory(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'instructions' => fake()->sentence(),
            'token' => Str::random(64),
            'status' => 'invited',
            'livekit_identity' => 'guest-'.Str::random(8),
        ];
    }

    public function connected(): static
    {
        return $this->state(fn () => [
            'status' => 'connected',
            'connected_at' => now(),
            'slot_number' => fake()->numberBetween(1, 8),
        ]);
    }

    public function live(): static
    {
        return $this->state(fn () => [
            'status' => 'live',
            'connected_at' => now()->subMinutes(10),
            'went_live_at' => now(),
            'slot_number' => fake()->numberBetween(1, 8),
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn () => [
            'status' => 'disconnected',
        ]);
    }
}
