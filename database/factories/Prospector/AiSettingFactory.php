<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\AiSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiSetting>
 */
class AiSettingFactory extends Factory
{
    protected $model = AiSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'xai',
            'model' => 'grok-3-mini-fast',
            'api_key' => 'xai-test-key-1234567890',
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }

    public function unconfigured(): static
    {
        return $this->state(fn () => ['api_key' => null, 'enabled' => false]);
    }
}
