<?php

namespace Database\Factories;

use App\Models\ContractTemplate;
use App\Models\EmployeeContract;
use App\Models\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EmployeeContract>
 */
class EmployeeContractFactory extends Factory
{
    protected $model = EmployeeContract::class;

    public function definition(): array
    {
        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'title' => fake()->sentence(3),
            'content' => 'This is a contract for '.fake()->name().'. Terms and conditions apply.',
            'status' => 'draft',
            'token' => (string) Str::uuid(),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function signed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'signed',
            'sent_at' => now()->subDays(3),
            'viewed_at' => now()->subDays(2),
            'signed_at' => now()->subDay(),
            'signature_data' => 'data:image/png;base64,'.base64_encode(fake()->word()),
            'signature_type' => fake()->randomElement(['drawn', 'typed']),
            'signer_name' => fake()->name(),
            'signer_ip' => fake()->ipv4(),
            'signer_user_agent' => fake()->userAgent(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'sent_at' => now()->subMonths(2),
            'expires_at' => now()->subWeek(),
        ]);
    }

    public function withTemplate(): static
    {
        return $this->state(fn (array $attributes) => [
            'contract_template_id' => ContractTemplate::factory(),
        ]);
    }
}
