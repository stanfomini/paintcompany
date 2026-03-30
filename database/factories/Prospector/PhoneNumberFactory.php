<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhoneNumber>
 */
class PhoneNumberFactory extends Factory
{
    protected $model = PhoneNumber::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $areaCode = fake()->randomElement(['303', '720', '719', '970', '830']);
        $number = '+1'.$areaCode.fake()->unique()->numerify('#######');

        return [
            'twilio_account_id' => TwilioAccount::factory(),
            'company_id' => null,
            'phone_number' => $number,
            'friendly_name' => $number,
            'area_code' => $areaCode,
            'twilio_sid' => 'PN'.fake()->unique()->regexify('[a-f0-9]{32}'),
            'role' => 'unassigned',
            'caller_id_override' => null,
            'capabilities' => ['voice' => true, 'sms' => true, 'mms' => true, 'fax' => false],
            'inbound_action' => 'forward_main',
            'inbound_forward_to' => null,
            'monthly_cost' => null,
            'is_active' => true,
            'last_used_at' => null,
        ];
    }

    public function forAccount(TwilioAccount $account): static
    {
        return $this->state(fn () => ['twilio_account_id' => $account->id]);
    }

    public function withAreaCode(string $areaCode): static
    {
        return $this->state(function () use ($areaCode) {
            $number = '+1'.$areaCode.fake()->unique()->numerify('#######');

            return [
                'phone_number' => $number,
                'friendly_name' => $number,
                'area_code' => $areaCode,
            ];
        });
    }

    public function main(): static
    {
        return $this->state(fn () => ['role' => 'main']);
    }

    public function localPresence(): static
    {
        return $this->state(fn () => ['role' => 'local_presence']);
    }

    public function shadow(): static
    {
        return $this->state(fn () => ['role' => 'shadow']);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function assigned(int $companyId): static
    {
        return $this->state(fn () => [
            'company_id' => $companyId,
            'role' => 'local_presence',
        ]);
    }
}
