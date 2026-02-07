<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use App\Models\Job;
use App\Models\JobCostCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JobCostCard>
 */
class JobCostCardFactory extends Factory
{
    protected $model = JobCostCard::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = fake()->randomFloat(2, 2000, 25000);
        $prodMgmt = round($price * fake()->randomFloat(2, 0.05, 0.10), 2);
        $subcontractor = round($price * fake()->randomFloat(2, 0, 0.15), 2);
        $wcInsurance = round($price * fake()->randomFloat(2, 0.03, 0.08), 2);
        $material = round($price * fake()->randomFloat(2, 0.08, 0.20), 2);
        $commission = round($price * fake()->randomFloat(2, 0.05, 0.10), 2);

        return [
            'company_setting_id' => fn () => CompanySetting::getInstance()->id,
            'year' => now()->year,
            'client_name' => fake()->name(),
            'price' => $price,
            'production_mgmt_labor' => $prodMgmt,
            'subcontractor_cost' => $subcontractor,
            'wc_insurance_burden' => $wcInsurance,
            'material_cost' => $material,
            'sales_commission' => $commission,
            'is_manual' => true,
            'status' => 'active',
            'estimate_id' => null,
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }

    /**
     * Associate with a specific job.
     */
    public function forJob(Job $job): static
    {
        return $this->state(fn (array $attributes) => [
            'job_id' => $job->id,
            'is_manual' => false,
        ]);
    }

    /**
     * Mark as a manual entry.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_manual' => true,
            'job_id' => null,
        ]);
    }

    /**
     * Set the year.
     */
    public function forYear(int $year): static
    {
        return $this->state(fn (array $attributes) => [
            'year' => $year,
        ]);
    }

    /**
     * Mark as projected status.
     */
    public function projected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'projected',
        ]);
    }

    /**
     * Mark as completed status.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }
}
