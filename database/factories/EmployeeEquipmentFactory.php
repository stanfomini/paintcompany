<?php

namespace Database\Factories;

use App\Models\EmployeeEquipment;
use App\Models\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeEquipment>
 */
class EmployeeEquipmentFactory extends Factory
{
    protected $model = EmployeeEquipment::class;

    public function definition(): array
    {
        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'name' => fake()->randomElement([
                'Paint Sprayer', 'Ladder (6ft)', 'Ladder (12ft)', 'Drop Cloths Set',
                'Brush Kit', 'Roller Set', 'Safety Harness', 'Respirator Mask',
                'Company Truck Keys', 'Tablet', 'Uniform Set',
            ]),
            'description' => fake()->optional()->sentence(),
            'serial_number' => fake()->optional()->bothify('??-####-##'),
            'assigned_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'returned_at' => null,
            'condition_on_assign' => fake()->randomElement(['new', 'good', 'fair']),
            'condition_on_return' => null,
            'notes' => null,
        ];
    }

    public function returned(): static
    {
        return $this->state(fn (array $attributes) => [
            'returned_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'condition_on_return' => fake()->randomElement(['good', 'fair', 'poor', 'damaged']),
        ]);
    }
}
