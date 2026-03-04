<?php

namespace Database\Factories;

use App\Models\AppointmentBooking;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AppointmentBooking>
 */
class AppointmentBookingFactory extends Factory
{
    protected $model = AppointmentBooking::class;

    public function definition(): array
    {
        $date = fake()->dateTimeBetween('+1 day', '+30 days');

        return [
            'estimator_id' => User::factory()->internal(),
            'status' => AppointmentBooking::STATUS_CONFIRMED,
            'prospect_first_name' => fake()->firstName(),
            'prospect_last_name' => fake()->lastName(),
            'prospect_email' => fake()->safeEmail(),
            'prospect_phone' => fake()->phoneNumber(),
            'prospect_address' => fake()->streetAddress(),
            'prospect_city' => fake()->city(),
            'prospect_state' => fake()->stateAbbr(),
            'prospect_zip' => fake()->postcode(),
            'prospect_description' => fake()->optional()->sentence(),
            'estimate_preference' => fake()->randomElement(['in_person', 'virtual']),
            'booked_date' => $date,
            'booked_start_time' => '09:00',
            'booked_end_time' => '10:00',
            'booked_timezone' => 'America/New_York',
            'booking_token' => (string) Str::uuid(),
            'confirmed_at' => now(),
            'booked_by' => 'prospect',
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentBooking::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentBooking::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancellation_reason' => fake()->sentence(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentBooking::STATUS_COMPLETED,
        ]);
    }

    public function noShow(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentBooking::STATUS_NO_SHOW,
        ]);
    }

    public function rescheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentBooking::STATUS_RESCHEDULED,
        ]);
    }

    public function withVendor(): static
    {
        return $this->state(fn (array $attributes) => [
            'vendor_id' => \App\Models\Vendor::factory(),
            'booked_by' => 'vendor',
        ]);
    }
}
