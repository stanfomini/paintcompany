<?php

namespace Database\Factories;

use App\Models\CompanyAllocation;
use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CompanyAllocation>
 */
class CompanyAllocationFactory extends Factory
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
            'Equipment Fund',
            'Training Budget',
            'Growth Investment',
        ]);

        return [
            'company_setting_id' => CompanySetting::getInstance()->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->randomNumber(4),
            'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
            'value' => fake()->randomFloat(2, 3, 15),
            'is_active' => true,
            'is_system' => false,
            'is_informational' => false,
            'description' => fake()->optional(0.5)->sentence(),
            'sort_order' => fake()->numberBetween(0, 20),
        ];
    }

    /**
     * Marketing allocation (system).
     */
    public function marketing(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Marketing',
            'slug' => 'marketing',
            'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
            'value' => 8.00,
            'is_system' => true,
            'is_informational' => false,
            'description' => 'Marketing and advertising budget',
            'sort_order' => 1,
        ]);
    }

    /**
     * Sales allocation (system).
     */
    public function sales(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Sales',
            'slug' => 'sales',
            'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
            'value' => 7.00,
            'is_system' => true,
            'is_informational' => false,
            'description' => 'Sales commission and compensation',
            'sort_order' => 2,
        ]);
    }

    /**
     * Production management allocation (system).
     */
    public function productionManagement(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Production Management',
            'slug' => 'production_management',
            'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
            'value' => 7.00,
            'is_system' => true,
            'is_informational' => false,
            'description' => 'Production oversight and coordination',
            'sort_order' => 3,
        ]);
    }

    /**
     * General overhead allocation (system, informational).
     */
    public function generalOverhead(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'General Overhead',
            'slug' => 'general_overhead',
            'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
            'value' => 12.00,
            'is_system' => true,
            'is_informational' => true,
            'description' => 'Rent, utilities, insurance, and other fixed costs (informational only)',
            'sort_order' => 10,
        ]);
    }

    /**
     * Fixed per job allocation.
     */
    public function fixedPerJob(float $amount = 150.00): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CompanyAllocation::TYPE_FIXED_PER_JOB,
            'value' => $amount,
        ]);
    }

    /**
     * Per labor hour allocation.
     */
    public function perLaborHour(float $rate = 5.00): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => CompanyAllocation::TYPE_PER_LABOR_HOUR,
            'value' => $rate,
        ]);
    }

    /**
     * Custom allocation (non-system).
     */
    public function custom(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_system' => false,
        ]);
    }

    /**
     * System allocation.
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_system' => true,
        ]);
    }

    /**
     * Inactive allocation.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Informational (doesn't reduce profit).
     */
    public function informational(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_informational' => true,
        ]);
    }

    /**
     * Associate with specific company settings.
     */
    public function forCompanySetting(CompanySetting $setting): static
    {
        return $this->state(fn (array $attributes) => [
            'company_setting_id' => $setting->id,
        ]);
    }
}
