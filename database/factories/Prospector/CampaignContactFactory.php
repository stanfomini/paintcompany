<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignContact>
 */
class CampaignContactFactory extends Factory
{
    protected $model = CampaignContact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'contact_id' => Contact::factory(),
            'status' => 'queued',
            'sort_order' => 0,
            'attempts' => 0,
            'last_attempted_at' => null,
            'last_disposition' => null,
            'added_at' => now(),
        ];
    }
}
