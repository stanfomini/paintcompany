<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_setting_id' => CompanySetting::getInstance()->id,
            'code' => (string) fake()->unique()->numberBetween(1000, 9999),
            'name' => fake()->words(2, true),
            'type' => fake()->randomElement(['asset', 'liability', 'equity', 'revenue', 'expense']),
            'is_system' => false,
            'normal_balance' => 'debit',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * Asset account (normal balance = debit).
     */
    public function asset(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Account::TYPE_ASSET,
            'normal_balance' => Account::NORMAL_DEBIT,
        ]);
    }

    /**
     * Liability account (normal balance = credit).
     */
    public function liability(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Account::TYPE_LIABILITY,
            'normal_balance' => Account::NORMAL_CREDIT,
        ]);
    }

    /**
     * Equity account (normal balance = credit).
     */
    public function equity(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Account::TYPE_EQUITY,
            'normal_balance' => Account::NORMAL_CREDIT,
        ]);
    }

    /**
     * Revenue account (normal balance = credit).
     */
    public function revenue(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Account::TYPE_REVENUE,
            'normal_balance' => Account::NORMAL_CREDIT,
        ]);
    }

    /**
     * Expense account (normal balance = debit).
     */
    public function expense(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Account::TYPE_EXPENSE,
            'normal_balance' => Account::NORMAL_DEBIT,
        ]);
    }

    /**
     * System account (cannot be deleted).
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_system' => true,
        ]);
    }

    /**
     * Bank account.
     */
    public function bankAccount(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => Account::TYPE_ASSET,
            'normal_balance' => Account::NORMAL_DEBIT,
            'is_bank_account' => true,
        ]);
    }

    /**
     * Inactive account.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
