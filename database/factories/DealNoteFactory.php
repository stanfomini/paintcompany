<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DealNote>
 */
class DealNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'user_id' => User::factory()->internal(),
            'content' => fake()->paragraph(),
            'is_internal' => fake()->boolean(30),
        ];
    }

    /**
     * Set the note as internal (not visible to customer).
     */
    public function internal(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_internal' => true,
        ]);
    }

    /**
     * Set the note as public (visible to customer).
     */
    public function public(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_internal' => false,
        ]);
    }

    /**
     * Associate with a specific deal.
     */
    public function forDeal(Deal $deal): static
    {
        return $this->state(fn (array $attributes) => [
            'deal_id' => $deal->id,
        ]);
    }

    /**
     * Set the author of the note.
     */
    public function writtenBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
