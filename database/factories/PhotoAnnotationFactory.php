<?php

namespace Database\Factories;

use App\Models\PropertyPhoto;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PhotoAnnotation>
 */
class PhotoAnnotationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_photo_id' => PropertyPhoto::factory(),
            'created_by' => User::factory()->internal(),
            'author_type' => 'internal',
            'canvas_json' => ['objects' => [], 'version' => '6.0'],
            'canvas_version' => '6.0',
            'rendered_path' => null,
        ];
    }

    public function internal(): static
    {
        return $this->state(fn () => ['author_type' => 'internal']);
    }

    public function crew(): static
    {
        return $this->state(fn () => [
            'author_type' => 'crew',
            'created_by' => User::factory()->crew(),
        ]);
    }

    public function customer(): static
    {
        return $this->state(fn () => [
            'author_type' => 'customer',
            'created_by' => User::factory()->customer(),
        ]);
    }
}
