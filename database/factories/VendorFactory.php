<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Vendor>
 */
class VendorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_setting_id' => CompanySetting::getInstance()->id,
            'name' => fake()->company(),
            'contact_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'category' => Vendor::CATEGORY_SUPPLIER,
            'is_active' => true,
        ];
    }

    /**
     * Subcontractor vendor.
     */
    public function subcontractor(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => Vendor::CATEGORY_SUBCONTRACTOR,
        ]);
    }

    /**
     * Service provider vendor.
     */
    public function service(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => Vendor::CATEGORY_SERVICE,
        ]);
    }

    /**
     * Inactive vendor.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Vendor with full compliance (W9, insurance, workers comp, hold harmless).
     */
    public function compliant(): static
    {
        return $this->state(fn (array $attributes) => [
            'w9_on_file' => true,
            'hold_harmless' => true,
            'certificate_of_insurance' => true,
            'insurance_expiration' => now()->addYear(),
            'additional_insured' => true,
            'workers_comp' => true,
            'workers_comp_expiration' => now()->addYear(),
        ]);
    }

    /**
     * Vendor with expired insurance.
     */
    public function expiredInsurance(): static
    {
        return $this->state(fn (array $attributes) => [
            'certificate_of_insurance' => true,
            'insurance_expiration' => now()->subMonth(),
        ]);
    }

    /**
     * Vendor with expired workers comp.
     */
    public function expiredWorkersComp(): static
    {
        return $this->state(fn (array $attributes) => [
            'workers_comp' => true,
            'workers_comp_expiration' => now()->subMonth(),
        ]);
    }

    /**
     * Vendor with full address.
     */
    public function withAddress(): static
    {
        return $this->state(fn (array $attributes) => [
            'address_line_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'zip' => fake()->postcode(),
        ]);
    }
}
