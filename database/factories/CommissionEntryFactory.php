<?php

namespace Database\Factories;

use App\Models\CommissionEntry;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<CommissionEntry>
 */
class CommissionEntryFactory extends Factory
{
    protected $model = CommissionEntry::class;

    public function definition(): array
    {
        $basisAmount = fake()->randomFloat(2, 1000, 50000);
        $rate = 0.05;

        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'commissionable_type' => EmployeeProfile::class,
            'commissionable_id' => null, // resolved via afterCreating below
            'commission_type' => 'revenue_percentage',
            'basis_amount' => $basisAmount,
            'rate' => $rate,
            'amount' => round($basisAmount * $rate, 2),
            'status' => 'pending',
            'earned_date' => now()->toDateString(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (CommissionEntry $entry) {
            // Auto-sync commissionable_id from employee_profile_id when using default factory
            if ($entry->commissionable_type === EmployeeProfile::class && ! $entry->commissionable_id && $entry->employee_profile_id) {
                $entry->update(['commissionable_id' => $entry->employee_profile_id]);
            }
        });
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_by' => User::factory()->internal(),
            'approved_at' => now(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'approved_by' => User::factory()->internal(),
            'approved_at' => now()->subDay(),
        ]);
    }

    public function reversed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'reversed',
            'notes' => 'Reversed: Test reversal',
        ]);
    }

    public function revenuePercentage(float $rate = 0.05): static
    {
        return $this->state(function (array $attributes) use ($rate) {
            $basis = (float) ($attributes['basis_amount'] ?? 10000);

            return [
                'commission_type' => 'revenue_percentage',
                'rate' => $rate,
                'amount' => round($basis * $rate, 2),
            ];
        });
    }

    public function profitPercentage(float $rate = 0.10): static
    {
        return $this->state(function (array $attributes) use ($rate) {
            $basis = (float) ($attributes['basis_amount'] ?? 5000);

            return [
                'commission_type' => 'profit_percentage',
                'rate' => $rate,
                'amount' => round($basis * $rate, 2),
            ];
        });
    }

    public function flat(float $flatAmount = 200): static
    {
        return $this->state(fn (array $attributes) => [
            'commission_type' => 'flat',
            'basis_amount' => 0,
            'rate' => 0,
            'amount' => $flatAmount,
        ]);
    }

    public function forLead(): static
    {
        return $this->state(fn (array $attributes) => [
            'source_label' => 'Lead Commission',
        ]);
    }

    public function forEstimate(): static
    {
        return $this->state(fn (array $attributes) => [
            'source_label' => 'Estimate Commission',
        ]);
    }

    public function forJob(): static
    {
        return $this->state(fn (array $attributes) => [
            'source_label' => 'Job Commission',
        ]);
    }

    /**
     * Create a commission entry for any commissionable entity (not just EmployeeProfile).
     */
    public function forCommissionable(Model $commissionable, ?int $employeeProfileId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'commissionable_type' => get_class($commissionable),
            'commissionable_id' => $commissionable->getKey(),
            'employee_profile_id' => $employeeProfileId ?? ($commissionable instanceof EmployeeProfile ? $commissionable->getKey() : null),
        ]);
    }
}
