<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Deal>
 */
class DealFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $estimatedValue = fake()->randomFloat(2, 1000, 50000);

        return [
            'customer_id' => Customer::factory(),
            'created_by' => User::factory()->internal(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional(0.5)->paragraph(),
            'stage' => 'lead',
            'estimated_value' => $estimatedValue,
            'expected_start_date' => fake()->optional(0.5)->dateTimeBetween('now', '+1 month'),
            'expected_end_date' => fake()->optional(0.3)->dateTimeBetween('+1 month', '+3 months'),
            'sort_order' => 0,
        ];
    }

    /**
     * Set the deal stage.
     */
    public function stage(string $stage): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => $stage,
        ]);
    }

    /**
     * Set the deal as a lead.
     */
    public function lead(): static
    {
        return $this->stage('lead');
    }

    /**
     * Set the deal as not contacted.
     */
    public function notContacted(): static
    {
        return $this->stage('not_contacted');
    }

    /**
     * Set the deal as follow up.
     */
    public function followUp(?string $date = null): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'follow_up',
            'follow_up_date' => $date ?? now()->addDay()->toDateString(),
        ]);
    }

    /**
     * Set the deal as quote scheduled.
     */
    public function quoteScheduled(): static
    {
        return $this->stage('quote_scheduled');
    }

    /**
     * Set the deal as quoted.
     */
    public function quoted(): static
    {
        return $this->stage('quoted');
    }

    /**
     * Set the deal as signed.
     */
    public function signed(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'signed',
            'actual_value' => $attributes['estimated_value'] ?? fake()->randomFloat(2, 1000, 50000),
        ]);
    }

    /**
     * Set the deal as completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => 'completed',
            'actual_value' => $attributes['estimated_value'] ?? fake()->randomFloat(2, 1000, 50000),
        ]);
    }

    /**
     * Set the deal as lost.
     */
    public function lost(): static
    {
        return $this->stage('lost');
    }

    /**
     * Set the deal as cancelled.
     */
    public function cancelled(): static
    {
        return $this->stage('cancelled');
    }

    /**
     * Associate with a specific customer.
     */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => $customer->id,
        ]);
    }

    /**
     * Associate with a specific property.
     */
    public function forProperty(Property $property): static
    {
        return $this->state(fn (array $attributes) => [
            'property_id' => $property->id,
            'customer_id' => $property->customer_id,
        ]);
    }

    /**
     * Assign to a team member.
     */
    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_to' => $user->id,
        ]);
    }

    /**
     * Set the referring agent.
     */
    public function referredByAgent(User $agent): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_id' => $agent->id,
        ]);
    }

    /**
     * Set agent ROI fields for property value calculator.
     */
    public function withAgentRoi(string $intent = 'selling', string $scope = 'interior'): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_id' => User::factory()->agent(),
            'agent_intent' => $intent,
            'listing_value' => fake()->randomFloat(2, 200000, 800000),
            'listing_bedrooms' => fake()->numberBetween(2, 5),
            'listing_sqft' => fake()->numberBetween(1000, 4000),
            'roi_scope' => $scope,
        ]);
    }

    /**
     * Set the lead generator.
     */
    public function withLeadGenerator(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'lead_generator_id' => $user->id,
        ]);
    }

    /**
     * Create with high value.
     */
    public function highValue(): static
    {
        return $this->state(fn (array $attributes) => [
            'estimated_value' => fake()->randomFloat(2, 50000, 200000),
        ]);
    }
}
