<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->words(3, true).' Campaign',
            'description' => fake()->optional()->sentence(),
            'status' => 'draft',
            'phone_number_id' => null,
            'use_local_presence' => true,
            'dialer_mode' => 'single',
            'parallel_lines' => 1,
            'priority' => 0,
            'contact_count' => 0,
            'calls_attempted' => 0,
            'calls_connected' => 0,
            'created_by' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function paused(): static
    {
        return $this->state(fn () => ['status' => 'paused']);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => 'completed']);
    }
}
