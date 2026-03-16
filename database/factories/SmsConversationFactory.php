<?php

namespace Database\Factories;

use App\Models\SmsConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsConversation>
 */
class SmsConversationFactory extends Factory
{
    protected $model = SmsConversation::class;

    public function definition(): array
    {
        return [
            'phone' => fake()->e164PhoneNumber(),
            'status' => 'in_progress',
            'answers' => [],
            'message_count' => 0,
            'photo_count' => 0,
            'last_message_at' => now(),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function humanTakeover(): static
    {
        return $this->state([
            'status' => 'human_takeover',
        ]);
    }

    public function dormant(): static
    {
        return $this->state([
            'status' => 'completed',
        ]);
    }

    public function abandoned(): static
    {
        return $this->state([
            'status' => 'abandoned',
        ]);
    }
}
