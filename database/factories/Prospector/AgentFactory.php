<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Agent;
use App\Models\Prospector\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'status' => 'offline',
            'status_changed_at' => null,
            'max_concurrent_lines' => 1,
            'wrap_up_seconds' => 30,
            'daily_call_count' => 0,
            'daily_talk_minutes' => 0,
            'settings' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }

    public function available(): static
    {
        return $this->state(fn () => [
            'status' => 'available',
            'status_changed_at' => now(),
        ]);
    }

    public function onCall(): static
    {
        return $this->state(fn () => [
            'status' => 'on_call',
            'status_changed_at' => now(),
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn () => [
            'status' => 'offline',
            'status_changed_at' => null,
        ]);
    }
}
