<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Job>
 */
class JobFactory extends Factory
{
    protected $model = Job::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'estimate_id' => null,
            'job_number' => Job::generateJobNumber(),
            'stage' => 'estimate_accepted',
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'required_crew_size' => fake()->numberBetween(1, 6),
        ];
    }

    /**
     * Set the job to scheduling_colors stage.
     */
    public function schedulingColors(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'scheduling_colors',
        ]);
    }

    /**
     * Set the job to job_prep stage.
     */
    public function jobPrep(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'job_prep',
            'scheduled_date' => fake()->dateTimeBetween('+1 week', '+1 month'),
            'company_date_confirmed' => true,
            'customer_date_confirmed' => true,
            'company_colors_confirmed' => true,
            'customer_colors_confirmed' => true,
        ]);
    }

    /**
     * Set the job to in_progress stage.
     */
    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'in_progress',
            'scheduled_date' => fake()->dateTimeBetween('-1 week', 'now'),
            'actual_start_date' => fake()->dateTimeBetween('-1 week', 'now'),
            'company_date_confirmed' => true,
            'customer_date_confirmed' => true,
            'company_colors_confirmed' => true,
            'customer_colors_confirmed' => true,
        ]);
    }

    /**
     * Set the job to completed stage.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'completed',
            'scheduled_date' => fake()->dateTimeBetween('-1 month', '-1 week'),
            'actual_start_date' => fake()->dateTimeBetween('-1 month', '-1 week'),
            'actual_end_date' => fake()->dateTimeBetween('-1 week', 'now'),
            'company_date_confirmed' => true,
            'customer_date_confirmed' => true,
            'company_colors_confirmed' => true,
            'customer_colors_confirmed' => true,
        ]);
    }

    /**
     * Set the job as ready for dispatch.
     */
    public function readyForDispatch(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'scheduling_colors',
            'scheduled_date' => fake()->dateTimeBetween('+1 week', '+1 month'),
            'company_date_confirmed' => true,
            'customer_date_confirmed' => true,
            'company_colors_confirmed' => true,
            'customer_colors_confirmed' => true,
        ]);
    }
}
