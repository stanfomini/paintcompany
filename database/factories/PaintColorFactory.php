<?php

namespace Database\Factories;

use App\Models\PaintBrand;
use App\Models\PaintColor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaintColor>
 */
class PaintColorFactory extends Factory
{
    protected $model = PaintColor::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $r = fake()->numberBetween(0, 255);
        $g = fake()->numberBetween(0, 255);
        $b = fake()->numberBetween(0, 255);

        return [
            'paint_brand_id' => PaintBrand::factory(),
            'name' => fake()->unique()->words(2, true),
            'code' => strtoupper(fake()->unique()->bothify('??-####')),
            'hex_value' => sprintf('#%02X%02X%02X', $r, $g, $b),
            'rgb_r' => $r,
            'rgb_g' => $g,
            'rgb_b' => $b,
            'color_family' => fake()->randomElement(['white', 'gray', 'beige', 'blue', 'green', 'red', 'yellow', 'brown', 'black', 'purple']),
            'intended_use' => 'both',
            'is_popular' => false,
            'is_active' => true,
        ];
    }

    /**
     * Mark as popular.
     */
    public function popular(): static
    {
        return $this->state(fn (array $attributes) => ['is_popular' => true]);
    }

    /**
     * Mark as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * For a specific brand.
     */
    public function forBrand(PaintBrand $brand): static
    {
        return $this->state(fn (array $attributes) => ['paint_brand_id' => $brand->id]);
    }

    /**
     * Mark as interior-only.
     */
    public function interior(): static
    {
        return $this->state(fn (array $attributes) => ['intended_use' => 'interior']);
    }

    /**
     * Mark as exterior-only.
     */
    public function exterior(): static
    {
        return $this->state(fn (array $attributes) => ['intended_use' => 'exterior']);
    }

    /**
     * With a specific hex color.
     */
    public function withHex(string $hex): static
    {
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));

        return $this->state(fn (array $attributes) => [
            'hex_value' => $hex,
            'rgb_r' => $r,
            'rgb_g' => $g,
            'rgb_b' => $b,
        ]);
    }
}
