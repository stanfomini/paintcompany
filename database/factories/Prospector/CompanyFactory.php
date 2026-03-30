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
}
