<?php

namespace Database\Factories;

use App\Models\EmployeeProfile;
use App\Models\PtoRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PtoRequest>
 */
class PtoRequestFactory extends Factory
{
    protected $model = PtoRequest::class;

    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('+1 week', '+2 months');
        $endDate = (clone $startDate)->modify('+'.fake()->numberBetween(0, 4).' days');

        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'type' => fake()->randomElement(['vacation', 'sick', 'personal']),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'hours' => fake()->randomFloat(1, 4, 40),
            'status' => 'pending',
            'notes' => null,
        ];
    }

    /**
     * Approved PTO request.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_by' => \App\Models\User::factory()->internal(),
            'approved_at' => now(),
        ]);
    }

    /**
     * Denied PTO request.
     */
    public function denied(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'denied',
            'approved_by' => \App\Models\User::factory()->internal(),
            'approved_at' => now(),
            'denial_reason' => fake()->sentence(),
        ]);
    }

    /**
     * Vacation type.
     */
    public function vacation(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'vacation',
        ]);
    }

    /**
     * Sick day.
     */
    public function sick(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'sick',
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'hours' => 8.0,
        ]);
    }

    /**
     * Single day request.
     */
    public function singleDay(): static
    {
        $date = fake()->dateTimeBetween('+1 week', '+1 month');

        return $this->state(fn (array $attributes) => [
            'start_date' => $date,
            'end_date' => $date,
            'hours' => 8.0,
        ]);
    }
}
