<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Sequence;
use App\Models\Prospector\SequenceStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SequenceStep>
 */
class SequenceStepFactory extends Factory
{
    protected $model = SequenceStep::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sequence_id' => Sequence::factory(),
            'sort_order' => 0,
            'type' => 'sms',
            'delay_value' => null,
            'delay_unit' => null,
            'config' => ['body' => fake()->sentence()],
        ];
    }

    public function forSequence(Sequence $sequence): static
    {
        return $this->state(fn () => ['sequence_id' => $sequence->id]);
    }

    public function sms(?string $body = null): static
    {
        return $this->state(fn () => [
            'type' => 'sms',
            'config' => ['body' => $body ?? fake()->sentence()],
        ]);
    }

    public function email(?string $subject = null, ?string $template = null): static
    {
        return $this->state(fn () => [
            'type' => 'email',
            'config' => [
                'subject' => $subject ?? fake()->sentence(4),
                'template' => $template ?? fake()->paragraph(),
            ],
        ]);
    }

    public function rvm(int $audioFileId = 1): static
    {
        return $this->state(fn () => [
            'type' => 'rvm',
            'config' => ['audio_file_id' => $audioFileId],
        ]);
    }

    public function call(?string $script = null): static
    {
        return $this->state(fn () => [
            'type' => 'call',
            'config' => ['script' => $script ?? fake()->paragraph()],
        ]);
    }

    public function wait(int $value = 1, string $unit = 'days'): static
    {
        return $this->state(fn () => [
            'type' => 'wait',
            'delay_value' => $value,
            'delay_unit' => $unit,
            'config' => [],
        ]);
    }

    public function condition(string $field = 'disposition', string $operator = 'equals', string $value = 'interested'): static
    {
        return $this->state(fn () => [
            'type' => 'condition',
            'config' => [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ],
        ]);
    }

    public function withDelay(int $value, string $unit = 'hours'): static
    {
        return $this->state(fn () => [
            'delay_value' => $value,
            'delay_unit' => $unit,
        ]);
    }

    public function atOrder(int $order): static
    {
        return $this->state(fn () => ['sort_order' => $order]);
    }
}
