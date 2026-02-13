<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\User;
use App\Models\VideoCall;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VideoCall>
 */
class VideoCallFactory extends Factory
{
    protected $model = VideoCall::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_id' => Job::factory(),
            'initiated_by' => User::factory(),
            'room_id' => Str::uuid()->toString(),
            'status' => 'waiting',
            'participants' => null,
            'started_at' => null,
            'ended_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'started_at' => now(),
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn () => [
            'status' => 'ended',
            'started_at' => now()->subMinutes(15),
            'ended_at' => now(),
            'duration_seconds' => 900,
        ]);
    }

    public function withRecording(): static
    {
        return $this->state(fn () => [
            'status' => 'ended',
            'recording_path' => 'recordings/1/1/playlist.m3u8',
            'recording_disk' => 'video-recordings',
            'started_at' => now()->subMinutes(15),
            'ended_at' => now(),
            'duration_seconds' => 900,
        ]);
    }
}
