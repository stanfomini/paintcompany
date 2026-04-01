<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Company;
use App\Models\Prospector\TwilioAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'twilio_account_id' => TwilioAccount::factory(),
            'main_phone_number_id' => null,
            'timezone' => 'America/Denver',
            'business_hours_start' => '08:00',
            'business_hours_end' => '17:00',
            'business_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'settings' => null,
            'is_active' => true,
            'color' => fake()->randomElement(['#3B82F6', '#EF4444', '#10B981', '#F59E0B', '#8B5CF6', '#EC4899', '#06B6D4', '#F97316']),
        ];
    }

    public function forAccount(TwilioAccount $account): static
    {
        return $this->state(fn () => ['twilio_account_id' => $account->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function withIvr(array $businessHoursOptions = [], array $afterHoursOptions = []): static
    {
        return $this->state(fn () => [
            'settings' => [
                'ivr' => [
                    'enabled' => true,
                    'business_hours' => [
                        'greeting' => 'Thank you for calling. Press 1 to speak with a team member.',
                        'options' => $businessHoursOptions ?: [
                            '1' => ['action' => 'route_agent', 'label' => 'Speak with team member'],
                            '2' => ['action' => 'voicemail', 'label' => 'Leave a message'],
                        ],
                        'no_input_action' => 'route_agent',
                    ],
                    'after_hours' => [
                        'greeting' => 'We are currently closed. Press 1 to leave a message.',
                        'options' => $afterHoursOptions ?: [
                            '1' => ['action' => 'voicemail', 'label' => 'Leave a message'],
                        ],
                        'no_input_action' => 'voicemail',
                    ],
                ],
            ],
        ]);
    }
}
