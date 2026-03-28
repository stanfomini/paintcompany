<?php

namespace Database\Factories;

use App\Models\LiveEvent;
use App\Models\LiveEventResource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LiveEventResource>
 */
class LiveEventResourceFactory extends Factory
{
    protected $model = LiveEventResource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'live_event_id' => LiveEvent::factory(),
            'title' => fake()->words(3, true),
            'url' => fake()->url(),
            'type' => 'link',
            'is_pinned' => true,
            'sort_order' => 0,
        ];
    }
}
