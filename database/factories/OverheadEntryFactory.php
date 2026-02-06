<?php

namespace Database\Factories;

use App\Models\BudgetCategory;
use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OverheadEntry>
 */
class OverheadEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_setting_id' => fn () => CompanySetting::getInstance()->id,
            'budget_category_id' => BudgetCategory::factory()->vehicleExpense(),
            'year' => now()->year,
            'month' => fake()->numberBetween(1, 12),
            'amount' => fake()->randomFloat(2, 50, 5000),
            'notes' => null,
        ];
    }

    public function forYear(int $year): static
    {
        return $this->state(fn () => [
            'year' => $year,
        ]);
    }

    public function forMonth(int $month): static
    {
        return $this->state(fn () => [
            'month' => $month,
        ]);
    }

    public function forCategory(BudgetCategory $category): static
    {
        return $this->state(fn () => [
            'budget_category_id' => $category->id,
        ]);
    }
}
