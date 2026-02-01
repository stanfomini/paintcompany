<?php

namespace Database\Factories;

use App\Models\EstimateArea;
use App\Models\EstimateAreaPaintSelection;
use App\Models\PaintProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimateAreaPaintSelection>
 */
class EstimateAreaPaintSelectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sqft = fake()->numberBetween(100, 500);
        $coats = fake()->randomElement([1, 2]);
        $gallonsNeeded = ceil(($sqft * $coats) / 350);
        $pricePerGallon = fake()->randomFloat(2, 40, 80);
        $paintCost = $gallonsNeeded * $pricePerGallon;
        $recycleFee = $gallonsNeeded * 0.54;

        return [
            'estimate_area_id' => EstimateArea::factory(),
            'paint_product_id' => PaintProduct::factory(),
            'surface_type' => fake()->randomElement([
                EstimateAreaPaintSelection::SURFACE_WALLS,
                EstimateAreaPaintSelection::SURFACE_CEILING,
                EstimateAreaPaintSelection::SURFACE_TRIM,
            ]),
            'coats' => $coats,
            'sqft_covered' => $sqft,
            'gallons_needed' => $gallonsNeeded,
            'paint_cost' => $paintCost,
            'recycle_fee' => $recycleFee,
            'total_material_cost' => $paintCost + $recycleFee,
            'color_name' => fake()->optional(0.7)->colorName(),
            'color_code' => fake()->optional(0.7)->lexify('SW-####'),
        ];
    }

    /**
     * Wall paint selection.
     */
    public function forWalls(): static
    {
        return $this->state(fn (array $attributes) => [
            'surface_type' => EstimateAreaPaintSelection::SURFACE_WALLS,
        ]);
    }

    /**
     * Ceiling paint selection.
     */
    public function forCeiling(): static
    {
        return $this->state(fn (array $attributes) => [
            'surface_type' => EstimateAreaPaintSelection::SURFACE_CEILING,
            'coats' => 1,
        ]);
    }

    /**
     * Trim paint selection.
     */
    public function forTrim(): static
    {
        return $this->state(fn (array $attributes) => [
            'surface_type' => EstimateAreaPaintSelection::SURFACE_TRIM,
            'coats' => 2,
        ]);
    }

    /**
     * Doors paint selection.
     */
    public function forDoors(): static
    {
        return $this->state(fn (array $attributes) => [
            'surface_type' => EstimateAreaPaintSelection::SURFACE_DOORS,
            'coats' => 2,
        ]);
    }

    /**
     * Cabinet paint selection.
     */
    public function forCabinets(): static
    {
        return $this->state(fn (array $attributes) => [
            'surface_type' => EstimateAreaPaintSelection::SURFACE_CABINETS,
            'coats' => 2,
        ]);
    }

    /**
     * With specific coverage.
     */
    public function withCoverage(float $sqft, int $coats = 2): static
    {
        return $this->state(fn (array $attributes) => [
            'sqft_covered' => $sqft,
            'coats' => $coats,
        ]);
    }

    /**
     * With color specified.
     */
    public function withColor(string $name, ?string $code = null): static
    {
        return $this->state(fn (array $attributes) => [
            'color_name' => $name,
            'color_code' => $code,
        ]);
    }
}
