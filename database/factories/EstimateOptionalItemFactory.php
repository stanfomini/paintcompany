<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\EstimateOptionalItem;
use App\Models\EstimateTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimateOptionalItem>
 */
class EstimateOptionalItemFactory extends Factory
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
            'Extended Warranty',
            'Color Consultation',
            'Furniture Moving',
            'Wallpaper Removal',
            'Ceiling Texture Repair',
            'Cabinet Painting',
            'Deck Staining',
            'Pressure Washing',
            'Drywall Repair',
            'Accent Wall',
            'Touch-Up Kit',
            'Extra Coat of Paint',
            'Mildew Treatment',
            'Rush Service',
        ];

        $pricingType = fake()->randomElement([
            EstimateOptionalItem::PRICING_FIXED,
            EstimateOptionalItem::PRICING_PERCENT,
        ]);

        return [
            'estimate_id' => Estimate::factory(),
            'template_id' => null,
            'name' => fake()->randomElement($optionNames),
            'description' => fake()->optional(0.7)->sentence(),
            'pricing_type' => $pricingType,
            'price' => $pricingType === EstimateOptionalItem::PRICING_FIXED
                ? fake()->randomFloat(2, 50, 1500)
                : 0,
            'percent_of_total' => $pricingType === EstimateOptionalItem::PRICING_PERCENT
                ? fake()->randomFloat(2, 1, 15)
                : null,
            'is_selected_by_customer' => false,
            'selected_at' => null,
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
     * Associate with a specific template.
     */
    public function forTemplate(EstimateTemplate $template): static
    {
        return $this->state(fn (array $attributes) => [
            'template_id' => $template->id,
        ]);
    }

    /**
     * Set pricing type to fixed.
     */
    public function fixed(?float $price = null): static
    {
        $price = $price ?? fake()->randomFloat(2, 100, 2000);

        return $this->state(fn (array $attributes) => [
            'pricing_type' => EstimateOptionalItem::PRICING_FIXED,
            'price' => $price,
            'percent_of_total' => null,
        ]);
    }

    /**
     * Set pricing type to percent.
     */
    public function percent(?float $percentOfTotal = null): static
    {
        return $this->state(fn (array $attributes) => [
            'pricing_type' => EstimateOptionalItem::PRICING_PERCENT,
            'price' => 0,
            'percent_of_total' => $percentOfTotal ?? fake()->randomFloat(2, 1, 15),
        ]);
    }

    /**
     * Mark optional item as selected by customer.
     */
    public function selected(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_selected_by_customer' => true,
            'selected_at' => now(),
        ]);
    }

    /**
     * Mark optional item as not selected by customer.
     */
    public function notSelected(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_selected_by_customer' => false,
            'selected_at' => null,
        ]);
    }
}
