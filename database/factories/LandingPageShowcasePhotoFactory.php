<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\LandingPageShowcasePhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LandingPageShowcasePhoto>
 */
class LandingPageShowcasePhotoFactory extends Factory
{
    protected $model = LandingPageShowcasePhoto::class;

    public function definition(): array
    {
        return [
            'before_photo_path' => 'showcase-photos/'.fake()->uuid().'.jpg',
            'after_photo_path' => 'showcase-photos/'.fake()->uuid().'.jpg',
            'before_thumbnail_path' => null,
            'after_thumbnail_path' => null,
            'disk' => null,
            'caption' => fake()->optional(0.5)->sentence(),
            'deal_id' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function forDeal(Deal $deal): static
    {
        return $this->state(fn (array $attributes) => [
            'deal_id' => $deal->id,
        ]);
    }
}
