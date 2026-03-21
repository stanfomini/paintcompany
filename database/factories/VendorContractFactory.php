<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorContract>
 */
class VendorContractFactory extends Factory
{
    protected $model = VendorContract::class;

    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'title' => fake()->sentence(3),
            'content' => '<p>'.fake()->paragraphs(3, true).'</p>',
            'status' => 'draft',
            'is_valid' => true,
            'created_by' => User::factory()->internal(),
        ];
    }

    /**
     * Contract with pending internal signatures status.
     */
    public function pendingInternal(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VendorContract::STATUS_PENDING_INTERNAL,
        ]);
    }

    /**
     * Contract with pending vendor signature status.
     */
    public function pendingVendor(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VendorContract::STATUS_PENDING_VENDOR,
        ]);
    }

    /**
     * Fully executed contract.
     */
    public function fullyExecuted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VendorContract::STATUS_FULLY_EXECUTED,
        ]);
    }

    /**
     * Expired contract.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
            'is_valid' => false,
        ]);
    }

    /**
     * Contract with an expiration date.
     */
    public function withExpiration(int $daysFromNow = 30): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->addDays($daysFromNow),
        ]);
    }
}
