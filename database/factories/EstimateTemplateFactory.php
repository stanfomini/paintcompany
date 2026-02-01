<?php

namespace Database\Factories;

use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\Substrate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimateTemplate>
 */
class EstimateTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $pricingMethod = fake()->randomElement(EstimateTemplate::PRICING_METHODS);

        return [
            'template_category_id' => EstimateTemplateCategory::factory(),
            'name' => fake()->randomElement([
                'Interior Wall Painting',
                'Ceiling Painting',
                'Trim & Baseboard',
                'Door Painting',
                'Cabinet Refinishing',
                'Exterior Siding',
                'Deck Staining',
                'Fence Painting',
                'Accent Wall',
                'Crown Molding',
            ]),
            'description' => fake()->optional(0.7)->sentence(10),
            'icon' => fake()->optional(0.5)->randomElement(['paint-brush', 'home', 'door', 'cabinet', 'fence']),
            'image_path' => null,
            'default_quantity' => fake()->randomFloat(2, 100, 500),
            'default_unit' => fake()->randomElement(['sqft', 'lnft', 'unit']),
            'default_coats' => fake()->numberBetween(1, 3),
            'production_rate_id' => $pricingMethod === EstimateTemplate::PRICING_PRODUCTION_RATE
                ? ProductionRate::factory()
                : null,
            'substrate_id' => null,
            'default_paint_product_id' => null,
            'pricing_method' => $pricingMethod,
            'base_price' => $pricingMethod === EstimateTemplate::PRICING_FIXED
                ? fake()->randomFloat(2, 100, 2000)
                : null,
            'per_sqft_rate' => $pricingMethod === EstimateTemplate::PRICING_PER_SQFT
                ? fake()->randomFloat(4, 0.50, 5.00)
                : null,
            'percent_of_total' => $pricingMethod === EstimateTemplate::PRICING_PERCENT
                ? fake()->randomFloat(2, 5, 25)
                : null,
            'scope_type' => fake()->optional(0.8)->randomElement([
                EstimateTemplate::SCOPE_TYPE_INTERIOR,
                EstimateTemplate::SCOPE_TYPE_EXTERIOR,
                EstimateTemplate::SCOPE_TYPE_BOTH,
            ]),
            'default_surfaces' => fake()->optional(0.5)->randomElement([
                ['walls', 'ceiling'],
                ['walls', 'trim'],
                ['doors'],
                ['cabinets', 'drawers'],
            ]),
            'options' => fake()->optional(0.3)->randomElement([
                ['include_primer' => true, 'extra_prep' => false],
                ['accent_color' => true, 'two_tone' => false],
            ]),
            'metadata' => null,
            'is_active' => true,
            'created_by' => null,
            'visibility' => EstimateTemplate::VISIBILITY_COMPANY,
            'is_configurable' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * Associate with a specific category.
     */
    public function forCategory(EstimateTemplateCategory $category): static
    {
        return $this->state(fn (array $attributes) => [
            'template_category_id' => $category->id,
        ]);
    }

    /**
     * Associate with a specific production rate.
     */
    public function withProductionRate(ProductionRate $productionRate): static
    {
        return $this->state(fn (array $attributes) => [
            'production_rate_id' => $productionRate->id,
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
        ]);
    }

    /**
     * Set fixed pricing method.
     */
    public function fixedPricing(?float $price = null): static
    {
        return $this->state(fn (array $attributes) => [
            'pricing_method' => EstimateTemplate::PRICING_FIXED,
            'base_price' => $price ?? fake()->randomFloat(2, 100, 2000),
            'production_rate_id' => null,
            'per_sqft_rate' => null,
            'percent_of_total' => null,
        ]);
    }

    /**
     * Set per square foot pricing method.
     */
    public function perSqftPricing(?float $rate = null): static
    {
        return $this->state(fn (array $attributes) => [
            'pricing_method' => EstimateTemplate::PRICING_PER_SQFT,
            'per_sqft_rate' => $rate ?? fake()->randomFloat(4, 0.50, 5.00),
            'production_rate_id' => null,
            'base_price' => null,
            'percent_of_total' => null,
        ]);
    }

    /**
     * Set percent pricing method.
     */
    public function percentPricing(?float $percent = null): static
    {
        return $this->state(fn (array $attributes) => [
            'pricing_method' => EstimateTemplate::PRICING_PERCENT,
            'percent_of_total' => $percent ?? fake()->randomFloat(2, 5, 25),
            'production_rate_id' => null,
            'base_price' => null,
            'per_sqft_rate' => null,
        ]);
    }

    /**
     * Set as interior scope.
     */
    public function interior(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
        ]);
    }

    /**
     * Set as exterior scope.
     */
    public function exterior(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
        ]);
    }

    /**
     * Set the template as inactive.
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

    /**
     * Set as a configurable area template.
     */
    public function configurable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_configurable' => true,
        ]);
    }

    /**
     * Set as a system template (cannot be modified/deleted by users).
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
            'created_by' => null,
        ]);
    }

    /**
     * Set as a company template.
     */
    public function company(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => EstimateTemplate::VISIBILITY_COMPANY,
        ]);
    }

    /**
     * Set as a private template.
     */
    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'visibility' => EstimateTemplate::VISIBILITY_PRIVATE,
        ]);
    }

    /**
     * Set the creator of the template.
     */
    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
        ]);
    }

    /**
     * Create a configurable interior area template.
     */
    public function areaTemplate(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_configurable' => true,
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
        ]);
    }
}
