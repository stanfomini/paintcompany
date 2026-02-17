<?php

namespace Database\Factories;

use App\Models\EmployeeProfile;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    public function definition(): array
    {
        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'clock_in' => now()->startOfDay()->addHours(8),
            'status' => 'active',
        ];
    }

    /**
     * Entry with clock out (completed shift).
     */
    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $clockIn = $attributes['clock_in'] ?? now()->startOfDay()->addHours(8);
            $clockOut = (clone $clockIn)->modify('+8 hours');

            return [
                'clock_out' => $clockOut,
                'total_hours' => 8.00,
                'status' => 'pending',
            ];
        });
    }

    /**
     * Entry currently on break.
     */
    public function onBreak(): static
    {
        return $this->state(fn (array $attributes) => [
            'break_start' => now()->subMinutes(10),
        ]);
    }

    /**
     * Approved entry.
     */
    public function approved(): static
    {
        return $this->completed()->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_by' => \App\Models\User::factory()->internal(),
            'approved_at' => now(),
        ]);
    }

    /**
     * Entry with GPS coordinates.
     */
    public function withGps(): static
    {
        return $this->state(fn (array $attributes) => [
            'clock_in_lat' => fake()->latitude(39.5, 40.1),
            'clock_in_lng' => fake()->longitude(-105.5, -104.5),
            'clock_in_address' => fake()->address(),
            'clock_in_geofence_status' => 'within',
        ]);
    }
}
