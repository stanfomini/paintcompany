<?php

namespace Database\Factories;

use App\Models\PlannerDelegation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PlannerDelegation>
 */
class PlannerDelegationFactory extends Factory
{
    protected $model = PlannerDelegation::class;

    public function definition(): array
    {
        return [
            'grantor_id' => User::factory()->internal(),
            'target_user_id' => User::factory()->internal(),
            'delegate_id' => User::factory()->internal(),
            'access_level' => 'view',
            'is_permanent' => true,
        ];
    }

    public function viewAccess(): static
    {
        return $this->state(['access_level' => 'view']);
    }

    public function actAccess(): static
    {
        return $this->state(['access_level' => 'act']);
    }

    public function fullAccess(): static
    {
        return $this->state(['access_level' => 'full']);
    }

    public function permanent(): static
    {
        return $this->state(['is_permanent' => true, 'expires_at' => null]);
    }

    public function temporary(?\Carbon\Carbon $until = null): static
    {
        return $this->state([
            'is_permanent' => false,
            'expires_at' => $until ?? now()->addWeek(),
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'is_permanent' => false,
            'expires_at' => now()->subDay(),
        ]);
    }
}
