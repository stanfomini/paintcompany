<?php

namespace Database\Factories;

use App\Models\BudgetCategory;
use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BudgetCategory>
 */
class BudgetCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'company_setting_id' => CompanySetting::getInstance()->id,
            'section' => fake()->randomElement(BudgetCategory::SECTIONS),
            'name' => $name,
            'slug' => Str::slug($name),
            'sort_order' => 0,
            'is_system' => true,
        ];
    }

    public function section(string $section): static
    {
        return $this->state(fn () => [
            'section' => $section,
        ]);
    }

    public function revenue(): static
    {
        return $this->section(BudgetCategory::SECTION_REVENUE);
    }

    public function variableExpense(): static
    {
        return $this->section(BudgetCategory::SECTION_VARIABLE_EXPENSE);
    }

    public function vehicleExpense(): static
    {
        return $this->section(BudgetCategory::SECTION_VEHICLE_EXPENSE);
    }

    public function businessExpense(): static
    {
        return $this->section(BudgetCategory::SECTION_BUSINESS_EXPENSE);
    }

    public function marketingExpense(): static
    {
        return $this->section(BudgetCategory::SECTION_MARKETING_EXPENSE);
    }
}
