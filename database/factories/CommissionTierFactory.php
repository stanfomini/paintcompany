<?php

namespace Database\Factories;

use App\Models\CommissionTier;
use App\Models\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionTier>
 */
class CommissionTierFactory extends Factory
{
    protected $model = CommissionTier::class;

    public function definition(): array
    {
        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'threshold_min' => 0,
            'threshold_max' => 10000,
            'rate' => 0.05,
            'period_type' => 'monthly',
            'sort_order' => 0,
        ];
    }

    public function quarterly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => 'quarterly',
        ]);
    }

    public function yearly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_type' => 'yearly',
        ]);
    }
}
