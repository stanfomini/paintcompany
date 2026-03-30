<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $areaCode = fake()->randomElement(['303', '720', '719', '970', '830']);
        $phone = '+1'.$areaCode.fake()->unique()->numerify('#######');

        return [
            'company_id' => Company::factory(),
            'external_id' => null,
            'source' => 'manual',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => $phone,
            'phone_2' => null,
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'zip' => fake()->postcode(),
            'timezone' => 'America/Denver',
            'tags' => null,
            'custom_fields' => null,
            'do_not_call' => false,
            'last_contacted_at' => null,
            'last_disposition' => null,
            'total_calls' => 0,
            'total_sms' => 0,
            'priority' => 0,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }

    public function dnc(): static
    {
        return $this->state(fn () => ['do_not_call' => true]);
    }

    public function withPhone(string $phone): static
    {
        return $this->state(fn () => ['phone' => $phone]);
    }

    public function highPriority(): static
    {
        return $this->state(fn () => ['priority' => 100]);
    }

    public function fromWebhook(): static
    {
        return $this->state(fn () => [
            'source' => 'webhook',
            'external_id' => fake()->uuid(),
        ]);
    }
}
