<?php

namespace Database\Factories;

use App\Models\PropertyPhoto;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PhotoComment>
 */
class PhotoCommentFactory extends Factory
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
            'user_id' => User::factory()->internal(),
            'body' => fake()->paragraph(),
            'parent_id' => null,
        ];
    }

    /**
     * Make this comment a reply to another comment.
     */
    public function replyTo(\App\Models\PhotoComment $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'property_photo_id' => $parent->property_photo_id,
            'parent_id' => $parent->id,
        ]);
    }
}
