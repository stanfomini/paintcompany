<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\TwilioAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TwilioAccount>
 */
class TwilioAccountFactory extends Factory
{
    protected $model = TwilioAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Twilio',
            'account_sid' => 'AC'.fake()->regexify('[a-f0-9]{32}'),
            'auth_token' => fake()->regexify('[a-f0-9]{32}'),
            'is_primary' => false,
            'is_active' => true,
            'last_synced_at' => null,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function synced(): static
    {
        return $this->state(fn () => ['last_synced_at' => now()]);
    }
}
