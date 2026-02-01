<?php

namespace Database\Factories;

use App\Models\AreaTemplateSurface;
use App\Models\EstimateTemplate;
use App\Models\ProductionRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AreaTemplateSurface>
 */
class AreaTemplateSurfaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estimate_template_id' => EstimateTemplate::factory(),
            'name' => fake()->randomElement(['Walls', 'Ceiling', 'Baseboards', 'Doors', 'Windows', 'Trim']),
            'surface_type' => fake()->randomElement(AreaTemplateSurface::SURFACE_TYPES),
            'calculation_method' => AreaTemplateSurface::CALC_METHOD_RATIO,
            'calculation_value' => 1.0,
            'unit_type' => AreaTemplateSurface::UNIT_TYPE_SQFT,
            'production_rate_id' => null,
            'default_coats' => 2,
            'is_required' => false,
            'is_default_enabled' => true,
            'fixed_price' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * Create a wall surface.
     */
    public function walls(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Walls',
            'surface_type' => AreaTemplateSurface::SURFACE_TYPE_WALLS,
            'calculation_method' => AreaTemplateSurface::CALC_METHOD_RATIO,
            'calculation_value' => 1.0,
            'unit_type' => AreaTemplateSurface::UNIT_TYPE_SQFT,
            'is_required' => true,
        ]);
    }

    /**
     * Create a ceiling surface.
     */
    public function ceiling(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Ceiling',
            'surface_type' => AreaTemplateSurface::SURFACE_TYPE_CEILING,
            'calculation_method' => AreaTemplateSurface::CALC_METHOD_RATIO,
            'calculation_value' => 0.9,
            'unit_type' => AreaTemplateSurface::UNIT_TYPE_SQFT,
            'is_required' => true,
        ]);
    }

    /**
     * Create a baseboards surface.
     */
    public function baseboards(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Baseboards',
            'surface_type' => AreaTemplateSurface::SURFACE_TYPE_TRIM,
            'calculation_method' => AreaTemplateSurface::CALC_METHOD_PER_100SQFT,
            'calculation_value' => 28,
            'unit_type' => AreaTemplateSurface::UNIT_TYPE_LNFT,
            'is_required' => false,
        ]);
    }

    /**
     * Create a doors surface.
     */
    public function doors(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Doors',
            'surface_type' => AreaTemplateSurface::SURFACE_TYPE_DOORS,
            'calculation_method' => AreaTemplateSurface::CALC_METHOD_FIXED_COUNT,
            'calculation_value' => 1,
            'unit_type' => AreaTemplateSurface::UNIT_TYPE_UNITS,
            'is_required' => false,
        ]);
    }

    /**
     * Create a fixed price surface.
     */
    public function fixedPrice(float $price = 75.00): static
    {
        return $this->state(fn (array $attributes) => [
            'calculation_method' => AreaTemplateSurface::CALC_METHOD_FIXED_PRICE,
            'fixed_price' => $price,
            'unit_type' => AreaTemplateSurface::UNIT_TYPE_UNITS,
        ]);
    }

    /**
     * Attach a production rate.
     */
    public function withProductionRate(?ProductionRate $rate = null): static
    {
        return $this->state(fn (array $attributes) => [
            'production_rate_id' => $rate?->id ?? ProductionRate::factory(),
        ]);
    }

    /**
     * Mark as required.
     */
    public function required(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_required' => true,
        ]);
    }

    /**
     * Mark as optional.
     */
    public function optional(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_required' => false,
        ]);
    }

    /**
     * Mark as enabled by default.
     */
    public function enabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default_enabled' => true,
        ]);
    }

    /**
     * Mark as disabled by default.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default_enabled' => false,
        ]);
    }
}
