<?php

namespace Database\Factories;

use App\Models\ProjectPreview;
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
            'project_preview_id' => ProjectPreview::factory(),
            'phone' => fake()->e164PhoneNumber(),
            'current_state' => SmsConversation::STATE_INITIATED,
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
            'current_state' => SmsConversation::STATE_DORMANT,
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function humanTakeover(): static
    {
        return $this->state([
            'current_state' => SmsConversation::STATE_HUMAN_TAKEOVER,
            'status' => 'human_takeover',
        ]);
    }

    public function dormant(): static
    {
        return $this->state([
            'current_state' => SmsConversation::STATE_DORMANT,
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
