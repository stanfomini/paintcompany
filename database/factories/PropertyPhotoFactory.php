<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\Job;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PropertyPhoto>
 */
class PropertyPhotoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'uploaded_by' => User::factory()->internal(),
            'path' => 'property-photos/'.fake()->uuid().'.jpg',
            'filename' => fake()->word().'.jpg',
            'caption' => fake()->optional(0.5)->sentence(),
            'category' => 'general',
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
     * Associate with a specific job.
     */
    public function forJob(Job $job): static
    {
        return $this->state(fn (array $attributes) => [
            'job_id' => $job->id,
        ]);
    }

    /**
     * Set photo to be shown on proposal.
     */
    public function proposalPhoto(): static
    {
        return $this->state(fn (array $attributes) => [
            'show_on_proposal' => true,
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
     * Set photo category to after.
     */
    public function after(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'after',
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

    /**
     * Associate with a specific property.
     */
    public function forProperty(Property $property): static
    {
        return $this->state(fn (array $attributes) => [
            'property_id' => $property->id,
        ]);
    }
}
