<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->words(3, true);

        return [
            'title' => Str::title($title),
            'slug' => Str::slug($title),
            'category' => fake()->randomElement(['Residential', 'Commercial', 'Specialty']),
            'tagline' => fake()->sentence(),
            'meta_description' => fake()->paragraph(),
            'overview' => fake()->paragraphs(3, true),
            'materials' => [
                'Paint' => [fake()->sentence(), fake()->sentence()],
                'Tools' => [fake()->sentence(), fake()->sentence()],
            ],
            'steps' => [
                ['title' => 'Step 1', 'description' => fake()->sentence()],
                ['title' => 'Step 2', 'description' => fake()->sentence()],
            ],
            'tips' => [
                ['title' => 'Tip 1', 'description' => fake()->sentence()],
            ],
            'cost_factors' => [fake()->sentence(), fake()->sentence()],
            'faqs' => [
                ['q' => fake()->sentence().'?', 'a' => fake()->paragraph()],
            ],
            'icon' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function residential(): static
    {
        return $this->state(fn () => ['category' => 'Residential']);
    }

    public function commercial(): static
    {
        return $this->state(fn () => ['category' => 'Commercial']);
    }

    public function specialty(): static
    {
        return $this->state(fn () => ['category' => 'Specialty']);
    }
}
