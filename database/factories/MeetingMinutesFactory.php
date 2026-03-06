<?php

namespace Database\Factories;

use App\Models\Corporation;
use App\Models\MeetingMinutes;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingMinutes>
 */
class MeetingMinutesFactory extends Factory
{
    protected $model = MeetingMinutes::class;

    public function definition(): array
    {
        return [
            'corporation_id' => Corporation::factory(),
            'recorded_by' => User::factory()->internal(),
            'title' => fake()->sentence(4),
            'meeting_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'meeting_type' => 'annual',
            'summary_status' => 'pending',
        ];
    }

    public function withAudio(): static
    {
        return $this->state(fn () => [
            'audio_path' => 'meeting-audio/'.fake()->uuid().'.webm',
            'audio_duration' => fake()->randomFloat(1, 5, 120),
        ]);
    }

    public function withTranscript(): static
    {
        return $this->state(fn () => [
            'transcript' => fake()->paragraphs(5, true),
            'transcript_segments' => [
                ['start' => 0, 'end' => 30, 'text' => fake()->sentence()],
                ['start' => 30, 'end' => 60, 'text' => fake()->sentence()],
                ['start' => 60, 'end' => 90, 'text' => fake()->sentence()],
            ],
        ]);
    }

    public function withSummary(): static
    {
        return $this->state(fn () => [
            'executive_summary' => fake()->paragraphs(2, true),
            'summary_status' => 'completed',
        ]);
    }
}
