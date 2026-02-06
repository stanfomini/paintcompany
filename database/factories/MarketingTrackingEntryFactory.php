<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use App\Models\MarketingTrackingEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MarketingTrackingEntry>
 */
class MarketingTrackingEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $expense = fake()->randomFloat(2, 100, 10000);
        $leads = fake()->numberBetween(0, 50);
        $estimates = $leads > 0 ? fake()->numberBetween(0, $leads) : 0;
        $jobsBooked = $estimates > 0 ? fake()->numberBetween(0, $estimates) : 0;
        $totalSales = $jobsBooked > 0 ? fake()->randomFloat(2, 1000, 100000) : 0;

        return [
            'company_setting_id' => fn () => CompanySetting::getInstance()->id,
            'year' => now()->year,
            'source_name' => fake()->randomElement(MarketingTrackingEntry::defaultSources()),
            'marketing_expense' => $expense,
            'leads' => $leads,
            'estimates' => $estimates,
            'jobs_booked' => $jobsBooked,
            'total_sales' => $totalSales,
            'is_manual' => false,
            'notes' => fake()->optional(0.3)->sentence(),
            'sort_order' => 0,
        ];
    }

    /**
     * Set the entry as manually created.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_manual' => true,
        ]);
    }

    /**
     * Set a specific year.
     */
    public function forYear(int $year): static
    {
        return $this->state(fn (array $attributes) => [
            'year' => $year,
        ]);
    }

    /**
     * Set a specific source name.
     */
    public function source(string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'source_name' => $name,
        ]);
    }

    /**
     * Set all tracking values to zero (empty entry).
     */
    public function empty(): static
    {
        return $this->state(fn (array $attributes) => [
            'marketing_expense' => 0,
            'leads' => 0,
            'estimates' => 0,
            'jobs_booked' => 0,
            'total_sales' => 0,
        ]);
    }
}
