<?php

namespace Database\Factories;

use App\Models\RecordingInvite;
use App\Models\User;
use App\Models\VideoRecording;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecordingInvite>
 */
class RecordingInviteFactory extends Factory
{
    protected $model = RecordingInvite::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => Str::random(64),
            'title' => fake()->sentence(3),
            'max_duration_seconds' => 120,
            'expires_at' => now()->addDay(),
            'status' => 'pending',
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => now()->subHour(),
            'status' => 'expired',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'video_recording_id' => VideoRecording::factory()->ready(),
        ]);
    }

    public function withInstructions(): static
    {
        return $this->state(fn () => [
            'instructions' => fake()->paragraph(),
        ]);
    }
}
