<?php

namespace Database\Factories;

use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsBotStep>
 */
class SmsBotStepFactory extends Factory
{
    protected $model = SmsBotStep::class;

    public function definition(): array
    {
        return [
            'flow_id' => SmsBotFlow::factory(),
            'sort_order' => fake()->numberBetween(1, 100),
            'step_type' => SmsBotStep::TYPE_MESSAGE,
            'message_text' => fake()->sentence(),
            'media_url' => null,
            'source_step_id' => null,
            'wait_for_media' => false,
            'quick_reply_options' => null,
            'field_mapping' => null,
            'is_required' => true,
            'delay_seconds' => null,
            'next_step_id' => null,
        ];
    }

    public function greeting(): static
    {
        return $this->state(['step_type' => SmsBotStep::TYPE_GREETING]);
    }

    public function message(): static
    {
        return $this->state(['step_type' => SmsBotStep::TYPE_MESSAGE]);
    }

    public function question(): static
    {
        return $this->state(['step_type' => SmsBotStep::TYPE_QUESTION]);
    }

    public function photoPrompt(): static
    {
        return $this->state([
            'step_type' => SmsBotStep::TYPE_PHOTO_PROMPT,
            'wait_for_media' => true,
        ]);
    }

    public function mediaMessage(): static
    {
        return $this->state([
            'step_type' => SmsBotStep::TYPE_MEDIA_MESSAGE,
            'media_url' => fake()->imageUrl(),
        ]);
    }

    public function aiVisualization(): static
    {
        return $this->state([
            'step_type' => SmsBotStep::TYPE_AI_VISUALIZATION,
        ]);
    }

    public function quickReply(array $options = []): static
    {
        return $this->state([
            'step_type' => SmsBotStep::TYPE_QUICK_REPLY,
            'quick_reply_options' => $options ?: [
                ['label' => 'Yes', 'value' => 'yes'],
                ['label' => 'No', 'value' => 'no'],
            ],
        ]);
    }

    public function delay(int $seconds = 5): static
    {
        return $this->state([
            'step_type' => SmsBotStep::TYPE_DELAY,
            'delay_seconds' => $seconds,
        ]);
    }

    public function handoff(): static
    {
        return $this->state(['step_type' => SmsBotStep::TYPE_HANDOFF]);
    }
}
