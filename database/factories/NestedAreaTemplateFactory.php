<?php

namespace Database\Factories;

use App\Models\NestedAreaTemplate;
use App\Models\PaintProduct;
use App\Models\Substrate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\NestedAreaTemplate>
 */
class NestedAreaTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fixedPrice = fake()->randomFloat(2, 75, 350);

        return [
            'name' => fake()->words(2, true),
            'code' => fake()->unique()->lexify('NEST-???'),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement([
                NestedAreaTemplate::CATEGORY_CLOSET,
                NestedAreaTemplate::CATEGORY_BATHROOM,
                NestedAreaTemplate::CATEGORY_UTILITY,
                NestedAreaTemplate::CATEGORY_OTHER,
            ]),
            'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
            'fixed_price' => $fixedPrice,
            // Backend reporting fields (not included in customer totals)
            'estimated_hours' => round($fixedPrice / 50, 2),  // Rough estimate: ~$50/hr
            'material_cost' => round($fixedPrice * 0.15, 2),  // ~15% of price for materials
            'include_in_package_pricing' => true,
            'default_length' => fake()->randomFloat(2, 3, 10),
            'default_width' => fake()->randomFloat(2, 3, 8),
            'default_height' => fake()->randomFloat(2, 8, 10),
            'includes_walls' => true,
            'includes_ceiling' => fake()->boolean(70),
            'includes_trim' => fake()->boolean(50),
            'includes_door' => fake()->boolean(30),
            'production_rate_id' => null,
            'substrate_id' => null,
            'default_paint_product_id' => null,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    /**
     * Small closet template.
     */
    public function smallCloset(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Small Closet',
            'code' => 'CLOSET-SM',
            'description' => 'Small closet (reach-in, up to 24 sq ft)',
            'category' => NestedAreaTemplate::CATEGORY_CLOSET,
            'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
            'fixed_price' => 95.00,
            'estimated_hours' => 1.5,
            'material_cost' => 12.00,
            'include_in_package_pricing' => true,
            'default_length' => 4.0,
            'default_width' => 2.5,
            'default_height' => 8.0,
            'includes_walls' => true,
            'includes_ceiling' => true,
            'includes_trim' => false,
            'includes_door' => false,
            'sort_order' => 1,
        ]);
    }

    /**
     * Medium closet template.
     */
    public function mediumCloset(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Medium Closet',
            'code' => 'CLOSET-MD',
            'description' => 'Medium closet (walk-in, 25-50 sq ft)',
            'category' => NestedAreaTemplate::CATEGORY_CLOSET,
            'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
            'fixed_price' => 165.00,
            'include_in_package_pricing' => true,
            'default_length' => 6.0,
            'default_width' => 5.0,
            'default_height' => 8.0,
            'includes_walls' => true,
            'includes_ceiling' => true,
            'includes_trim' => false,
            'includes_door' => false,
            'sort_order' => 2,
        ]);
    }

    /**
     * Large closet template.
     */
    public function largeCloset(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Large Closet',
            'code' => 'CLOSET-LG',
            'description' => 'Large closet (master walk-in, 50+ sq ft)',
            'category' => NestedAreaTemplate::CATEGORY_CLOSET,
            'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
            'fixed_price' => 245.00,
            'include_in_package_pricing' => true,
            'default_length' => 8.0,
            'default_width' => 6.0,
            'default_height' => 8.0,
            'includes_walls' => true,
            'includes_ceiling' => true,
            'includes_trim' => false,
            'includes_door' => false,
            'sort_order' => 3,
        ]);
    }

    /**
     * Half bathroom template.
     */
    public function halfBath(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Half Bath',
            'code' => 'BATH-HALF',
            'description' => 'Half bathroom (powder room)',
            'category' => NestedAreaTemplate::CATEGORY_BATHROOM,
            'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
            'fixed_price' => 175.00,
            'include_in_package_pricing' => true,
            'default_length' => 5.0,
            'default_width' => 4.0,
            'default_height' => 8.0,
            'includes_walls' => true,
            'includes_ceiling' => true,
            'includes_trim' => true,
            'includes_door' => false,
            'sort_order' => 10,
        ]);
    }

    /**
     * Laundry room template.
     */
    public function laundryRoom(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Laundry Room',
            'code' => 'UTIL-LAUND',
            'description' => 'Standard laundry room',
            'category' => NestedAreaTemplate::CATEGORY_UTILITY,
            'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
            'fixed_price' => 225.00,
            'include_in_package_pricing' => true,
            'default_length' => 8.0,
            'default_width' => 6.0,
            'default_height' => 8.0,
            'includes_walls' => true,
            'includes_ceiling' => true,
            'includes_trim' => true,
            'includes_door' => false,
            'sort_order' => 20,
        ]);
    }

    /**
     * Pantry template.
     */
    public function pantry(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Pantry',
            'code' => 'UTIL-PANT',
            'description' => 'Kitchen pantry',
            'category' => NestedAreaTemplate::CATEGORY_UTILITY,
            'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
            'fixed_price' => 125.00,
            'include_in_package_pricing' => true,
            'default_length' => 5.0,
            'default_width' => 4.0,
            'default_height' => 8.0,
            'includes_walls' => true,
            'includes_ceiling' => true,
            'includes_trim' => false,
            'includes_door' => false,
            'sort_order' => 21,
        ]);
    }

    /**
     * Closet category.
     */
    public function closet(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => NestedAreaTemplate::CATEGORY_CLOSET,
        ]);
    }

    /**
     * Bathroom category.
     */
    public function bathroom(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => NestedAreaTemplate::CATEGORY_BATHROOM,
        ]);
    }

    /**
     * Utility category.
     */
    public function utility(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => NestedAreaTemplate::CATEGORY_UTILITY,
        ]);
    }

    /**
     * Inactive template.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * With a specific substrate.
     */
    public function withSubstrate(Substrate $substrate): static
    {
        return $this->state(fn (array $attributes) => [
            'substrate_id' => $substrate->id,
        ]);
    }

    /**
     * With a specific paint product.
     */
    public function withPaintProduct(PaintProduct $paintProduct): static
    {
        return $this->state(fn (array $attributes) => [
            'default_paint_product_id' => $paintProduct->id,
        ]);
    }
}
