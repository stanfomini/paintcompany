<?php

namespace Database\Factories;

use App\Models\ShowcaseMedia;
use App\Models\VideoRecording;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ShowcaseMedia>
 */
class ShowcaseMediaFactory extends Factory
{
    protected $model = ShowcaseMedia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->words(3, true);

        return [
            'slug' => Str::slug($label),
            'label' => ucwords($label),
            'video_recording_id' => null,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 100),
        ];
    }

    public function withVideo(): static
    {
        return $this->state(fn () => [
            'video_recording_id' => VideoRecording::factory()->ready(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
