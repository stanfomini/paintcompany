<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LeadSource>
 */
class LeadSourceFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucwords($name),
            'slug' => Str::slug($name, '_'),
            'type' => 'custom',
            'icon' => 'globe-alt',
            'color' => 'blue',
            'is_active' => true,
            'is_system' => false,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    public function system(): static
    {
        return $this->state(fn () => [
            'type' => 'built_in',
            'is_system' => true,
        ]);
    }

    public function webhook(): static
    {
        return $this->state(fn () => [
            'type' => 'webhook',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
