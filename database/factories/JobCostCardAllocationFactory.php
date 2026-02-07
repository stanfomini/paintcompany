<?php

namespace Database\Factories;

use App\Models\CompanyAllocation;
use App\Models\JobCostCard;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JobCostCardAllocation>
 */
class JobCostCardAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement([
            'Marketing',
            'Sales Commission',
            'Production Management',
            'Equipment Fund',
            'Training Budget',
        ]);

        return [
            'job_cost_card_id' => JobCostCard::factory(),
            'company_allocation_id' => CompanyAllocation::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'type' => 'percent_of_revenue',
            'value' => fake()->randomFloat(4, 3, 15),
            'amount' => fake()->randomFloat(2, 50, 500),
            'conditions_snapshot' => null,
        ];
    }

    /**
     * Associate with a specific job cost card.
     */
    public function forJobCostCard(JobCostCard $card): static
    {
        return $this->state(fn (array $attributes) => [
            'job_cost_card_id' => $card->id,
        ]);
    }

    /**
     * Associate with a specific company allocation.
     */
    public function forCompanyAllocation(CompanyAllocation $allocation): static
    {
        return $this->state(fn (array $attributes) => [
            'company_allocation_id' => $allocation->id,
            'name' => $allocation->name,
            'slug' => $allocation->slug,
            'type' => $allocation->type,
            'value' => $allocation->value,
        ]);
    }
}
