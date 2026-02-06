<?php

namespace Database\Factories;

use App\Models\CrewProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CrewProfile>
 */
class CrewProfileFactory extends Factory
{
    protected $model = CrewProfile::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->crew(),
            'company_name' => fake()->company(),
            'phone' => fake()->phoneNumber(),
            'crew_size' => fake()->numberBetween(1, 8),
            'specialties' => fake()->randomElements(['interior', 'exterior', 'deck', 'cabinet', 'commercial'], rand(1, 3)),
            'hourly_rate' => fake()->randomFloat(2, 25, 75),
            'bio' => fake()->optional()->sentence(),
            'insurance_company' => fake()->optional()->company(),
            'policy_number' => fake()->optional()->uuid(),
            'insurance_start_date' => fake()->optional()->dateTimeBetween('-1 year', '-1 month'),
            'insurance_expiration_date' => fake()->optional()->dateTimeBetween('+1 month', '+1 year'),
            'insurance_photo' => null,
            'insurance_photo_type' => null,
            'workers_comp_carrier' => fake()->optional()->company(),
            'workers_comp_policy_number' => fake()->optional()->uuid(),
            'workers_comp_start_date' => fake()->optional()->dateTimeBetween('-1 year', '-1 month'),
            'workers_comp_expiration_date' => fake()->optional()->dateTimeBetween('+1 month', '+1 year'),
            'workers_comp_document' => null,
            'workers_comp_document_type' => null,
            'w9_photo' => null,
            'w9_photo_type' => null,
            'w9_received_date' => fake()->optional()->dateTimeBetween('-1 year', 'now'),
            'license_number' => fake()->optional()->bothify('LIC-####-????'),
            'license_expiration_date' => fake()->optional()->dateTimeBetween('+1 month', '+2 years'),
            'notes' => null,
        ];
    }

    /**
     * Crew with all compliance documents in good standing.
     */
    public function compliant(): static
    {
        return $this->state(fn (array $attributes) => [
            'insurance_company' => fake()->company(),
            'policy_number' => fake()->uuid(),
            'insurance_start_date' => now()->subMonths(6),
            'insurance_expiration_date' => now()->addMonths(6),
            'w9_received_date' => now()->subMonths(3),
            'workers_comp_carrier' => fake()->company(),
            'workers_comp_policy_number' => fake()->uuid(),
            'workers_comp_start_date' => now()->subMonths(6),
            'workers_comp_expiration_date' => now()->addMonths(6),
            'license_number' => fake()->bothify('LIC-####-????'),
            'license_expiration_date' => now()->addYear(),
        ]);
    }

    /**
     * Crew with insurance expiring soon (within 30 days).
     */
    public function insuranceExpiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'insurance_company' => fake()->company(),
            'policy_number' => fake()->uuid(),
            'insurance_start_date' => now()->subMonths(11),
            'insurance_expiration_date' => now()->addDays(15),
        ]);
    }

    /**
     * Crew with expired insurance.
     */
    public function insuranceExpired(): static
    {
        return $this->state(fn (array $attributes) => [
            'insurance_company' => fake()->company(),
            'policy_number' => fake()->uuid(),
            'insurance_start_date' => now()->subMonths(14),
            'insurance_expiration_date' => now()->subDays(30),
        ]);
    }

    /**
     * Crew with license expiring soon (within 30 days).
     */
    public function licenseExpiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'license_number' => fake()->bothify('LIC-####-????'),
            'license_expiration_date' => now()->addDays(20),
        ]);
    }

    /**
     * Crew with expired license.
     */
    public function licenseExpired(): static
    {
        return $this->state(fn (array $attributes) => [
            'license_number' => fake()->bothify('LIC-####-????'),
            'license_expiration_date' => now()->subDays(15),
        ]);
    }

    /**
     * Crew with workers' comp expiring soon (within 30 days).
     */
    public function workersCompExpiringSoon(): static
    {
        return $this->state(fn (array $attributes) => [
            'workers_comp_carrier' => fake()->company(),
            'workers_comp_policy_number' => fake()->uuid(),
            'workers_comp_start_date' => now()->subMonths(11),
            'workers_comp_expiration_date' => now()->addDays(15),
        ]);
    }

    /**
     * Crew with expired workers' comp.
     */
    public function workersCompExpired(): static
    {
        return $this->state(fn (array $attributes) => [
            'workers_comp_carrier' => fake()->company(),
            'workers_comp_policy_number' => fake()->uuid(),
            'workers_comp_start_date' => now()->subMonths(14),
            'workers_comp_expiration_date' => now()->subDays(30),
        ]);
    }
}
