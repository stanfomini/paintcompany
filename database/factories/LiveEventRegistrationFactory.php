<?php

namespace Database\Factories;

use App\Models\LiveEvent;
use App\Models\LiveEventRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LiveEventRegistration>
 */
class LiveEventRegistrationFactory extends Factory
{
    protected $model = LiveEventRegistration::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'live_event_id' => LiveEvent::factory(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'attended' => false,
        ];
    }
}
