<?php

namespace Database\Factories;

use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceCategory>
 */
class ServiceCategoryFactory extends Factory
{
    protected $model = ServiceCategory::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'slug' => fn (array $attributes) => \Illuminate\Support\Str::slug($attributes['name']),
            'description' => fake()->sentence(),
            'icon' => fake()->randomElement(['paint-brush', 'sparkles', 'wrench', 'bolt']),
            'color' => fake()->randomElement(['blue', 'green', 'red', 'yellow', 'purple']),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function painting(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Painting',
            'slug' => 'painting',
            'description' => 'Interior and exterior painting services',
            'icon' => 'paint-brush',
            'color' => 'blue',
            'sort_order' => 1,
        ]);
    }

    public function cleaning(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Cleaning',
            'slug' => 'cleaning',
            'description' => 'Post-painting cleanup and pressure washing',
            'icon' => 'sparkles',
            'color' => 'green',
            'sort_order' => 2,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
