<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LifeCommitment>
 */
class LifeCommitmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_setting_id' => fn () => CompanySetting::getInstance()->id,
            'area' => fake()->randomElement([
                'Physical Health',
                'Mental Health',
                'Family',
                'Business',
                'Financial',
                'Spiritual',
                'Social',
                'Education',
            ]),
            'commitment' => fake()->sentence(),
            'action_type' => fake()->randomElement([
                'daily',
                'weekly',
                'monthly',
                'quarterly',
                'annual',
                'ongoing',
                'one_time',
            ]),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
            'start_date' => null,
            'recurrence_days' => null,
            'recurrence_time' => null,
            'recurrence_day_of_month' => null,
        ];
    }
}
