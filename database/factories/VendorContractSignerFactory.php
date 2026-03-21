<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VendorContract;
use App\Models\VendorContractSigner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorContractSigner>
 */
class VendorContractSignerFactory extends Factory
{
    protected $model = VendorContractSigner::class;

    public function definition(): array
    {
        return [
            'vendor_contract_id' => VendorContract::factory(),
            'user_id' => User::factory()->internal(),
            'order' => 0,
        ];
    }

    /**
     * Signer who has already signed.
     */
    public function signed(): static
    {
        return $this->state(fn (array $attributes) => [
            'signed_at' => now(),
            'signature_data' => 'data:image/png;base64,'.fake()->sha256(),
            'signature_type' => 'drawn',
            'signer_ip' => fake()->ipv4(),
        ]);
    }
}
