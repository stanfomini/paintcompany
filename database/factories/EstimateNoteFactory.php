<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\EstimateNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimateNote>
 */
class EstimateNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estimate_id' => Estimate::factory(),
            'user_id' => User::factory()->internal(),
            'parent_id' => null,
            'content' => fake()->paragraph(),
            'is_internal' => fake()->boolean(30),
            'ip_address' => fake()->ipv4(),
        ];
    }

    /**
     * Associate with a specific estimate.
     */
    public function forEstimate(Estimate $estimate): static
    {
        return $this->state(fn (array $attributes) => [
            'estimate_id' => $estimate->id,
        ]);
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
     * Set the note as external (visible to customer).
     */
    public function external(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_internal' => false,
        ]);
    }

    /**
     * Set this note as a reply to another note.
     */
    public function asReplyTo(EstimateNote $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'estimate_id' => $parent->estimate_id,
            'parent_id' => $parent->id,
        ]);
    }
}
