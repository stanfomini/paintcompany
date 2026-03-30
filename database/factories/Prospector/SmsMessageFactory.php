<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Agent;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\SmsMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsMessage>
 */
class SmsMessageFactory extends Factory
{
    protected $model = SmsMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fromNumber = '+1'.fake()->randomElement(['303', '720', '719', '970']).fake()->numerify('#######');
        $toNumber = '+1'.fake()->randomElement(['303', '720', '719', '970']).fake()->numerify('#######');

        return [
            'company_id' => Company::factory(),
            'contact_id' => Contact::factory(),
            'agent_id' => Agent::factory(),
            'direction' => 'outbound',
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
            'body' => fake()->sentence(),
            'media_urls' => null,
            'twilio_sid' => 'SM'.fake()->regexify('[a-f0-9]{32}'),
            'status' => 'queued',
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn () => [
            'direction' => 'inbound',
            'status' => 'received',
        ]);
    }

    public function outbound(): static
    {
        return $this->state(fn () => [
            'direction' => 'outbound',
            'status' => 'queued',
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn () => [
            'direction' => 'outbound',
            'status' => 'delivered',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'direction' => 'outbound',
            'status' => 'failed',
        ]);
    }

    public function withMedia(): static
    {
        return $this->state(fn () => [
            'media_urls' => [
                'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages/MM123/Media/ME'.fake()->regexify('[a-f0-9]{32}'),
            ],
        ]);
    }
}
