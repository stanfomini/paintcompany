<?php

use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PayPeriod;
use App\Models\PayStub;
use App\Models\User;
use App\Services\JournalEntryService;
use App\Services\PayrollService;
use Database\Seeders\ChartOfAccountsSeeder;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);

    (new ChartOfAccountsSeeder)->run();
});

// === Payroll GL Posting ===

it('creates journal entry from finalized pay stub', function () {
    $stub = PayStub::factory()->finalized()->create([
        'gross_pay' => 2000.00,
        'federal_tax' => 240.00,
        'state_tax' => 88.00,
        'social_security' => 124.00,
        'medicare' => 29.00,
        'net_pay' => 1519.00,
        'check_number' => '1001',
    ]);

    $service = app(JournalEntryService::class);
    $entry = $service->createFromPayStub($stub);

    expect($entry)->not->toBeNull();
    expect($entry->reference_type)->toBe('pay_stub');
    expect($entry->reference_id)->toBe($stub->id);
    expect($entry->status)->toBe('posted');

    // Verify balanced: debits = credits
    $totalDebits = $entry->lines->sum('debit_amount');
    $totalCredits = $entry->lines->sum('credit_amount');
    expect(round($totalDebits, 2))->toBe(round($totalCredits, 2));

    // DR side: gross_pay = 2000
    expect(round($totalDebits, 2))->toBe(2000.00);

    // CR side breakdown
    $creditLines = $entry->lines->where('credit_amount', '>', 0);
    expect($creditLines)->toHaveCount(4); // federal, state, fica, net_pay
});

it('prevents duplicate pay stub journal entries', function () {
    $stub = PayStub::factory()->finalized()->create([
        'gross_pay' => 1000.00,
        'federal_tax' => 120.00,
        'state_tax' => 44.00,
        'social_security' => 62.00,
        'medicare' => 14.50,
        'net_pay' => 759.50,
    ]);

    $service = app(JournalEntryService::class);
    $entry1 = $service->createFromPayStub($stub);
    $entry2 = $service->createFromPayStub($stub);

    expect($entry1)->not->toBeNull();
    expect($entry2)->toBeNull();
    expect(JournalEntry::where('reference_type', 'pay_stub')->where('reference_id', $stub->id)->count())->toBe(1);
});

it('creates journal entries when payroll is finalized', function () {
    $period = PayPeriod::factory()->create(['status' => 'processing']);

    $stub1 = PayStub::factory()->create([
        'pay_period_id' => $period->id,
        'status' => 'draft',
        'gross_pay' => 2000.00,
        'federal_tax' => 240.00,
        'state_tax' => 88.00,
        'social_security' => 124.00,
        'medicare' => 29.00,
        'net_pay' => 1519.00,
    ]);

    $stub2 = PayStub::factory()->create([
        'pay_period_id' => $period->id,
        'status' => 'draft',
        'gross_pay' => 1500.00,
        'federal_tax' => 180.00,
        'state_tax' => 66.00,
        'social_security' => 93.00,
        'medicare' => 21.75,
        'net_pay' => 1139.25,
    ]);

    app(PayrollService::class)->finalizePayroll($period, $this->user);

    // Both stubs should have journal entries
    expect(JournalEntry::where('reference_type', 'pay_stub')->count())->toBe(2);

    // Verify each is balanced
    JournalEntry::where('reference_type', 'pay_stub')->each(function ($entry) {
        $debits = round($entry->lines->sum('debit_amount'), 2);
        $credits = round($entry->lines->sum('credit_amount'), 2);
        expect($debits)->toBe($credits);
    });
});

// === Invoice GL Posting Duplicate Guard ===

it('prevents duplicate invoice journal entries', function () {
    CompanySetting::getInstance()->update(['accounting_mode' => 'accrual']);

    $invoice = Invoice::factory()->sent()->create(['total' => 5000.00]);

    $service = app(JournalEntryService::class);
    $entry1 = $service->createFromInvoice($invoice);
    $entry2 = $service->createFromInvoice($invoice);

    expect($entry1)->not->toBeNull();
    expect($entry2)->toBeNull();
    expect(JournalEntry::where('reference_type', 'invoice')->where('reference_id', $invoice->id)->count())->toBe(1);
});

// === Payment GL Posting Duplicate Guard ===

it('prevents duplicate payment journal entries', function () {
    $invoice = Invoice::factory()->sent()->create(['total' => 5000.00]);

    // PaymentObserver auto-creates JE on creation, so first verify that works
    $payment = Payment::factory()->check()->forInvoice($invoice)->create([
        'amount' => 2500.00,
    ]);

    // Observer should have created the JE
    expect(JournalEntry::where('reference_type', 'payment')->where('reference_id', $payment->id)->count())->toBe(1);

    // Manual call should return null (duplicate guard)
    $service = app(JournalEntryService::class);
    $duplicate = $service->createFromPayment($payment);

    expect($duplicate)->toBeNull();
    expect(JournalEntry::where('reference_type', 'payment')->where('reference_id', $payment->id)->count())->toBe(1);
});

// === Journal Entry Balance Verification ===

it('pay stub journal entry debits equal credits exactly', function () {
    $stub = PayStub::factory()->finalized()->create([
        'gross_pay' => 3456.78,
        'federal_tax' => 414.81,
        'state_tax' => 152.10,
        'social_security' => 214.32,
        'medicare' => 50.12,
        'net_pay' => 2625.43,
    ]);

    $service = app(JournalEntryService::class);
    $entry = $service->createFromPayStub($stub);

    expect($entry)->not->toBeNull();

    $debits = round($entry->lines->sum('debit_amount'), 2);
    $credits = round($entry->lines->sum('credit_amount'), 2);

    // Debits should be gross_pay
    expect($debits)->toBe(3456.78);

    // Credits should sum to gross_pay (federal + state + fica + net)
    $expectedCredits = 414.81 + 152.10 + 214.32 + 50.12 + 2625.43;
    expect($credits)->toBe(round($expectedCredits, 2));
});

it('skips journal entry when GL accounts missing', function () {
    // Wipe chart of accounts
    \App\Models\Account::query()->delete();

    $stub = PayStub::factory()->finalized()->create();

    $service = app(JournalEntryService::class);
    $entry = $service->createFromPayStub($stub);

    expect($entry)->toBeNull();
});
