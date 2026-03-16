<?php

namespace Database\Factories;

use App\Models\CallLog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CallLog>
 */
class CallLogFactory extends Factory
{
    protected $model = CallLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-30 days', 'now');
        $duration = fake()->numberBetween(10, 600);

        return [
            'ulid' => Str::ulid()->toBase32(),
            'twilio_call_sid' => 'CA'.Str::random(32),
            'direction' => 'inbound',
            'from_number' => '+1'.fake()->numerify('##########'),
            'to_number' => '+1'.fake()->numerify('##########'),
            'status' => 'completed',
            'duration_seconds' => $duration,
            'started_at' => $startedAt,
            'answered_at' => $startedAt,
            'ended_at' => now(),
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn () => ['direction' => 'inbound']);
    }

    public function outbound(): static
    {
        return $this->state(fn () => ['direction' => 'outbound']);
    }

    public function missed(): static
    {
        return $this->state(fn () => [
            'status' => 'no-answer',
            'answered_at' => null,
            'answered_by_user_id' => null,
            'duration_seconds' => 0,
        ]);
    }

    public function voicemail(): static
    {
        return $this->state(fn () => [
            'status' => 'voicemail',
            'answered_at' => null,
            'answered_by_user_id' => null,
            'recording_sid' => 'RE'.Str::random(32),
            'recording_url' => 'https://api.twilio.com/recordings/RE'.Str::random(32),
            'recording_duration_seconds' => fake()->numberBetween(5, 120),
            'voicemail_transcription' => fake()->sentence(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'duration_seconds' => fake()->numberBetween(30, 600),
        ]);
    }

    public function withRecording(): static
    {
        return $this->state(fn () => [
            'recording_sid' => 'RE'.Str::random(32),
            'recording_url' => 'https://api.twilio.com/recordings/RE'.Str::random(32),
            'recording_duration_seconds' => fake()->numberBetween(10, 300),
        ]);
    }
}
