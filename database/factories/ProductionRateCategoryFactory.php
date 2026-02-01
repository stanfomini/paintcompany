<?php

namespace Database\Factories;

use App\Models\ProductionRateCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductionRateCategory>
 */
class ProductionRateCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categories = [
            'interior' => [
                'Wall Painting',
                'Ceiling Painting',
                'Trim & Molding',
                'Door Painting',
                'Cabinet Painting',
                'Drywall Repair',
                'Texture Application',
            ],
            'exterior' => [
                'Siding Painting',
                'Trim & Fascia',
                'Deck Staining',
                'Fence Staining',
                'Garage Door Painting',
                'Pressure Washing',
                'Surface Preparation',
            ],
            'specialty' => [
                'Faux Finishing',
                'Wallpaper Removal',
                'Epoxy Flooring',
                'Lead Paint Abatement',
                'Popcorn Ceiling Removal',
                'Commercial Coating',
                'Industrial Painting',
            ],
        ];

        $type = fake()->randomElement(array_keys($categories));
        $name = fake()->randomElement($categories[$type]);

        return [
            'name' => $name,
            'type' => $type,
            'description' => fake()->optional(0.7)->sentence(),
            'sort_order' => fake()->numberBetween(1, 100),
            'is_active' => true,
        ];
    }

    /**
     * Set the category type to interior.
     */
    public function interior(): static
    {
        $interiorCategories = [
            'Wall Painting',
            'Ceiling Painting',
            'Trim & Molding',
            'Door Painting',
            'Cabinet Painting',
        ];

        return $this->state(fn (array $attributes) => [
            'type' => ProductionRateCategory::TYPE_INTERIOR,
            'name' => fake()->randomElement($interiorCategories),
        ]);
    }

    /**
     * Set the category type to exterior.
     */
    public function exterior(): static
    {
        $exteriorCategories = [
            'Siding Painting',
            'Trim & Fascia',
            'Deck Staining',
            'Fence Staining',
            'Garage Door Painting',
        ];

        return $this->state(fn (array $attributes) => [
            'type' => ProductionRateCategory::TYPE_EXTERIOR,
            'name' => fake()->randomElement($exteriorCategories),
        ]);
    }

    /**
     * Set the category type to specialty.
     */
    public function specialty(): static
    {
        $specialtyCategories = [
            'Faux Finishing',
            'Wallpaper Removal',
            'Epoxy Flooring',
            'Lead Paint Abatement',
            'Popcorn Ceiling Removal',
        ];

        return $this->state(fn (array $attributes) => [
            'type' => ProductionRateCategory::TYPE_SPECIALTY,
            'name' => fake()->randomElement($specialtyCategories),
        ]);
    }

    /**
     * Set the category as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
