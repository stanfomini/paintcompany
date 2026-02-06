<?php

namespace Database\Factories;

use App\Models\BudgetCategory;
use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BudgetLineItem>
 */
class BudgetLineItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_setting_id' => CompanySetting::getInstance()->id,
            'budget_category_id' => BudgetCategory::factory(),
            'year' => now()->year,
            'prior_year_actual' => 0,
            'current_year_plan' => 0,
            'current_year_actual' => 0,
        ];
    }

    public function forYear(int $year): static
    {
        return $this->state(fn () => [
            'year' => $year,
        ]);
    }

    public function withAmounts(float $priorActual, float $plan, float $currentActual): static
    {
        return $this->state(fn () => [
            'prior_year_actual' => $priorActual,
            'current_year_plan' => $plan,
            'current_year_actual' => $currentActual,
        ]);
    }
}
