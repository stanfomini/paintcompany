<?php

namespace Database\Factories;

use App\Models\EstimateTemplateCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimateTemplateCategory>
 */
class EstimateTemplateCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categories = [
            'room' => [
                'Living Room',
                'Bedroom',
                'Kitchen',
                'Bathroom',
                'Dining Room',
                'Office',
                'Garage',
            ],
            'surface' => [
                'Walls',
                'Ceiling',
                'Trim',
                'Doors',
                'Windows',
                'Baseboards',
                'Crown Molding',
            ],
            'item' => [
                'Primer Coat',
                'Paint Coat',
                'Sanding',
                'Caulking',
                'Patching',
                'Masking',
                'Prep Work',
            ],
            'addon' => [
                'Premium Paint Upgrade',
                'Extra Coat',
                'Accent Wall',
                'Color Change',
                'Stain Blocking',
                'Wallpaper Removal',
                'Texture Repair',
            ],
        ];

        $type = fake()->randomElement(array_keys($categories));
        $name = fake()->randomElement($categories[$type]);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(4),
            'description' => fake()->optional(0.7)->sentence(),
            'icon' => fake()->optional(0.5)->randomElement(['home', 'paint-bucket', 'brush', 'layers', 'grid', 'square']),
            'type' => $type,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    /**
     * Set the category type to room.
     */
    public function room(): static
    {
        $roomCategories = [
            'Living Room',
            'Bedroom',
            'Kitchen',
            'Bathroom',
            'Dining Room',
        ];

        $name = fake()->randomElement($roomCategories);

        return $this->state(fn (array $attributes) => [
            'type' => EstimateTemplateCategory::TYPE_ROOM,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(4),
        ]);
    }

    /**
     * Set the category type to surface.
     */
    public function surface(): static
    {
        $surfaceCategories = [
            'Walls',
            'Ceiling',
            'Trim',
            'Doors',
            'Windows',
        ];

        $name = fake()->randomElement($surfaceCategories);

        return $this->state(fn (array $attributes) => [
            'type' => EstimateTemplateCategory::TYPE_SURFACE,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(4),
        ]);
    }

    /**
     * Set the category type to item.
     */
    public function item(): static
    {
        $itemCategories = [
            'Primer Coat',
            'Paint Coat',
            'Sanding',
            'Caulking',
            'Patching',
        ];

        $name = fake()->randomElement($itemCategories);

        return $this->state(fn (array $attributes) => [
            'type' => EstimateTemplateCategory::TYPE_ITEM,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(4),
        ]);
    }

    /**
     * Set the category type to addon.
     */
    public function addon(): static
    {
        $addonCategories = [
            'Premium Paint Upgrade',
            'Extra Coat',
            'Accent Wall',
            'Color Change',
            'Stain Blocking',
        ];

        $name = fake()->randomElement($addonCategories);

        return $this->state(fn (array $attributes) => [
            'type' => EstimateTemplateCategory::TYPE_ADDON,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(4),
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

    /**
     * Set a specific slug for the category.
     */
    public function withSlug(string $slug): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => $slug,
        ]);
    }
}
