<?php

namespace Database\Factories;

use App\Models\PackageTemplate;
use App\Models\PackageTemplateItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PackageTemplateItem>
 */
class PackageTemplateItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_template_id' => PackageTemplate::factory(),
            'name' => fake()->randomElement(['Cleaning Service', 'Color Consultation', 'Touch-up Kit', 'Premium Tape']),
            'description' => fake()->sentence(),
            'pricing_type' => PackageTemplateItem::PRICING_FIXED,
            'cost' => fake()->randomFloat(2, 50, 200),
            'price' => fake()->randomFloat(2, 100, 400),
            'percent' => null,
            'is_optional' => true,
            'is_included_by_default' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * Configure with fixed pricing.
     */
    public function fixed(float $cost = 200, float $price = 400): static
    {
        return $this->state(fn (array $attributes) => [
            'pricing_type' => PackageTemplateItem::PRICING_FIXED,
            'cost' => $cost,
            'price' => $price,
            'percent' => null,
        ]);
    }

    /**
     * Configure with percentage pricing.
     */
    public function percent(float $percent = 5): static
    {
        return $this->state(fn (array $attributes) => [
            'pricing_type' => PackageTemplateItem::PRICING_PERCENT,
            'cost' => 0,
            'price' => 0,
            'percent' => $percent,
        ]);
    }

    /**
     * Configure as cleaning service.
     */
    public function cleaningService(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Post-Project Cleaning',
            'description' => 'Professional cleaning of work areas after project completion',
            'pricing_type' => PackageTemplateItem::PRICING_FIXED,
            'cost' => 200,
            'price' => 400,
            'is_optional' => true,
            'is_included_by_default' => true,
        ]);
    }

    /**
     * Configure as color consultation.
     */
    public function colorConsultation(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Color Consultation',
            'description' => 'In-home consultation with our color expert',
            'pricing_type' => PackageTemplateItem::PRICING_FIXED,
            'cost' => 50,
            'price' => 125,
            'is_optional' => true,
            'is_included_by_default' => true,
        ]);
    }

    /**
     * Configure as non-optional (always included).
     */
    public function required(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_optional' => false,
            'is_included_by_default' => true,
        ]);
    }

    /**
     * Configure as optional and not included by default.
     */
    public function optionalNotIncluded(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_optional' => true,
            'is_included_by_default' => false,
        ]);
    }

    /**
     * Associate with a specific package template.
     */
    public function forPackageTemplate(PackageTemplate $template): static
    {
        return $this->state(fn (array $attributes) => [
            'package_template_id' => $template->id,
        ]);
    }
}
