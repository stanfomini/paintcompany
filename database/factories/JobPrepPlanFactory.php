<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\JobPrepPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPrepPlan>
 */
class JobPrepPlanFactory extends Factory
{
    protected $model = JobPrepPlan::class;

    public function definition(): array
    {
        return [
            'job_id' => Job::factory()->jobPrep(),
            'crew_size' => fake()->numberBetween(1, 4),
            'hours_per_day' => fake()->randomElement([6, 8, 10]),
            'total_days' => fake()->numberBetween(1, 5),
            'total_labor_hours' => fake()->randomFloat(1, 4, 80),
            'total_cleanup_hours' => fake()->randomFloat(1, 0.5, 8),
        ];
    }

    /**
     * All three parties approved.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'team_approved' => true,
            'crew_approved' => true,
            'customer_approved' => true,
            'team_approved_at' => now(),
            'crew_approved_at' => now(),
            'customer_approved_at' => now(),
            'team_approved_by' => User::factory()->internal()->withFullAccess(),
            'crew_approved_by' => User::factory()->crew(),
            'customer_approved_by' => User::factory()->customer(),
        ]);
    }

    /**
     * Team approved only.
     */
    public function teamApproved(): static
    {
        return $this->state(fn (array $attributes) => [
            'team_approved' => true,
            'team_approved_at' => now(),
            'team_approved_by' => User::factory()->internal()->withFullAccess(),
        ]);
    }

    /**
     * Crew approved only.
     */
    public function crewApproved(): static
    {
        return $this->state(fn (array $attributes) => [
            'crew_approved' => true,
            'crew_approved_at' => now(),
            'crew_approved_by' => User::factory()->crew(),
        ]);
    }

    /**
     * Customer approved only.
     */
    public function customerApproved(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_approved' => true,
            'customer_approved_at' => now(),
            'customer_approved_by' => User::factory()->customer(),
        ]);
    }
}
