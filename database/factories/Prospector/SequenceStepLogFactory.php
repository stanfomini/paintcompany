<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\SequenceEnrollment;
use App\Models\Prospector\SequenceStep;
use App\Models\Prospector\SequenceStepLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SequenceStepLog>
 */
class SequenceStepLogFactory extends Factory
{
    protected $model = SequenceStepLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => SequenceEnrollment::factory(),
            'step_id' => SequenceStep::factory(),
            'status' => 'pending',
            'result' => null,
            'executed_at' => null,
            'created_at' => now(),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => 'sent',
            'executed_at' => now(),
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn () => [
            'status' => 'delivered',
            'executed_at' => now(),
        ]);
    }

    public function failed(?array $result = null): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'executed_at' => now(),
            'result' => $result ?? ['error' => 'Delivery failed'],
        ]);
    }

    public function skipped(string $reason = 'condition not met'): static
    {
        return $this->state(fn () => [
            'status' => 'skipped',
            'executed_at' => now(),
            'result' => ['reason' => $reason],
        ]);
    }
}
