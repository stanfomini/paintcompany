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

        $laborCost = round($price * fake()->randomFloat(2, 0.15, 0.30), 2);
        $cleanupCost = round($laborCost * 0.15, 2);
        $sundriesCost = round($laborCost * 0.05, 2);
        $materialTax = round($material * 0.08, 2);
        $laborTax = round($laborCost * 0.03, 2);
        $addonCost = round($price * fake()->randomFloat(2, 0, 0.05), 2);
        $totalCogs = $laborCost + $cleanupCost + $sundriesCost + $material + $materialTax + $laborTax + $addonCost;

        $laborHours = round(fake()->randomFloat(2, 8, 80), 2);
        $cleanupHours = round($laborHours * 0.15, 2);

        $grossProfit = round($price - $totalCogs, 2);
        $grossProfitPercent = $price > 0 ? round($grossProfit / $price * 100, 2) : 0;

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
            // COGS decomposition
            'labor_cost' => $laborCost,
            'cleanup_cost' => $cleanupCost,
            'sundries_cost' => $sundriesCost,
            'material_tax' => $materialTax,
            'labor_tax' => $laborTax,
            'addon_cost' => $addonCost,
            'items_vendor_cost' => 0,
            'warranty_reserve' => 0,
            'total_cogs' => $totalCogs,
            // Hours
            'labor_hours' => $laborHours,
            'cleanup_hours' => $cleanupHours,
            'total_hours' => round($laborHours + $cleanupHours, 2),
            // Profit snapshot
            'projected_gross_profit' => $grossProfit,
            'projected_gross_profit_percent' => $grossProfitPercent,
            'crew_bonus' => 0,
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

    /**
     * Add actual cost tracking data.
     */
    public function withActuals(): static
    {
        return $this->state(function (array $attributes) {
            $actualMaterial = round(($attributes['material_cost'] ?? 500) * fake()->randomFloat(2, 0.8, 1.2), 2);
            $actualLabor = round(($attributes['labor_cost'] ?? 1000) * fake()->randomFloat(2, 0.8, 1.2), 2);
            $actualSub = round(fake()->randomFloat(2, 0, 500), 2);
            $actualOther = round(fake()->randomFloat(2, 0, 200), 2);

            return [
                'actual_material_cost' => $actualMaterial,
                'actual_labor_cost' => $actualLabor,
                'actual_subcontractor_cost' => $actualSub,
                'actual_other_cost' => $actualOther,
                'actual_total_cost' => round($actualMaterial + $actualLabor + $actualSub + $actualOther, 2),
                'actual_notes' => fake()->optional(0.5)->sentence(),
            ];
        });
    }

    /**
     * Set payment summary to fully paid.
     */
    public function fullyPaid(): static
    {
        return $this->state(fn (array $attributes) => [
            'total_invoiced' => $attributes['price'] ?? 5000,
            'total_collected' => $attributes['price'] ?? 5000,
            'balance_due' => 0,
        ]);
    }
}
