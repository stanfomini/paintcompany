<?php

namespace Database\Factories;

use App\Models\PackageTemplate;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PackageTemplate>
 */
class PackageTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Good', 'Better', 'Best']),
            'tier' => fake()->unique()->randomElement([
                PackageTemplate::TIER_GOOD,
                PackageTemplate::TIER_BETTER,
                PackageTemplate::TIER_BEST,
            ]),
            'description' => fake()->sentence(),
            'warranty_months' => 0,
            'warranty_percent' => 0,
            'is_default' => false,
            'is_popular' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * Configure as the "Good" tier package.
     */
    public function good(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Good',
            'tier' => PackageTemplate::TIER_GOOD,
            'warranty_months' => 0,
            'warranty_percent' => 0,
            'is_default' => false,
            'is_popular' => false,
            'sort_order' => 1,
        ]);
    }

    /**
     * Configure as the "Better" tier package.
     */
    public function better(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Better',
            'tier' => PackageTemplate::TIER_BETTER,
            'warranty_months' => 0,
            'warranty_percent' => 0,
            'is_default' => true,
            'is_popular' => true,
            'sort_order' => 2,
        ]);
    }

    /**
     * Configure as the "Best" tier package.
     */
    public function best(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Best',
            'tier' => PackageTemplate::TIER_BEST,
            'warranty_months' => 0,
            'warranty_percent' => 0,
            'is_default' => false,
            'is_popular' => false,
            'sort_order' => 3,
        ]);
    }

    /**
     * Mark as the default package.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    /**
     * Mark as popular.
     */
    public function popular(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_popular' => true,
        ]);
    }

    /**
     * Mark as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Associate with a specific service category.
     */
    public function forService(ServiceCategory $category): static
    {
        return $this->state(fn (array $attributes) => [
            'service_category_id' => $category->id,
        ]);
    }
}
