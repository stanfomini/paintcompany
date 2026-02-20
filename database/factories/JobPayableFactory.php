<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\JobPayable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPayable>
 */
class JobPayableFactory extends Factory
{
    protected $model = JobPayable::class;

    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'payee_type' => JobPayable::PAYEE_TYPE_CREW,
            'payee_name' => fake()->name(),
            'category' => fake()->randomElement(array_keys(JobPayable::CATEGORIES)),
            'description' => fake()->sentence(),
            'amount' => fake()->randomFloat(2, 50, 5000),
            'status' => JobPayable::STATUS_PENDING,
            'created_by' => User::factory(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => JobPayable::STATUS_PENDING,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => JobPayable::STATUS_APPROVED,
            'approved_by' => User::factory(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => JobPayable::STATUS_PAID,
            'paid_at' => now(),
            'paid_via' => fake()->randomElement(array_keys(JobPayable::PAYMENT_METHODS)),
            'reference_number' => fake()->bothify('REF-####'),
        ]);
    }

    public function forCrew(): static
    {
        return $this->state(fn (array $attributes) => [
            'payee_type' => JobPayable::PAYEE_TYPE_CREW,
            'payee_user_id' => User::factory()->crew(),
        ]);
    }

    public function forVendor(): static
    {
        return $this->state(fn (array $attributes) => [
            'payee_type' => JobPayable::PAYEE_TYPE_VENDOR,
            'vendor_id' => \App\Models\Vendor::factory(),
        ]);
    }

    public function forEmployee(): static
    {
        return $this->state(fn (array $attributes) => [
            'payee_type' => JobPayable::PAYEE_TYPE_EMPLOYEE,
            'payee_user_id' => User::factory()->internal(),
        ]);
    }
}
