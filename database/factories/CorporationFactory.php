<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use App\Models\Corporation;
use App\Models\CorporationOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Corporation>
 */
class CorporationFactory extends Factory
{
    protected $model = Corporation::class;

    public function definition(): array
    {
        return [
            'company_setting_id' => CompanySetting::firstOrCreate([], [
                'company_name' => 'Test Company',
            ])->id,
            'name' => fake()->company().' LLC',
            'entity_type' => 'LLC',
            'state_of_formation' => fake()->stateAbbr(),
            'ein' => fake()->numerify('##-#######'),
            'formation_date' => fake()->dateTimeBetween('-10 years', '-1 year'),
            'registered_agent' => fake()->name(),
            'address_line_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'zip' => fake()->postcode(),
            'is_active' => true,
        ];
    }

    public function sCorp(): static
    {
        return $this->state(fn () => [
            'entity_type' => 'S-Corp',
            'name' => fake()->company().' Inc.',
        ]);
    }

    public function cCorp(): static
    {
        return $this->state(fn () => [
            'entity_type' => 'C-Corp',
            'name' => fake()->company().' Corp.',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function withOwners(int $count = 2): static
    {
        return $this->afterCreating(function (Corporation $corporation) use ($count) {
            CorporationOwner::factory()
                ->count($count)
                ->for($corporation)
                ->create();
        });
    }
}
