<?php

namespace Database\Factories;

use App\Models\LiveEvent;
use App\Models\LiveEventQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LiveEventQuestion>
 */
class LiveEventQuestionFactory extends Factory
{
    protected $model = LiveEventQuestion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'live_event_id' => LiveEvent::factory(),
            'viewer_name' => fake()->name(),
            'question' => fake()->sentence(),
            'status' => 'pending',
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
        ]);
    }

    public function answered(): static
    {
        return $this->state(fn () => [
            'status' => 'answered',
            'answered_at' => now(),
        ]);
    }

    public function dismissed(): static
    {
        return $this->state(fn () => [
            'status' => 'dismissed',
        ]);
    }
}
