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
}
