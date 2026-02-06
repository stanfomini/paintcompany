<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\EstimatePackage;
use App\Models\Job;
use App\Models\WarrantyReserve;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WarrantyReserve>
 */
class WarrantyReserveFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $warrantyYears = fake()->randomElement([1, 2, 3, 5]);
        $startDate = fake()->dateTimeBetween('-1 year', 'now');

        return [
            'estimate_id' => Estimate::factory(),
            'estimate_package_id' => EstimatePackage::factory(),
            'job_id' => null,
            'warranty_years' => $warrantyYears,
            'amount_reserved' => fake()->randomFloat(2, 50, 500),
            'amount_claimed' => 0,
            'warranty_starts_at' => $startDate,
            'warranty_expires_at' => (clone $startDate)->modify("+{$warrantyYears} years"),
            'status' => WarrantyReserve::STATUS_PENDING,
            'notes' => null,
        ];
    }

    /**
     * Set as active warranty.
     */
    public function active(): static
    {
        return $this->state(function (array $attributes) {
            $startDate = now()->subMonths(fake()->numberBetween(1, 6));
            $years = $attributes['warranty_years'] ?? 3;

            return [
                'status' => WarrantyReserve::STATUS_ACTIVE,
                'warranty_starts_at' => $startDate,
                'warranty_expires_at' => $startDate->copy()->addYears($years),
            ];
        });
    }

    /**
     * Set as expired warranty.
     */
    public function expired(): static
    {
        return $this->state(function (array $attributes) {
            $years = $attributes['warranty_years'] ?? 3;
            $startDate = now()->subYears($years)->subMonths(1);

            return [
                'status' => WarrantyReserve::STATUS_EXPIRED,
                'warranty_starts_at' => $startDate,
                'warranty_expires_at' => $startDate->copy()->addYears($years),
            ];
        });
    }

    /**
     * Set as fully claimed warranty.
     */
    public function fullyClaimed(): static
    {
        return $this->state(function (array $attributes) {
            $reserved = $attributes['amount_reserved'] ?? 200.00;

            return [
                'status' => WarrantyReserve::STATUS_FULLY_CLAIMED,
                'amount_claimed' => $reserved,
            ];
        });
    }

    /**
     * Set warranty with partial claims.
     */
    public function withClaims(float $claimedAmount): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_claimed' => $claimedAmount,
        ]);
    }

    /**
     * Set specific warranty years.
     */
    public function years(int $years): static
    {
        return $this->state(fn (array $attributes) => [
            'warranty_years' => $years,
        ]);
    }

    /**
     * Associate with a specific estimate.
     */
    public function forEstimate(Estimate $estimate): static
    {
        return $this->state(fn (array $attributes) => [
            'estimate_id' => $estimate->id,
        ]);
    }

    /**
     * Associate with a specific package.
     */
    public function forPackage(EstimatePackage $package): static
    {
        return $this->state(fn (array $attributes) => [
            'estimate_id' => $package->estimate_id,
            'estimate_package_id' => $package->id,
            'warranty_years' => $package->warranty_years ?? 3,
            'amount_reserved' => (float) ($package->warranty_price ?? 150.00),
        ]);
    }

    /**
     * Associate with a specific job.
     */
    public function forJob(Job $job): static
    {
        return $this->state(fn (array $attributes) => [
            'job_id' => $job->id,
        ]);
    }

    /**
     * Set warranty expiring soon.
     */
    public function expiringSoon(int $daysUntilExpiry = 14): static
    {
        return $this->state(function (array $attributes) use ($daysUntilExpiry) {
            $years = $attributes['warranty_years'] ?? 3;
            $expiresAt = now()->addDays($daysUntilExpiry);
            $startsAt = $expiresAt->copy()->subYears($years);

            return [
                'status' => WarrantyReserve::STATUS_ACTIVE,
                'warranty_starts_at' => $startsAt,
                'warranty_expires_at' => $expiresAt,
            ];
        });
    }
}
