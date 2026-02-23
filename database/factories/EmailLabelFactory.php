<?php

namespace Database\Factories;

use App\Models\EmailAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmailLabel>
 */
class EmailLabelFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'email_account_id' => null,
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'color' => null,
            'is_system' => false,
            'sort_order' => 0,
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_system' => true,
        ]);
    }

    public function forAccount(EmailAccount $account): static
    {
        return $this->state(fn (array $attributes) => [
            'email_account_id' => $account->id,
        ]);
    }
}
