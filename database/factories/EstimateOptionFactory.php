<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\EstimateOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimateOption>
 */
class EstimateOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $optionNames = [
            'Premium Paint Upgrade',
            'Color Consultation',
            'Furniture Moving',
            'Wallpaper Removal',
            'Ceiling Texture Repair',
            'Trim & Baseboard Painting',
            'Cabinet Painting',
            'Deck Staining',
            'Pressure Washing',
            'Drywall Repair',
            'Accent Wall',
            'Two-Tone Finish',
            'Primer Coat',
            'Extra Coat of Paint',
            'Mildew Treatment',
        ];

        $pricingType = fake()->randomElement(array_keys(EstimateOption::PRICING_TYPES));
        $fixedPrice = fake()->randomFloat(2, 50, 1500);
        $multiplier = fake()->randomFloat(3, 0.5, 3.0);

        return [
            'estimate_id' => Estimate::factory(),
            'surface_id' => null,
            'name' => fake()->randomElement($optionNames),
            'description' => fake()->optional(0.7)->sentence(),
            'pricing_type' => $pricingType,
            'fixed_price' => $fixedPrice,
            'multiplier' => $multiplier,
            'calculated_price' => $pricingType === 'fixed' ? $fixedPrice : fake()->randomFloat(2, 100, 2000),
            'is_selected' => false,
            'is_recommended' => fake()->boolean(30),
            'sort_order' => fake()->numberBetween(0, 10),
        ];
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
     * Set pricing type to fixed.
     */
    public function fixed(): static
    {
        $price = fake()->randomFloat(2, 100, 2000);

        return $this->state(fn (array $attributes) => [
            'pricing_type' => 'fixed',
            'fixed_price' => $price,
            'calculated_price' => $price,
        ]);
    }

    /**
     * Set pricing type to per square foot.
     */
    public function perSqft(): static
    {
        return $this->state(fn (array $attributes) => [
            'pricing_type' => 'per_sqft',
            'multiplier' => fake()->randomFloat(3, 0.5, 5.0),
        ]);
    }

    /**
     * Set pricing type to per surface.
     */
    public function perSurface(): static
    {
        return $this->state(fn (array $attributes) => [
            'pricing_type' => 'per_surface',
            'multiplier' => fake()->randomFloat(3, 1.0, 2.5),
        ]);
    }

    /**
     * Mark option as selected.
     */
    public function selected(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_selected' => true,
        ]);
    }

    /**
     * Mark option as recommended.
     */
    public function recommended(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_recommended' => true,
        ]);
    }
}
