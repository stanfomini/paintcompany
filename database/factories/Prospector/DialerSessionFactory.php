<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Agent;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\DialerSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DialerSession>
 */
class DialerSessionFactory extends Factory
{
    protected $model = DialerSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'campaign_id' => Campaign::factory(),
            'mode' => 'single',
            'status' => 'active',
            'calls_attempted' => 0,
            'calls_connected' => 0,
            'total_talk_seconds' => 0,
            'started_at' => now(),
            'ended_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'ended_at' => null,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn () => [
            'status' => 'paused',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'calls_attempted' => fake()->numberBetween(10, 50),
            'calls_connected' => fake()->numberBetween(3, 15),
            'total_talk_seconds' => fake()->numberBetween(300, 3600),
            'ended_at' => now(),
        ]);
    }
}
