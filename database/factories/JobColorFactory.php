<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\JobColor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JobColor>
 */
class JobColorFactory extends Factory
{
    protected $model = JobColor::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $colors = [
            ['Agreeable Gray', 'Sherwin-Williams', 'SW 7029', '#D0CBC6'],
            ['Alabaster', 'Sherwin-Williams', 'SW 7008', '#F0EDE5'],
            ['Revere Pewter', 'Benjamin Moore', 'HC-172', '#C9BFA8'],
            ['Simply White', 'Benjamin Moore', 'OC-17', '#F1EDE3'],
            ['Swiss Coffee', 'Behr', 'PPU5-12', '#E7DDD1'],
        ];

        $color = fake()->randomElement($colors);

        return [
            'job_id' => Job::factory(),
            'name' => $color[0],
            'brand' => $color[1],
            'code' => $color[2],
            'hex_value' => $color[3],
            'surface_area' => fake()->optional()->randomElement(['Walls', 'Ceiling', 'Trim', 'Accent Wall']),
            'sort_order' => 0,
        ];
    }
}
