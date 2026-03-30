<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Company;
use App\Models\Prospector\Sequence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sequence>
 */
class SequenceFactory extends Factory
{
    protected $model = Sequence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->words(3, true).' Sequence',
            'description' => fake()->optional()->sentence(),
            'status' => 'draft',
            'trigger_type' => 'manual',
            'trigger_config' => null,
            'exit_conditions' => null,
            'business_hours_only' => false,
            'created_by' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function paused(): static
    {
        return $this->state(fn () => ['status' => 'paused']);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft']);
    }

    public function businessHoursOnly(): static
    {
        return $this->state(fn () => ['business_hours_only' => true]);
    }

    public function withTrigger(string $type, ?array $config = null): static
    {
        return $this->state(fn () => [
            'trigger_type' => $type,
            'trigger_config' => $config,
        ]);
    }
}
