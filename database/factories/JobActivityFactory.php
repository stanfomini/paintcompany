<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\JobActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JobActivity>
 */
class JobActivityFactory extends Factory
{
    protected $model = JobActivity::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'user_id' => fake()->optional(0.7)->randomElement([User::factory()->internal()]),
            'activity_type' => fake()->randomElement(array_keys(JobActivity::ACTIVITY_TYPES)),
            'description' => fake()->optional(0.5)->sentence(),
            'metadata' => null,
            'ip_address' => fake()->ipv4(),
        ];
    }

    /**
     * Associate with a specific job.
     */
    public function forJob(Job $job): static
    {
        return $this->state(fn (array $attributes) => [
            'job_id' => $job->id,
        ]);
    }

    /**
     * Associate with a specific user.
     */
    public function byUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Set as anonymous (no user).
     */
    public function anonymous(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }

    public function stageChanged(): static
    {
        return $this->state(fn (array $attributes) => [
            'activity_type' => 'stage_changed',
            'description' => 'Stage changed',
        ]);
    }

    public function dateProposed(): static
    {
        return $this->state(fn (array $attributes) => [
            'activity_type' => 'date_proposed',
            'description' => 'Date proposed',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'activity_type' => 'completed',
            'description' => 'Job marked as completed',
        ]);
    }
}
