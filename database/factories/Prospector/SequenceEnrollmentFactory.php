<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Contact;
use App\Models\Prospector\Sequence;
use App\Models\Prospector\SequenceEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SequenceEnrollment>
 */
class SequenceEnrollmentFactory extends Factory
{
    protected $model = SequenceEnrollment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sequence_id' => Sequence::factory(),
            'contact_id' => Contact::factory(),
            'current_step_id' => null,
            'status' => 'active',
            'exit_reason' => null,
            'next_execution_at' => now(),
            'enrolled_at' => now(),
            'completed_at' => null,
            'enrolled_by' => null,
        ];
    }

    public function forSequence(Sequence $sequence): static
    {
        return $this->state(fn () => ['sequence_id' => $sequence->id]);
    }

    public function forContact(Contact $contact): static
    {
        return $this->state(fn () => ['contact_id' => $contact->id]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'completed_at' => now(),
            'next_execution_at' => null,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn () => [
            'status' => 'paused',
            'next_execution_at' => null,
        ]);
    }

    public function exited(string $reason = 'manual'): static
    {
        return $this->state(fn () => [
            'status' => 'exited',
            'exit_reason' => $reason,
            'next_execution_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'next_execution_at' => null,
        ]);
    }

    public function due(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'next_execution_at' => now()->subMinute(),
        ]);
    }

    public function notDueYet(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'next_execution_at' => now()->addHour(),
        ]);
    }
}
