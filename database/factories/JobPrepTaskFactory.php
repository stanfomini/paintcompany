<?php

namespace Database\Factories;

use App\Models\JobPrepDay;
use App\Models\JobPrepPlan;
use App\Models\JobPrepTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPrepTask>
 */
class JobPrepTaskFactory extends Factory
{
    protected $model = JobPrepTask::class;

    public function definition(): array
    {
        return [
            'job_prep_day_id' => JobPrepDay::factory(),
            'job_prep_plan_id' => JobPrepPlan::factory(),
            'task_type' => JobPrepTask::TYPE_CUSTOM,
            'title' => fake()->sentence(4),
            'estimated_hours' => fake()->randomFloat(1, 0.5, 8),
            'status' => JobPrepTask::STATUS_PENDING,
            'sort_order' => 0,
        ];
    }

    /**
     * Surface-linked task.
     */
    public function surface(): static
    {
        return $this->state(fn (array $attributes) => [
            'task_type' => JobPrepTask::TYPE_SURFACE,
            'area_name' => fake()->randomElement(['Living Room', 'Bedroom', 'Kitchen']),
            'surface_name' => fake()->randomElement(['Walls', 'Ceiling', 'Baseboards']),
            'quantity' => fake()->randomFloat(0, 100, 500),
            'unit' => fake()->randomElement(['sqft', 'lnft', 'unit']),
            'coats' => fake()->numberBetween(1, 3),
        ]);
    }

    /**
     * Addon-linked task.
     */
    public function addon(): static
    {
        return $this->state(fn (array $attributes) => [
            'task_type' => JobPrepTask::TYPE_ADDON,
            'area_name' => fake()->randomElement(['Bedroom', 'Master Bedroom']),
            'surface_name' => fake()->randomElement(['Walk-in Closet', 'Linen Closet']),
        ]);
    }

    /**
     * Completed task.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => JobPrepTask::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }
}
