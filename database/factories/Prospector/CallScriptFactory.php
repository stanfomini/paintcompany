<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\CallScript;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallScript>
 */
class CallScriptFactory extends Factory
{
    protected $model = CallScript::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'campaign_id' => null,
            'name' => fake()->randomElement(['Cold Call Intro', 'Follow-Up Script', 'Objection Handling']),
            'body' => fake()->paragraphs(3, true),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }

    public function forCampaign(Campaign $campaign): static
    {
        return $this->state(fn () => ['campaign_id' => $campaign->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function global(): static
    {
        return $this->state(fn () => ['company_id' => null]);
    }
}
