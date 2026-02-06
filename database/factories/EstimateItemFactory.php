<?php

namespace Database\Factories;

use App\Models\Estimate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimateItem>
 */
class EstimateItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(2, 1, 100);
        $unitPrice = fake()->randomFloat(2, 25, 500);

        return [
            'estimate_id' => Estimate::factory(),
            'description' => fake()->sentence(5),
            'details' => fake()->optional(0.3)->sentence(10),
            'quantity' => $quantity,
            'unit' => fake()->randomElement(['each', 'sq ft', 'linear ft', 'hour', 'day', 'gallon']),
            'unit_price' => $unitPrice,
            'total' => $quantity * $unitPrice,
            'cost' => null, // Defaults to total when null
            'sort_order' => 0,
        ];
    }

    /**
     * Set as labor item.
     */
    public function labor(): static
    {
        $hours = fake()->randomFloat(2, 1, 40);
        $rate = fake()->randomFloat(2, 45, 85);

        return $this->state(fn (array $attributes) => [
            'description' => 'Labor - '.fake()->randomElement(['Interior Painting', 'Exterior Painting', 'Prep Work', 'Trim Work', 'Ceiling Work']),
            'quantity' => $hours,
            'unit' => 'hour',
            'unit_price' => $rate,
            'total' => $hours * $rate,
        ]);
    }

    /**
     * Set as material item.
     */
    public function material(): static
    {
        $quantity = fake()->randomFloat(2, 1, 20);
        $price = fake()->randomFloat(2, 30, 150);

        return $this->state(fn (array $attributes) => [
            'description' => fake()->randomElement(['Premium Paint', 'Primer', 'Paint Supplies', 'Brushes & Rollers', 'Drop Cloths', 'Caulk & Filler']),
            'quantity' => $quantity,
            'unit' => 'gallon',
            'unit_price' => $price,
            'total' => $quantity * $price,
        ]);
    }

    /**
     * Set as per-square-foot item.
     */
    public function perSquareFoot(): static
    {
        $sqft = fake()->randomFloat(2, 100, 2000);
        $pricePerSqft = fake()->randomFloat(2, 2, 8);

        return $this->state(fn (array $attributes) => [
            'description' => fake()->randomElement(['Wall Painting', 'Ceiling Painting', 'Surface Preparation', 'Wallpaper Removal']),
            'quantity' => $sqft,
            'unit' => 'sq ft',
            'unit_price' => $pricePerSqft,
            'total' => $sqft * $pricePerSqft,
        ]);
    }

    /**
     * Associate with a specific estimate.
     */
    public function forEstimate(Estimate $estimate): static
    {
        return $this->state(fn (array $attributes) => [
            'estimate_id' => $estimate->id,
        ]);
    }

    /**
     * Set as a service item with vendor cost (e.g., house cleaning).
     */
    public function withVendorCost(float $customerPrice, float $vendorCost): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => 1,
            'unit' => 'service',
            'unit_price' => $customerPrice,
            'total' => $customerPrice,
            'cost' => $vendorCost,
        ]);
    }

    /**
     * Set as a third-party service (house cleaning, pressure washing, etc.).
     */
    public function thirdPartyService(): static
    {
        $services = [
            ['name' => 'House Cleaning', 'price' => 400.00, 'cost' => 350.00],
            ['name' => 'Pressure Washing', 'price' => 250.00, 'cost' => 175.00],
            ['name' => 'Window Cleaning', 'price' => 200.00, 'cost' => 140.00],
            ['name' => 'Gutter Cleaning', 'price' => 150.00, 'cost' => 100.00],
            ['name' => 'Furniture Moving', 'price' => 300.00, 'cost' => 200.00],
        ];

        $service = fake()->randomElement($services);

        return $this->state(fn (array $attributes) => [
            'description' => $service['name'],
            'quantity' => 1,
            'unit' => 'service',
            'unit_price' => $service['price'],
            'total' => $service['price'],
            'cost' => $service['cost'],
        ]);
    }
}
