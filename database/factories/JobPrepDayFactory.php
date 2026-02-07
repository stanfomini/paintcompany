<?php

namespace Database\Factories;

use App\Models\JobPrepDay;
use App\Models\JobPrepPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPrepDay>
 */
class JobPrepDayFactory extends Factory
{
    protected $model = JobPrepDay::class;

    public function definition(): array
    {
        return [
            'job_prep_plan_id' => JobPrepPlan::factory(),
            'day_number' => 1,
            'label' => null,
            'sort_order' => 0,
        ];
    }
}
