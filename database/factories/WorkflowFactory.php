<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Workflow>
 */
class WorkflowFactory extends Factory
{
    protected $model = Workflow::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'is_active' => true,
            'is_system' => false,
            'version' => 1,
            'created_by' => User::factory(),
            'published_at' => null,
        ];
    }

    /**
     * Mark the workflow as published.
     */
    public function published(): static
    {
        return $this->state(fn () => [
            'published_at' => now(),
        ]);
    }

    /**
     * Mark the workflow as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    /**
     * Mark the workflow as a system workflow.
     */
    public function system(): static
    {
        return $this->state(fn () => [
            'is_system' => true,
        ]);
    }
}
