<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CompensationScenario>
 */
class CompensationScenarioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'created_by' => User::factory()->internal(),
            'settings' => [
                'avgJobSize' => 10000,
                'knockToApptRate' => 10,
                'apptToSoldRate' => 40,
            ],
        ];
    }
}
