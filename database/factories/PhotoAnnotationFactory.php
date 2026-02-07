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
            'canvas_json' => ['objects' => [], 'version' => '1.0'],
            'canvas_version' => '1.0',
            'rendered_path' => null,
        ];
    }
}
