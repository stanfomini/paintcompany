<?php

namespace Database\Factories;

use App\Models\CrewSignedContract;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CrewSignedContract>
 */
class CrewSignedContractFactory extends Factory
{
    protected $model = CrewSignedContract::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->crew(),
            'contract_type' => CrewSignedContract::TYPE_CONTRACTOR_AGREEMENT,
            'contract_content' => fake()->paragraphs(5, true),
            'signature_data' => 'data:image/png;base64,'.base64_encode(fake()->text(100)),
            'signature_type' => fake()->randomElement(['drawn', 'typed']),
            'signer_name' => fake()->name(),
            'signer_email' => fake()->safeEmail(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'signed_at' => now(),
        ];
    }

    public function coloradoCompliance(): static
    {
        return $this->state(fn (array $attributes) => [
            'contract_type' => CrewSignedContract::TYPE_COLORADO_COMPLIANCE,
        ]);
    }

    public function contractorAgreement(): static
    {
        return $this->state(fn (array $attributes) => [
            'contract_type' => CrewSignedContract::TYPE_CONTRACTOR_AGREEMENT,
        ]);
    }

    public function backgroundCheck(): static
    {
        return $this->state(fn (array $attributes) => [
            'contract_type' => CrewSignedContract::TYPE_BACKGROUND_CHECK,
        ]);
    }

    public function typed(): static
    {
        return $this->state(fn (array $attributes) => [
            'signature_type' => 'typed',
            'signature_data' => fake()->name(),
        ]);
    }

    public function drawn(): static
    {
        return $this->state(fn (array $attributes) => [
            'signature_type' => 'drawn',
            'signature_data' => 'data:image/png;base64,'.base64_encode(fake()->text(100)),
        ]);
    }
}
