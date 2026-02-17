<?php

namespace Database\Factories;

use App\Models\EmployeeDeduction;
use App\Models\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeDeduction>
 */
class EmployeeDeductionFactory extends Factory
{
    protected $model = EmployeeDeduction::class;

    public function definition(): array
    {
        $type = fake()->randomElement(['health_insurance', 'dental', 'vision', '401k', 'hsa']);

        $descriptions = [
            'health_insurance' => 'Health Insurance Premium',
            'dental' => 'Dental Insurance',
            'vision' => 'Vision Insurance',
            '401k' => '401(k) Contribution',
            'hsa' => 'HSA Contribution',
        ];

        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'type' => $type,
            'description' => $descriptions[$type] ?? fake()->sentence(3),
            'amount' => fake()->randomFloat(2, 25, 500),
            'is_percentage' => false,
            'is_pretax' => true,
            'is_active' => true,
        ];
    }

    /**
     * Percentage-based deduction (e.g., 401k at 6%).
     */
    public function percentage(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => '401k',
            'description' => '401(k) Contribution',
            'amount' => fake()->randomFloat(2, 1, 15),
            'is_percentage' => true,
            'is_pretax' => true,
        ]);
    }

    /**
     * Post-tax deduction.
     */
    public function posttax(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => fake()->randomElement(['garnishment', 'child_support', 'other']),
            'is_pretax' => false,
        ]);
    }

    /**
     * Inactive deduction.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'end_date' => fake()->dateTimeBetween('-6 months', 'now'),
        ]);
    }

    /**
     * Garnishment deduction.
     */
    public function garnishment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'garnishment',
            'description' => 'Wage Garnishment',
            'amount' => fake()->randomFloat(2, 100, 500),
            'is_pretax' => false,
        ]);
    }
}
