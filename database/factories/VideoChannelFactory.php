<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VideoChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoChannel>
 */
class VideoChannelFactory extends Factory
{
    protected $model = VideoChannel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional(0.7)->sentence(),
            'visibility' => 'public',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function public(): static
    {
        return $this->state(fn () => [
            'visibility' => 'public',
        ]);
    }

    public function private(): static
    {
        return $this->state(fn () => [
            'visibility' => 'private',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
