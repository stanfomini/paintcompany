<?php

namespace Database\Factories;

use App\Models\JobPrepPlan;
use App\Models\JobPrepServiceSetting;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPrepServiceSetting>
 */
class JobPrepServiceSettingFactory extends Factory
{
    protected $model = JobPrepServiceSetting::class;

    public function definition(): array
    {
        return [
            'job_prep_plan_id' => JobPrepPlan::factory(),
            'service_category_id' => ServiceCategory::factory()->painting(),
            'crew_size' => fake()->numberBetween(1, 8),
            'hours_per_day' => fake()->randomElement([6, 8, 10]),
            'scheduled_date' => fake()->dateTimeBetween('+1 week', '+3 months'),
            'total_labor_hours' => fake()->randomFloat(1, 4, 80),
            'total_cleanup_hours' => fake()->randomFloat(1, 0.5, 8),
            'total_days' => fake()->numberBetween(1, 5),
        ];
    }

    public function forCleaning(): static
    {
        return $this->state(fn (array $attributes) => [
            'service_category_id' => ServiceCategory::factory()->cleaning(),
        ]);
    }
}
