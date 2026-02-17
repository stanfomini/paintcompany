<?php

namespace Database\Factories;

use App\Models\EmployeeAvailability;
use App\Models\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeAvailability>
 */
class EmployeeAvailabilityFactory extends Factory
{
    protected $model = EmployeeAvailability::class;

    public function definition(): array
    {
        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'available_from' => '07:00',
            'available_to' => '17:00',
            'is_available' => true,
        ];
    }

    /**
     * Unavailable day.
     */
    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_available' => false,
            'available_from' => null,
            'available_to' => null,
        ]);
    }

    /**
     * Weekend day (Saturday or Sunday).
     */
    public function weekend(): static
    {
        return $this->state(fn (array $attributes) => [
            'day_of_week' => fake()->randomElement([0, 6]),
            'is_available' => false,
            'available_from' => null,
            'available_to' => null,
        ]);
    }

    /**
     * For a specific day of the week.
     */
    public function forDay(int $day): static
    {
        return $this->state(fn (array $attributes) => [
            'day_of_week' => $day,
        ]);
    }
}
