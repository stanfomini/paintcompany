<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimatePhoto>
 */
class EstimatePhotoFactory extends Factory
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
            'area_id' => null,
            'uploaded_by' => User::factory()->internal(),
            'path' => 'estimate-photos/'.fake()->uuid().'.jpg',
            'filename' => fake()->word().'.jpg',
            'caption' => fake()->optional(0.5)->sentence(),
            'category' => 'other',
            'show_on_proposal' => true,
            'sort_order' => 0,
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
     * Associate with a specific area.
     */
    public function forArea(EstimateArea $area): static
    {
        return $this->state(fn (array $attributes) => [
            'area_id' => $area->id,
        ]);
    }

    /**
     * Set photo category to before.
     */
    public function before(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'before',
        ]);
    }

    /**
     * Set photo category to during.
     */
    public function during(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'during',
        ]);
    }

    /**
     * Set photo category to reference.
     */
    public function reference(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'reference',
        ]);
    }

    /**
     * Set photo to be hidden from proposal.
     */
    public function hiddenFromProposal(): static
    {
        return $this->state(fn (array $attributes) => [
            'show_on_proposal' => false,
        ]);
    }
}
