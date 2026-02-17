<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VideoEmail;
use App\Models\VideoRecording;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoEmail>
 */
class VideoEmailFactory extends Factory
{
    protected $model = VideoEmail::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_recording_id' => VideoRecording::factory()->ready(),
            'sent_by' => User::factory(),
            'recipient_email' => fake()->safeEmail(),
            'recipient_name' => fake()->optional(0.7)->name(),
            'subject' => fake()->sentence(4),
            'message' => fake()->optional(0.5)->paragraph(),
        ];
    }

    public function opened(): static
    {
        return $this->state(fn () => [
            'opened_at' => now()->subMinutes(30),
        ]);
    }

    public function clicked(): static
    {
        return $this->state(fn () => [
            'opened_at' => now()->subMinutes(30),
            'clicked_at' => now()->subMinutes(15),
        ]);
    }
}
