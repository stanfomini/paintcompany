<?php

namespace Database\Factories;

use App\Models\Vendor;
use App\Models\VendorDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VendorDocument>
 */
class VendorDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'name' => fake()->sentence(3),
            'type' => VendorDocument::TYPE_OTHER,
            'file_path' => 'vendor-documents/'.fake()->uuid().'.pdf',
            'original_filename' => fake()->word().'.pdf',
            'status' => VendorDocument::STATUS_PENDING,
        ];
    }

    public function w9(): static
    {
        return $this->state(fn () => [
            'name' => 'W-9 Form',
            'type' => VendorDocument::TYPE_W9,
        ]);
    }

    public function insurance(): static
    {
        return $this->state(fn () => [
            'name' => 'Certificate of Insurance',
            'type' => VendorDocument::TYPE_INSURANCE,
            'expires_at' => now()->addYear(),
        ]);
    }

    public function workersComp(): static
    {
        return $this->state(fn () => [
            'name' => 'Workers Comp Certificate',
            'type' => VendorDocument::TYPE_WORKERS_COMP,
            'expires_at' => now()->addYear(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => VendorDocument::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);
    }
}
