<?php

namespace Database\Factories;

use App\Models\Keyword;
use App\Models\KeywordPageAssignment;
use App\Models\Service;
use App\Models\ServiceArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KeywordPageAssignment>
 */
class KeywordPageAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'keyword_id' => Keyword::factory(),
            'service_id' => Service::factory(),
            'service_area_id' => ServiceArea::factory(),
            'role' => 'secondary',
            'custom_sentence' => null,
            'position' => null,
            'impressions' => null,
            'clicks' => null,
            'ctr' => null,
            'last_synced_at' => null,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['role' => 'primary']);
    }

    public function secondary(): static
    {
        return $this->state(fn () => ['role' => 'secondary']);
    }

    public function supporting(): static
    {
        return $this->state(fn () => ['role' => 'supporting']);
    }

    /**
     * Scope this assignment to a specific service+area combo page.
     */
    public function forCombo(Service $service, ServiceArea $area): static
    {
        return $this->state(fn () => [
            'service_id' => $service->id,
            'service_area_id' => $area->id,
        ]);
    }

    /**
     * Scope this assignment to apply to a service across all areas (service-only fallback).
     */
    public function forServiceOnly(Service $service): static
    {
        return $this->state(fn () => [
            'service_id' => $service->id,
            'service_area_id' => null,
        ]);
    }

    /**
     * Scope this assignment to apply to an area across all services (area-only fallback).
     */
    public function forAreaOnly(ServiceArea $area): static
    {
        return $this->state(fn () => [
            'service_id' => null,
            'service_area_id' => $area->id,
        ]);
    }
}
