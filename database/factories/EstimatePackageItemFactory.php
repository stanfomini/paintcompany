<?php

namespace Database\Factories;

use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimatePackageItem>
 */
class EstimatePackageItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_id' => EstimatePackage::factory(),
            'itemable_type' => EstimateItem::class,
            'itemable_id' => EstimateItem::factory(),
            'quantity' => null,
            'price_override' => null,
            'is_included' => true,
            'customer_approved' => true,
            'customer_approved_at' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * Associate with a specific package.
     */
    public function forPackage(EstimatePackage $package): static
    {
        return $this->state(fn (array $attributes) => [
            'package_id' => $package->id,
        ]);
    }

    /**
     * Associate with an EstimateItem.
     */
    public function forEstimateItem(EstimateItem $item): static
    {
        return $this->state(fn (array $attributes) => [
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item->id,
        ]);
    }

    /**
     * Associate with an EstimateArea.
     */
    public function forEstimateArea(EstimateArea $area): static
    {
        return $this->state(fn (array $attributes) => [
            'itemable_type' => EstimateArea::class,
            'itemable_id' => $area->id,
        ]);
    }

    /**
     * Associate with an EstimateAreaSurface.
     */
    public function forEstimateAreaSurface(EstimateAreaSurface $surface): static
    {
        return $this->state(fn (array $attributes) => [
            'itemable_type' => EstimateAreaSurface::class,
            'itemable_id' => $surface->id,
        ]);
    }

    /**
     * Set a custom quantity override.
     */
    public function withQuantity(float $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
        ]);
    }

    /**
     * Set a price override.
     */
    public function withPriceOverride(float $price): static
    {
        return $this->state(fn (array $attributes) => [
            'price_override' => $price,
        ]);
    }

    /**
     * Mark as excluded from package.
     */
    public function excluded(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_included' => false,
        ]);
    }

    /**
     * Mark as not approved by customer.
     */
    public function notApproved(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_approved' => false,
            'customer_approved_at' => null,
        ]);
    }

    /**
     * Mark as approved by customer with timestamp.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_approved' => true,
            'customer_approved_at' => now(),
        ]);
    }
}
