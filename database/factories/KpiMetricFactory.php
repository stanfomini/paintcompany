<?php

namespace Database\Factories;

use App\Models\KpiCategory;
use App\Models\KpiMetric;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\KpiMetric>
 */
class KpiMetricFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Estimates per Week',
            'Close Rate',
            'Average Estimate Value',
            'Revenue Produced',
            'Gross Profit Margin',
            'Marketing Cost',
            'Overhead Cost',
            'Net Profit',
            'Customer Satisfaction Score',
        ]);

        return [
            'kpi_category_id' => KpiCategory::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->optional()->sentence(),
            'unit' => fake()->randomElement([KpiMetric::UNIT_NUMBER, KpiMetric::UNIT_PERCENT, KpiMetric::UNIT_CURRENCY]),
            'calculation_type' => KpiMetric::CALC_MANUAL,
            'calculation_config' => null,
            'target_value' => fake()->randomFloat(2, 10, 1000),
            'target_period' => fake()->randomElement(['weekly', 'monthly', 'quarterly']),
            'higher_is_better' => true,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }

    public function forCategory(KpiCategory $category): static
    {
        return $this->state(fn (array $attributes) => [
            'kpi_category_id' => $category->id,
        ]);
    }

    public function number(): static
    {
        return $this->state(fn (array $attributes) => [
            'unit' => KpiMetric::UNIT_NUMBER,
        ]);
    }

    public function percent(): static
    {
        return $this->state(fn (array $attributes) => [
            'unit' => KpiMetric::UNIT_PERCENT,
            'target_value' => fake()->randomFloat(1, 10, 100),
        ]);
    }

    public function currency(): static
    {
        return $this->state(fn (array $attributes) => [
            'unit' => KpiMetric::UNIT_CURRENCY,
            'target_value' => fake()->randomFloat(0, 1000, 100000),
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'calculation_type' => KpiMetric::CALC_MANUAL,
        ]);
    }

    public function autoCount(): static
    {
        return $this->state(fn (array $attributes) => [
            'calculation_type' => KpiMetric::CALC_AUTO_COUNT,
        ]);
    }

    public function lowerIsBetter(): static
    {
        return $this->state(fn (array $attributes) => [
            'higher_is_better' => false,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
