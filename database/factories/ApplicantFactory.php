<?php

namespace Database\Factories;

use App\Models\Applicant;
use App\Models\JobListing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Applicant>
 */
class ApplicantFactory extends Factory
{
    protected $model = Applicant::class;

    public function definition(): array
    {
        return [
            'job_listing_id' => JobListing::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional(0.8)->phoneNumber(),
            'source' => 'web',
            'stage' => 'applied',
        ];
    }

    /**
     * Applicant in screening stage.
     */
    public function screening(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'screening',
        ]);
    }

    /**
     * Applicant who has been hired.
     */
    public function hired(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'hired',
            'hired_at' => now(),
            'offer_amount' => fake()->randomFloat(2, 18, 40),
            'offer_type' => 'hourly',
        ]);
    }

    /**
     * Applicant who has been rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'rejected',
            'rejection_reason' => fake()->sentence(),
        ]);
    }

    /**
     * Applicant with a resume uploaded.
     */
    public function withResume(): static
    {
        return $this->state(fn (array $attributes) => [
            'resume_path' => 'resumes/'.fake()->uuid().'.pdf',
        ]);
    }

    /**
     * General application (no specific job listing).
     */
    public function general(): static
    {
        return $this->state(fn (array $attributes) => [
            'job_listing_id' => null,
        ]);
    }
}
