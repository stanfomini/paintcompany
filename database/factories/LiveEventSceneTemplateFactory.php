<?php

namespace Database\Factories;

use App\Models\LiveEventSceneTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LiveEventSceneTemplate>
 */
class LiveEventSceneTemplateFactory extends Factory
{
    protected $model = LiveEventSceneTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'scene_data' => LiveEventSceneTemplate::defaultLayouts()[0],
        ];
    }
}
