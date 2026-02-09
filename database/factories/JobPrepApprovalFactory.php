<?php

namespace Database\Factories;

use App\Models\JobPrepApproval;
use App\Models\JobPrepPlan;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPrepApproval>
 */
class JobPrepApprovalFactory extends Factory
{
    protected $model = JobPrepApproval::class;

    public function definition(): array
    {
        return [
            'job_prep_plan_id' => JobPrepPlan::factory(),
            'service_category_id' => ServiceCategory::factory()->painting(),
            'party' => fake()->randomElement([
                JobPrepApproval::PARTY_TEAM,
                JobPrepApproval::PARTY_CREW,
                JobPrepApproval::PARTY_CUSTOMER,
            ]),
            'approved' => false,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved' => true,
            'approved_by' => User::factory()->internal()->withFullAccess(),
            'approved_at' => now(),
        ]);
    }

    public function team(): static
    {
        return $this->state(fn (array $attributes) => [
            'party' => JobPrepApproval::PARTY_TEAM,
        ]);
    }

    public function crew(): static
    {
        return $this->state(fn (array $attributes) => [
            'party' => JobPrepApproval::PARTY_CREW,
        ]);
    }

    public function customer(): static
    {
        return $this->state(fn (array $attributes) => [
            'party' => JobPrepApproval::PARTY_CUSTOMER,
        ]);
    }
}
