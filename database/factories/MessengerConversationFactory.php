<?php

namespace Database\Factories;

use App\Models\ChatThread;
use App\Models\MessengerBotFlow;
use App\Models\MessengerConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessengerConversationFactory extends Factory
{
    protected $model = MessengerConversation::class;

    public function definition(): array
    {
        return [
            'flow_id' => MessengerBotFlow::factory(),
            'thread_id' => ChatThread::factory(),
            'facebook_psid' => fake()->numerify('##########'),
            'current_step_id' => null,
            'status' => 'in_progress',
            'answers' => [],
            'fb_profile_data' => null,
            'customer_id' => null,
            'deal_id' => null,
            'entry_source' => null,
            'booking_token' => null,
            'booking_token_expires_at' => null,
            'started_at' => now(),
            'completed_at' => null,
            'last_activity_at' => now(),
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function abandoned(): static
    {
        return $this->state([
            'status' => 'abandoned',
        ]);
    }

    public function humanTakeover(): static
    {
        return $this->state([
            'status' => 'human_takeover',
        ]);
    }
}
