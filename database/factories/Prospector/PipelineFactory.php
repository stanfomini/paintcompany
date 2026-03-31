<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Pipeline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pipeline>
 */
class PipelineFactory extends Factory
{
    protected $model = Pipeline::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Sales Pipeline', 'Follow-Up Pipeline', 'Referral Pipeline']),
            'color' => fake()->hexColor(),
            'sort_order' => 0,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
