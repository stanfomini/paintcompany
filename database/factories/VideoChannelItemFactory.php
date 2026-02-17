<?php

namespace Database\Factories;

use App\Models\VideoChannel;
use App\Models\VideoChannelItem;
use App\Models\VideoRecording;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoChannelItem>
 */
class VideoChannelItemFactory extends Factory
{
    protected $model = VideoChannelItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'video_channel_id' => VideoChannel::factory(),
            'video_recording_id' => VideoRecording::factory()->ready(),
            'sort_order' => 0,
            'published_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'published_at' => now()->subHour(),
        ]);
    }
}
