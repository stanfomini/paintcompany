<?php

namespace Database\Factories;

use App\Models\CrewW9Form;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CrewW9Form>
 */
class CrewW9FormFactory extends Factory
{
    protected $model = CrewW9Form::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->crew(),
            'name' => fake()->name(),
            'business_name' => fake()->optional()->company(),
            'tax_classification' => 'individual',
            'llc_tax_classification' => null,
            'other_classification' => null,
            'exempt_payee_code' => null,
            'fatca_exemption_code' => null,
            'address' => fake()->streetAddress(),
            'city_state_zip' => fake()->city().', '.fake()->stateAbbr().' '.fake()->postcode(),
            'requester_name' => null,
            'account_numbers' => null,
            'tin_type' => 'ssn',
            'tin' => fake()->numerify('###-##-####'),
            'signature_data' => 'data:image/png;base64,'.base64_encode(fake()->text(100)),
            'signature_type' => 'drawn',
            'signed_at' => now(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    public function withEin(): static
    {
        return $this->state(fn (array $attributes) => [
            'tin_type' => 'ein',
            'tin' => fake()->numerify('##-#######'),
        ]);
    }

    public function asLlc(): static
    {
        return $this->state(fn (array $attributes) => [
            'tax_classification' => 'llc',
            'llc_tax_classification' => fake()->randomElement(['C', 'S', 'P']),
            'business_name' => fake()->company().' LLC',
        ]);
    }

    public function asCorporation(): static
    {
        return $this->state(fn (array $attributes) => [
            'tax_classification' => 'c_corp',
            'business_name' => fake()->company().' Inc.',
            'tin_type' => 'ein',
            'tin' => fake()->numerify('##-#######'),
        ]);
    }
}
