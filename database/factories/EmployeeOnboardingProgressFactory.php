<?php

namespace Database\Factories;

use App\Models\EmployeeContract;
use App\Models\EmployeeOnboardingProgress;
use App\Models\EmployeeProfile;
use App\Models\OnboardingChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeOnboardingProgress>
 */
class EmployeeOnboardingProgressFactory extends Factory
{
    protected $model = EmployeeOnboardingProgress::class;

    public function definition(): array
    {
        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'onboarding_checklist_item_id' => OnboardingChecklistItem::factory(),
            'status' => 'pending',
            'completed_at' => null,
            'completed_by' => null,
            'notes' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => fake()->dateTimeBetween('-1 month', 'now'),
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'skipped',
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'in_progress',
        ]);
    }

    public function withContract(): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_contract_id' => EmployeeContract::factory(),
        ]);
    }
}
