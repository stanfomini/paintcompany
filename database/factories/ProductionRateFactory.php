<?php

namespace Database\Factories;

use App\Models\ProductionRateCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductionRate>
 */
class ProductionRateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $scope = fake()->randomElement(['interior', 'exterior']);
        $surfaceCategory = $scope === 'interior'
            ? fake()->randomElement(['prep', 'walls', 'ceiling', 'baseboard', 'doors'])
            : fake()->randomElement(['prep', 'body', 'eaves', 'fascia', 'windows', 'trim', 'exterior_doors', 'wood_replacement']);

        return [
            'category_id' => ProductionRateCategory::factory(),
            'scope' => $scope,
            'surface_category' => $surfaceCategory,
            'spreadsheet_name' => null,
            'name' => fake()->randomElement([
                'Interior Wall Painting',
                'Ceiling Painting',
                'Trim & Baseboard',
                'Door Painting',
                'Cabinet Painting',
                'Exterior Siding',
                'Deck Staining',
                'Fence Painting',
            ]),
            'description' => fake()->optional(0.7)->sentence(10),
            'unit_type' => fake()->randomElement(['sqft', 'lnft', 'unit']),
            'production_rate' => fake()->randomFloat(2, 100, 400),
            'rate_type' => fake()->randomElement(['sqft_hr', 'lnft_hr', 'units_hr']),
            'coat_multipliers' => [
                '1' => 1.0,
                '2' => 1.8,
                '3' => 2.5,
            ],
            'default_coats' => 2,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * Associate with a specific category.
     */
    public function forCategory(ProductionRateCategory $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => $category->id,
        ]);
    }

    /**
     * Set the production rate as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set as interior scope.
     */
    public function interior(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => 'interior',
            'surface_category' => fake()->randomElement(['prep', 'walls', 'ceiling', 'baseboard', 'doors']),
        ]);
    }

    /**
     * Set as exterior scope.
     */
    public function exterior(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => 'exterior',
            'surface_category' => fake()->randomElement(['prep', 'body', 'eaves', 'fascia', 'windows', 'trim', 'exterior_doors', 'wood_replacement']),
        ]);
    }

    /**
     * Set specific surface category.
     */
    public function surfaceCategory(string $category): static
    {
        return $this->state(fn (array $attributes) => [
            'surface_category' => $category,
        ]);
    }

    /**
     * Set as hours per unit rate type (for doors).
     */
    public function hoursPerUnit(): static
    {
        return $this->state(fn (array $attributes) => [
            'unit_type' => 'unit',
            'rate_type' => 'hours_per_unit',
            'production_rate' => fake()->randomFloat(2, 0.5, 4),
        ]);
    }

    /**
     * Set as cost per linear foot rate type (for wood replacement).
     */
    public function costPerLinearFoot(): static
    {
        return $this->state(fn (array $attributes) => [
            'unit_type' => 'lnft',
            'rate_type' => 'cost_per_lnft',
            'production_rate' => fake()->randomFloat(2, 5, 20),
        ]);
    }
}
