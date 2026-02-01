<?php

namespace Database\Factories;

use App\Models\EstimateTemplate;
use App\Models\NestedAreaTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AreaTemplateNestedOption>
 */
class AreaTemplateNestedOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_template_id' => EstimateTemplate::factory(),
            'nested_template_id' => null,
            'nested_area_template_id' => null,
            'name' => fake()->randomElement(['Closet', 'Half Bath', 'Pantry', 'Utility Room']),
            'is_default_included' => false,
            'max_quantity' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * Link to a nested estimate template.
     */
    public function withNestedTemplate(?EstimateTemplate $template = null): static
    {
        return $this->state(fn (array $attributes) => [
            'nested_template_id' => $template?->id ?? EstimateTemplate::factory(),
            'nested_area_template_id' => null,
        ]);
    }

    /**
     * Link to a nested area template (fixed-price).
     */
    public function withNestedAreaTemplate(?NestedAreaTemplate $template = null): static
    {
        return $this->state(fn (array $attributes) => [
            'nested_template_id' => null,
            'nested_area_template_id' => $template?->id ?? NestedAreaTemplate::factory(),
        ]);
    }

    /**
     * Mark as included by default.
     */
    public function defaultIncluded(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default_included' => true,
        ]);
    }

    /**
     * Set a quantity limit.
     */
    public function limitQuantity(int $max): static
    {
        return $this->state(fn (array $attributes) => [
            'max_quantity' => $max,
        ]);
    }

    /**
     * Create a closet nested option.
     */
    public function closet(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Closet',
            'is_default_included' => false,
            'max_quantity' => 3,
        ]);
    }

    /**
     * Create a half bath nested option.
     */
    public function halfBath(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Half Bath',
            'is_default_included' => false,
            'max_quantity' => 1,
        ]);
    }
}
