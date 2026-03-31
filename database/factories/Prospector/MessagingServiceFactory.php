<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\MessagingService;
use App\Models\Prospector\TwilioAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessagingService>
 */
class MessagingServiceFactory extends Factory
{
    protected $model = MessagingService::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'twilio_account_id' => TwilioAccount::factory(),
            'friendly_name' => fake()->company().' Messaging',
            'status' => 'active',
        ];
    }

    public function withBrand(string $status = 'APPROVED'): static
    {
        return $this->state([
            'brand_sid' => 'BN'.fake()->regexify('[a-z0-9]{32}'),
            'brand_status' => $status,
            'brand_details' => [
                'business_name' => fake()->company(),
                'business_type' => 'LLC',
                'ein' => fake()->numerify('##-#######'),
            ],
        ]);
    }

    public function withCampaign(string $status = 'VERIFIED'): static
    {
        return $this->state([
            'campaign_sid' => 'QE'.fake()->regexify('[a-z0-9]{32}'),
            'campaign_status' => $status,
            'campaign_use_case' => 'MIXED',
            'campaign_details' => [
                'description' => 'Sales outreach and appointment confirmations',
                'message_samples' => ['Hi {name}, following up on your inquiry.', 'Your appointment is confirmed for {date}.'],
            ],
        ]);
    }

    public function fullyCompliant(): static
    {
        return $this->withBrand('APPROVED')->withCampaign('VERIFIED');
    }
}
