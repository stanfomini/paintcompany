<?php

namespace Database\Factories;

use App\Models\Estimate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimateSignature>
 */
class EstimateSignatureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estimate_id' => Estimate::factory(),
            'signer_name' => fake()->name(),
            'signer_email' => fake()->safeEmail(),
            'signature_data' => 'data:image/png;base64,'.base64_encode(fake()->sha256()),
            'signature_type' => fake()->randomElement(['drawn', 'typed']),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'accepted_terms' => true,
            'signed_at' => now(),
        ];
    }

    /**
     * Set the signature type to drawn.
     */
    public function drawn(): static
    {
        return $this->state(fn (array $attributes) => [
            'signature_type' => 'drawn',
            'signature_data' => 'data:image/png;base64,'.base64_encode(fake()->sha256()),
        ]);
    }

    /**
     * Set the signature type to typed.
     */
    public function typed(): static
    {
        return $this->state(fn (array $attributes) => [
            'signature_type' => 'typed',
            'signature_data' => fake()->name(),
        ]);
    }

    /**
     * Associate with a specific estimate.
     */
    public function forEstimate(Estimate $estimate): static
    {
        return $this->state(fn (array $attributes) => [
            'estimate_id' => $estimate->id,
        ]);
    }
}
