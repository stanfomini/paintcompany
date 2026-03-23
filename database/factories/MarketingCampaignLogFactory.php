<?php

namespace Database\Factories;

use App\Models\MarketingCampaignLog;
use App\Models\MarketingStrategy;
use Illuminate\Database\Eloquent\Factories\Factory;

class MarketingCampaignLogFactory extends Factory
{
    protected $model = MarketingCampaignLog::class;

    public function definition(): array
    {
        return [
            'marketing_strategy_id' => MarketingStrategy::factory(),
            'trigger_type' => fake()->randomElement(['job_completed', 'scheduled', 'manual']),
            'action' => fake()->randomElement(['sms_sent', 'campaign_queued', 'postcard_queued']),
            'status' => 'queued',
        ];
    }
}
