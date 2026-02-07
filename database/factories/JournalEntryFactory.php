<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\JournalEntry>
 */
class JournalEntryFactory extends Factory
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
            'entry_number' => 'JE-'.now()->year.'-'.str_pad(fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'transaction_date' => fake()->date(),
            'recognition_date' => fake()->date(),
            'description' => fake()->sentence(),
            'reference_type' => null,
            'reference_id' => null,
            'status' => JournalEntry::STATUS_DRAFT,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Posted journal entry.
     */
    public function posted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
        ]);
    }

    /**
     * Voided journal entry.
     */
    public function void(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => JournalEntry::STATUS_VOID,
            'posted_at' => now()->subDay(),
            'voided_at' => now(),
        ]);
    }

    /**
     * Manual reference type.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'reference_type' => JournalEntry::REF_MANUAL,
        ]);
    }

    /**
     * Invoice reference type.
     */
    public function forInvoice(int $invoiceId): static
    {
        return $this->state(fn (array $attributes) => [
            'reference_type' => JournalEntry::REF_INVOICE,
            'reference_id' => $invoiceId,
        ]);
    }

    /**
     * Payment reference type.
     */
    public function forPayment(int $paymentId): static
    {
        return $this->state(fn (array $attributes) => [
            'reference_type' => JournalEntry::REF_PAYMENT,
            'reference_id' => $paymentId,
        ]);
    }
}
