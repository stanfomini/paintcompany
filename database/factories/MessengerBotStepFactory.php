<?php

namespace Database\Factories;

use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessengerBotStepFactory extends Factory
{
    protected $model = MessengerBotStep::class;

    public function definition(): array
    {
        return [
            'flow_id' => MessengerBotFlow::factory(),
            'sort_order' => fake()->numberBetween(0, 10),
            'step_type' => MessengerBotStep::TYPE_TEXT_QUESTION,
            'message_text' => fake()->sentence(),
            'quick_reply_options' => null,
            'field_mapping' => null,
            'is_required' => true,
            'delay_seconds' => null,
            'next_step_id' => null,
        ];
    }

    public function greeting(): static
    {
        return $this->state([
            'step_type' => MessengerBotStep::TYPE_GREETING,
            'field_mapping' => null,
        ]);
    }

    public function textQuestion(?string $fieldMapping = null): static
    {
        return $this->state([
            'step_type' => MessengerBotStep::TYPE_TEXT_QUESTION,
            'field_mapping' => $fieldMapping,
        ]);
    }

    public function quickReply(?array $options = null, ?string $fieldMapping = null): static
    {
        return $this->state([
            'step_type' => MessengerBotStep::TYPE_QUICK_REPLY,
            'quick_reply_options' => $options ?? [
                ['label' => 'Option A', 'value' => 'option_a'],
                ['label' => 'Option B', 'value' => 'option_b'],
            ],
            'field_mapping' => $fieldMapping,
        ]);
    }

    public function delay(int $seconds = 3): static
    {
        return $this->state([
            'step_type' => MessengerBotStep::TYPE_DELAY,
            'delay_seconds' => $seconds,
            'field_mapping' => null,
        ]);
    }

    public function handoff(): static
    {
        return $this->state([
            'step_type' => MessengerBotStep::TYPE_HANDOFF,
            'field_mapping' => null,
        ]);
    }

    public function nextStep(int $stepId): static
    {
        return $this->state([
            'next_step_id' => $stepId,
        ]);
    }

    public function branchingQuickReply(array $options): static
    {
        return $this->state([
            'step_type' => MessengerBotStep::TYPE_QUICK_REPLY,
            'quick_reply_options' => $options,
        ]);
    }
}
