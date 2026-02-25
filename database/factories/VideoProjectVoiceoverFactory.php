<?php

namespace Database\Factories;

use App\Models\VideoProject;
use App\Models\VideoProjectVoiceover;
use App\Models\VideoRecording;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoProjectVoiceover>
 */
class VideoProjectVoiceoverFactory extends Factory
{
    protected $model = VideoProjectVoiceover::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_project_id' => VideoProject::factory(),
            'video_recording_id' => VideoRecording::factory()->ready(),
            'start_ms' => 0,
            'volume' => 100,
            'sort_order' => 0,
        ];
    }
}
