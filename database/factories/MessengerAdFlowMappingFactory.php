<?php

namespace Database\Factories;

use App\Models\MessengerAdFlowMapping;
use App\Models\MessengerBotFlow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MessengerAdFlowMapping>
 */
class MessengerAdFlowMappingFactory extends Factory
{
    protected $model = MessengerAdFlowMapping::class;

    public function definition(): array
    {
        return [
            'facebook_ad_id' => (string) fake()->unique()->numberBetween(100000000, 999999999),
            'flow_id' => MessengerBotFlow::factory(),
            'facebook_ad_name' => fake()->words(3, true).' Ad',
            'facebook_campaign_name' => fake()->words(2, true).' Campaign',
            'facebook_adset_name' => fake()->words(2, true).' AdSet',
            'facebook_ad_status' => fake()->randomElement(['ACTIVE', 'PAUSED']),
            'last_synced_at' => now(),
        ];
    }
}
