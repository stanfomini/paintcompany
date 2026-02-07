<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JournalEntryLine>
 */
class JournalEntryLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'journal_entry_id' => JournalEntry::factory(),
            'account_id' => Account::factory(),
            'debit_amount' => 0,
            'credit_amount' => 0,
            'description' => null,
        ];
    }

    /**
     * Debit line.
     */
    public function debit(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'debit_amount' => $amount,
            'credit_amount' => 0,
        ]);
    }

    /**
     * Credit line.
     */
    public function credit(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'debit_amount' => 0,
            'credit_amount' => $amount,
        ]);
    }
}
