<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SaasComparisonScenario>
 */
class SaasComparisonScenarioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'created_by' => User::factory(),
            'settings' => [
                'userCount' => 10,
                'selfHostServer' => 96,
                'selfHostForge' => 19,
                'selfHostDomain' => 1,
                'selfHostStorage' => 25,
                'selfHostTwilio' => 20,
                'selfHostMessaging' => 100,
                'selfHostPython' => 0,
                'selfHostMisc' => 539,
                'hoursConsolidation' => 5,
                'hoursPerEstimate' => 2,
                'estimatesPerWeek' => 5,
                'hoursSwitching' => 1,
                'trainingHoursSaved' => 40,
                'employeeHourValue' => 35,
                'workWeeksPerYear' => 50,
            ],
        ];
    }

    public function withPublicToken(int $expiryDays = 30): static
    {
        return $this->state(fn () => [
            'public_token' => fake()->regexify('[A-Za-z0-9]{64}'),
            'public_token_expires_at' => now()->addDays($expiryDays),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'public_token' => fake()->regexify('[A-Za-z0-9]{64}'),
            'public_token_expires_at' => now()->subDay(),
        ]);
    }
}
