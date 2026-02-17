<?php

namespace Database\Factories;

use App\Models\EmployeeProfile;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    public function definition(): array
    {
        $startHour = fake()->numberBetween(6, 10);

        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'job_id' => null,
            'date' => fake()->dateTimeBetween('now', '+2 weeks'),
            'start_time' => sprintf('%02d:00', $startHour),
            'end_time' => sprintf('%02d:00', $startHour + 8),
            'break_duration' => 30,
            'status' => 'scheduled',
            'notes' => null,
            'created_by' => null,
        ];
    }

    /**
     * Schedule with confirmed status.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'confirmed',
        ]);
    }

    /**
     * Schedule with completed status.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'date' => fake()->dateTimeBetween('-2 weeks', 'yesterday'),
        ]);
    }

    /**
     * Schedule with cancelled status.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }

    /**
     * Schedule for today.
     */
    public function today(): static
    {
        return $this->state(fn (array $attributes) => [
            'date' => now()->toDateString(),
        ]);
    }

    /**
     * Schedule with a specific creator.
     */
    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
        ]);
    }
}
