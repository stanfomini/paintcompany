<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\User;
use App\Models\VideoRecording;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VideoRecording>
 */
class VideoRecordingFactory extends Factory
{
    protected $model = VideoRecording::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'status' => 'uploading',
            'disk' => 'video-recordings',
            'share_token' => Str::random(32),
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => 'processing',
            'original_path' => 'originals/'.Str::uuid().'.webm',
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => 'ready',
            'original_path' => 'originals/'.Str::uuid().'.webm',
            'video_path' => 'videos/'.Str::uuid().'.mp4',
            'thumbnail_path' => 'thumbnails/'.Str::uuid().'.jpg',
            'duration_seconds' => fake()->numberBetween(5, 120),
            'file_size_bytes' => fake()->numberBetween(500000, 50000000),
            'width' => 1280,
            'height' => 720,
            'mime_type' => 'video/mp4',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'original_path' => 'originals/'.Str::uuid().'.webm',
            'error_message' => 'Processing failed: FFmpeg error',
        ]);
    }

    public function forEstimate(Estimate $estimate): static
    {
        return $this->state(fn () => [
            'recordable_type' => Estimate::class,
            'recordable_id' => $estimate->id,
        ]);
    }
}
