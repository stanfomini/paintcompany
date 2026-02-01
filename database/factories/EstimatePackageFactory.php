<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\EstimatePackage;
use App\Models\EstimateSignature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimatePackage>
 */
class EstimatePackageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 1000, 15000);
        $taxRate = fake()->randomElement([0, 6, 7, 8, 10]);
        $taxAmount = $subtotal * ($taxRate / 100);
        $discountPercent = fake()->boolean(30) ? fake()->randomFloat(2, 5, 15) : 0;
        $discountAmount = $subtotal * ($discountPercent / 100);
        $total = $subtotal - $discountAmount + $taxAmount;

        return [
            'estimate_id' => Estimate::factory(),
            'name' => fake()->randomElement(['Essential Package', 'Standard Package', 'Premium Package', 'Complete Package']),
            'tier' => fake()->randomElement([
                EstimatePackage::TIER_BASIC,
                EstimatePackage::TIER_STANDARD,
                EstimatePackage::TIER_PREMIUM,
            ]),
            'description' => fake()->optional(0.7)->paragraph(),
            'includes' => fake()->optional(0.6)->text(200),
            'excludes' => fake()->optional(0.4)->text(100),
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'discount_percent' => $discountPercent,
            'total' => $total,
            'warranty_price' => fake()->boolean(50) ? fake()->randomFloat(2, 100, 500) : 0,
            'warranty_years' => fake()->optional(0.5)->randomElement([1, 2, 3, 5]),
            'warranty_type' => fake()->optional(0.5)->randomElement(['labor', 'materials', 'full']),
            'is_recommended' => false,
            'is_selected' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * Set as basic tier package.
     */
    public function basic(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Good Package',
            'tier' => EstimatePackage::TIER_BASIC,
            'sort_order' => 1,
        ]);
    }

    /**
     * Set as standard tier package.
     */
    public function standard(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Better Package',
            'tier' => EstimatePackage::TIER_STANDARD,
            'sort_order' => 2,
        ]);
    }

    /**
     * Set as premium tier package.
     */
    public function premium(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Best Package',
            'tier' => EstimatePackage::TIER_PREMIUM,
            'sort_order' => 3,
        ]);
    }

    /**
     * Set as custom tier package.
     */
    public function custom(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Custom Package',
            'tier' => EstimatePackage::TIER_CUSTOM,
            'sort_order' => 4,
        ]);
    }

    /**
     * Mark this package as recommended.
     */
    public function recommended(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_recommended' => true,
        ]);
    }

    /**
     * Mark this package as selected.
     */
    public function selected(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_selected' => true,
        ]);
    }

    /**
     * Mark this package as signed.
     */
    public function signed(): static
    {
        return $this->state(fn (array $attributes) => [
            'signature_id' => EstimateSignature::factory(),
            'signed_at' => fake()->dateTimeBetween('-1 week', 'now'),
            'is_selected' => true,
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
     * Set specific pricing values.
     */
    public function withPricing(
        float $subtotal,
        float $taxRate = 0,
        float $discountPercent = 0
    ): static {
        $discountAmount = $subtotal * ($discountPercent / 100);
        $taxableAmount = $subtotal - $discountAmount;
        $taxAmount = $taxableAmount * ($taxRate / 100);
        $total = $taxableAmount + $taxAmount;

        return $this->state(fn (array $attributes) => [
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'discount_percent' => $discountPercent,
            'total' => $total,
        ]);
    }

    /**
     * Add warranty to the package.
     */
    public function withWarranty(float $price = 250, int $years = 2, string $type = 'full'): static
    {
        return $this->state(fn (array $attributes) => [
            'warranty_price' => $price,
            'warranty_years' => $years,
            'warranty_type' => $type,
        ]);
    }
}
