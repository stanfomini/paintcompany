<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\JobServiceRequirement;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobServiceRequirement>
 */
class JobServiceRequirementFactory extends Factory
{
    protected $model = JobServiceRequirement::class;

    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'service_category_id' => ServiceCategory::factory()->painting(),
            'scheduled_date' => fake()->dateTimeBetween('+1 week', '+3 months'),
            'required_crew_size' => fake()->numberBetween(1, 8),
            'status' => JobServiceRequirement::STATUS_PENDING,
        ];
    }

    public function dispatched(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => JobServiceRequirement::STATUS_DISPATCHED,
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => JobServiceRequirement::STATUS_ACCEPTED,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => JobServiceRequirement::STATUS_COMPLETED,
        ]);
    }

    public function forCleaning(): static
    {
        return $this->state(fn (array $attributes) => [
            'service_category_id' => ServiceCategory::factory()->cleaning(),
        ]);
    }
}
