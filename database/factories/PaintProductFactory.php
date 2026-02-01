<?php

namespace Database\Factories;

use App\Models\PaintBrand;
use App\Models\PaintProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaintProduct>
 */
class PaintProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'paint_brand_id' => PaintBrand::factory(),
            'name' => fake()->words(2, true),
            'code' => fake()->unique()->lexify('PROD-????'),
            'description' => fake()->sentence(),
            'sheen' => fake()->randomElement([
                PaintProduct::SHEEN_FLAT,
                PaintProduct::SHEEN_EGGSHELL,
                PaintProduct::SHEEN_SATIN,
                PaintProduct::SHEEN_SEMI_GLOSS,
            ]),
            'product_type' => PaintProduct::TYPE_PAINT,
            'scope_type' => fake()->randomElement([
                PaintProduct::SCOPE_INTERIOR,
                PaintProduct::SCOPE_EXTERIOR,
                PaintProduct::SCOPE_BOTH,
            ]),
            'surface_type' => 'walls',
            'price_per_gallon' => fake()->randomFloat(2, 35, 95),
            'price_per_five_gallon' => fake()->randomFloat(2, 150, 400),
            'coverage_sqft_brush_roll' => fake()->numberBetween(300, 400),
            'coverage_sqft_spray' => fake()->numberBetween(400, 600),
            'is_premium' => fake()->boolean(30),
            'is_low_voc' => fake()->boolean(70),
            'is_self_priming' => fake()->boolean(50),
            'requires_tinting' => true,
            'recommended_coats' => 2,
            'second_coat_coverage_multiplier' => 1.25,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    /**
     * SW Duration Home (premium interior).
     */
    public function durationHome(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Duration Home',
            'code' => 'SW-DUR-HOME',
            'description' => 'Premium interior paint with excellent washability',
            'sheen' => PaintProduct::SHEEN_SATIN,
            'product_type' => PaintProduct::TYPE_PAINT,
            'scope_type' => PaintProduct::SCOPE_INTERIOR,
            'price_per_gallon' => 79.99,
            'price_per_five_gallon' => 349.99,
            'coverage_sqft_brush_roll' => 400,
            'coverage_sqft_spray' => 550,
            'is_premium' => true,
            'is_low_voc' => true,
            'is_self_priming' => true,
            'recommended_coats' => 2,
            'second_coat_coverage_multiplier' => 1.30,
        ]);
    }

    /**
     * SW ProMar 200 (contractor grade).
     */
    public function proMar200(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'ProMar 200',
            'code' => 'SW-PM200',
            'description' => 'Professional contractor-grade interior paint',
            'sheen' => PaintProduct::SHEEN_EGGSHELL,
            'product_type' => PaintProduct::TYPE_PAINT,
            'scope_type' => PaintProduct::SCOPE_INTERIOR,
            'price_per_gallon' => 42.99,
            'price_per_five_gallon' => 189.99,
            'coverage_sqft_brush_roll' => 350,
            'coverage_sqft_spray' => 500,
            'is_premium' => false,
            'is_low_voc' => true,
            'is_self_priming' => false,
            'recommended_coats' => 2,
            'second_coat_coverage_multiplier' => 1.20,
        ]);
    }

    /**
     * SW Duration Exterior (premium exterior).
     */
    public function durationExterior(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Duration Exterior',
            'code' => 'SW-DUR-EXT',
            'description' => 'Premium exterior paint with lifetime warranty',
            'sheen' => PaintProduct::SHEEN_SATIN,
            'product_type' => PaintProduct::TYPE_PAINT,
            'scope_type' => PaintProduct::SCOPE_EXTERIOR,
            'price_per_gallon' => 89.99,
            'price_per_five_gallon' => 399.99,
            'coverage_sqft_brush_roll' => 350,
            'coverage_sqft_spray' => 500,
            'is_premium' => true,
            'is_low_voc' => true,
            'is_self_priming' => true,
            'recommended_coats' => 2,
            'second_coat_coverage_multiplier' => 1.25,
        ]);
    }

    /**
     * BM Regal Select (premium).
     */
    public function regalSelect(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Regal Select',
            'code' => 'BM-REGAL',
            'description' => 'Premium paint with excellent coverage',
            'sheen' => PaintProduct::SHEEN_EGGSHELL,
            'product_type' => PaintProduct::TYPE_PAINT,
            'scope_type' => PaintProduct::SCOPE_INTERIOR,
            'price_per_gallon' => 74.99,
            'price_per_five_gallon' => 329.99,
            'coverage_sqft_brush_roll' => 400,
            'coverage_sqft_spray' => 550,
            'is_premium' => true,
            'is_low_voc' => true,
            'is_self_priming' => true,
            'recommended_coats' => 2,
            'second_coat_coverage_multiplier' => 1.30,
        ]);
    }

    /**
     * Primer product.
     */
    public function primer(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Multi-Purpose Primer',
            'code' => 'PRIMER-MP',
            'product_type' => PaintProduct::TYPE_PRIMER,
            'sheen' => PaintProduct::SHEEN_FLAT,
            'scope_type' => PaintProduct::SCOPE_BOTH,
            'price_per_gallon' => 35.99,
            'price_per_five_gallon' => 159.99,
            'coverage_sqft_brush_roll' => 300,
            'coverage_sqft_spray' => 450,
            'is_premium' => false,
            'is_self_priming' => false,
            'recommended_coats' => 1,
            'second_coat_coverage_multiplier' => 1.00,
        ]);
    }

    /**
     * Ceiling paint (flat).
     */
    public function ceilingPaint(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Ceiling Paint',
            'code' => 'CEIL-FLAT',
            'sheen' => PaintProduct::SHEEN_FLAT,
            'product_type' => PaintProduct::TYPE_PAINT,
            'scope_type' => PaintProduct::SCOPE_INTERIOR,
            'surface_type' => 'ceiling',
            'price_per_gallon' => 38.99,
            'price_per_five_gallon' => 169.99,
            'coverage_sqft_brush_roll' => 400,
            'coverage_sqft_spray' => 550,
            'is_premium' => false,
            'is_self_priming' => true,
            'recommended_coats' => 1,
        ]);
    }

    /**
     * Trim paint (semi-gloss).
     */
    public function trimPaint(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'ProClassic Trim',
            'code' => 'TRIM-SG',
            'sheen' => PaintProduct::SHEEN_SEMI_GLOSS,
            'product_type' => PaintProduct::TYPE_PAINT,
            'scope_type' => PaintProduct::SCOPE_INTERIOR,
            'surface_type' => 'trim',
            'price_per_gallon' => 65.99,
            'price_per_five_gallon' => 289.99,
            'coverage_sqft_brush_roll' => 350,
            'coverage_sqft_spray' => 500,
            'is_premium' => true,
            'is_self_priming' => true,
            'recommended_coats' => 2,
        ]);
    }

    /**
     * Interior scope only.
     */
    public function interior(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope_type' => PaintProduct::SCOPE_INTERIOR,
        ]);
    }

    /**
     * Exterior scope only.
     */
    public function exterior(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope_type' => PaintProduct::SCOPE_EXTERIOR,
        ]);
    }

    /**
     * Inactive product.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
