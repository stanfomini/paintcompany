<?php

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AccountingService;
use App\Services\JournalEntryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.manage', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'accounting.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'accounting.manage', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'vendors.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'vendors.manage', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'roadmap.view', 'guard_name' => 'web']);
    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());
});

// === Account Model Tests ===

it('has correct type constants', function () {
    expect(Account::TYPE_ASSET)->toBe('asset');
    expect(Account::TYPE_LIABILITY)->toBe('liability');
    expect(Account::TYPE_EQUITY)->toBe('equity');
    expect(Account::TYPE_REVENUE)->toBe('revenue');
    expect(Account::TYPE_EXPENSE)->toBe('expense');
    expect(Account::TYPES)->toHaveCount(5);
});

it('has correct normal balance constants', function () {
    expect(Account::NORMAL_DEBIT)->toBe('debit');
    expect(Account::NORMAL_CREDIT)->toBe('credit');
});

it('belongs to company setting', function () {
    $account = Account::factory()->asset()->create();
    expect($account->companySetting)->toBeInstanceOf(CompanySetting::class);
});

it('has parent and children relationships', function () {
    $parent = Account::factory()->asset()->create(['code' => '1000']);
    $child = Account::factory()->asset()->create(['code' => '1010', 'parent_id' => $parent->id]);

    expect($child->parent->id)->toBe($parent->id);
    expect($parent->children)->toHaveCount(1);
    expect($parent->children->first()->id)->toBe($child->id);
});

it('scopes active accounts', function () {
    Account::factory()->asset()->create(['is_active' => true]);
    Account::factory()->asset()->inactive()->create();

    expect(Account::active()->count())->toBe(1);
});

it('scopes accounts by type', function () {
    Account::factory()->asset()->create();
    Account::factory()->revenue()->create();

    expect(Account::byType('asset')->count())->toBe(1);
    expect(Account::byType('revenue')->count())->toBe(1);
});

it('determines default normal balance from type', function () {
    expect(Account::defaultNormalBalance('asset'))->toBe('debit');
    expect(Account::defaultNormalBalance('expense'))->toBe('debit');
    expect(Account::defaultNormalBalance('liability'))->toBe('credit');
    expect(Account::defaultNormalBalance('equity'))->toBe('credit');
    expect(Account::defaultNormalBalance('revenue'))->toBe('credit');
});

// === JournalEntry Model Tests ===

it('has correct journal entry status constants', function () {
    expect(JournalEntry::STATUS_DRAFT)->toBe('draft');
    expect(JournalEntry::STATUS_POSTED)->toBe('posted');
    expect(JournalEntry::STATUS_VOID)->toBe('void');
    expect(JournalEntry::STATUSES)->toHaveCount(3);
});

it('journal entry belongs to company setting and created by user', function () {
    $entry = JournalEntry::factory()->create();
    expect($entry->companySetting)->toBeInstanceOf(CompanySetting::class);
    expect($entry->createdBy)->toBeInstanceOf(User::class);
});

it('journal entry has lines relationship', function () {
    $account = Account::factory()->asset()->create();
    $entry = JournalEntry::factory()->create();
    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->id,
        'account_id' => $account->id,
        'debit_amount' => 100,
    ]);

    expect($entry->lines)->toHaveCount(1);
});

it('journal entry isBalanced returns true when balanced', function () {
    $asset = Account::factory()->asset()->create();
    $revenue = Account::factory()->revenue()->create();
    $entry = JournalEntry::factory()->create();
    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->id,
        'account_id' => $asset->id,
        'debit_amount' => 500,
        'credit_amount' => 0,
    ]);
    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->id,
        'account_id' => $revenue->id,
        'debit_amount' => 0,
        'credit_amount' => 500,
    ]);

    expect($entry->isBalanced())->toBeTrue();
});

it('journal entry isBalanced returns false when unbalanced', function () {
    $asset = Account::factory()->asset()->create();
    $entry = JournalEntry::factory()->create();
    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->id,
        'account_id' => $asset->id,
        'debit_amount' => 500,
        'credit_amount' => 0,
    ]);

    expect($entry->isBalanced())->toBeFalse();
});

it('journal entry scope posted works', function () {
    JournalEntry::factory()->posted()->create();
    JournalEntry::factory()->create(); // draft

    expect(JournalEntry::posted()->count())->toBe(1);
});

// === Vendor Model Tests ===

it('vendor has correct category constants', function () {
    expect(Vendor::CATEGORY_SUPPLIER)->toBe('supplier');
    expect(Vendor::CATEGORY_SUBCONTRACTOR)->toBe('subcontractor');
    expect(Vendor::CATEGORIES)->toHaveCount(4);
});

it('vendor scope active and byCategory work', function () {
    Vendor::factory()->create(['is_active' => true, 'category' => 'supplier']);
    Vendor::factory()->create(['is_active' => false, 'category' => 'subcontractor']);
    Vendor::factory()->create(['is_active' => true, 'category' => 'subcontractor']);

    expect(Vendor::active()->count())->toBe(2);
    expect(Vendor::byCategory('supplier')->count())->toBe(1);
    expect(Vendor::byCategory('subcontractor')->count())->toBe(2);
});

// === AccountingService Tests ===

it('creates journal entry with balanced lines', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $cash = Account::factory()->asset()->create(['code' => '1000']);
    $revenue = Account::factory()->revenue()->create(['code' => '4000']);

    $service = new AccountingService;
    $entry = $service->createJournalEntry([
        'description' => 'Test entry',
        'transaction_date' => '2026-01-15',
        'recognition_date' => '2026-01-15',
        'lines' => [
            ['account_id' => $cash->id, 'debit_amount' => 1000, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 1000],
        ],
    ]);

    expect($entry)->toBeInstanceOf(JournalEntry::class);
    expect($entry->lines)->toHaveCount(2);
    expect($entry->isBalanced())->toBeTrue();
    expect($entry->status)->toBe('draft');
});

it('rejects unbalanced journal entry', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $cash = Account::factory()->asset()->create();

    $service = new AccountingService;
    $service->createJournalEntry([
        'description' => 'Unbalanced',
        'transaction_date' => '2026-01-15',
        'lines' => [
            ['account_id' => $cash->id, 'debit_amount' => 1000, 'credit_amount' => 0],
        ],
    ]);
})->throws(\InvalidArgumentException::class);

it('posts a draft entry', function () {
    $entry = JournalEntry::factory()->create(['status' => 'draft']);

    $service = new AccountingService;
    $service->postEntry($entry);

    $entry->refresh();
    expect($entry->status)->toBe('posted');
    expect($entry->posted_at)->not->toBeNull();
});

it('voids a journal entry', function () {
    $entry = JournalEntry::factory()->posted()->create();

    $service = new AccountingService;
    $service->voidEntry($entry);

    $entry->refresh();
    expect($entry->status)->toBe('void');
    expect($entry->voided_at)->not->toBeNull();
});

it('generates unique entry numbers', function () {
    $service = new AccountingService;

    $first = $service->generateEntryNumber();
    JournalEntry::factory()->create(['entry_number' => $first]);
    $second = $service->generateEntryNumber();

    expect($first)->toStartWith('JE-'.now()->year.'-');
    expect($second)->toStartWith('JE-'.now()->year.'-');
    expect($first)->not->toBe($second);
});

it('calculates account balance for debit-normal account', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $cash = Account::factory()->asset()->create(['code' => '1000']);
    $revenue = Account::factory()->revenue()->create(['code' => '4000']);

    $service = new AccountingService;
    $service->createJournalEntry([
        'description' => 'Sale',
        'transaction_date' => '2026-01-15',
        'recognition_date' => '2026-01-15',
        'status' => 'posted',
        'lines' => [
            ['account_id' => $cash->id, 'debit_amount' => 500, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 500],
        ],
    ]);

    $balance = $service->getAccountBalance($cash, now(), 'cash');
    expect($balance)->toBe(500.0);
});

it('calculates account balance for credit-normal account', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $cash = Account::factory()->asset()->create(['code' => '1000']);
    $revenue = Account::factory()->revenue()->create(['code' => '4000']);

    $service = new AccountingService;
    $service->createJournalEntry([
        'description' => 'Sale',
        'transaction_date' => '2026-01-15',
        'recognition_date' => '2026-01-15',
        'status' => 'posted',
        'lines' => [
            ['account_id' => $cash->id, 'debit_amount' => 750, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 750],
        ],
    ]);

    $balance = $service->getAccountBalance($revenue, now(), 'cash');
    expect($balance)->toBe(750.0);
});

it('trial balance returns balanced totals', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $cash = Account::factory()->asset()->create();
    $revenue = Account::factory()->revenue()->create();

    $service = new AccountingService;
    $service->createJournalEntry([
        'description' => 'Test',
        'transaction_date' => '2026-01-15',
        'recognition_date' => '2026-01-15',
        'status' => 'posted',
        'lines' => [
            ['account_id' => $cash->id, 'debit_amount' => 1000, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 1000],
        ],
    ]);

    $tb = $service->getTrialBalance(now(), 'cash');
    expect($tb['is_balanced'])->toBeTrue();
    expect($tb['total_debits'])->toBe($tb['total_credits']);
});

it('P&L returns revenue and expenses for period', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $cash = Account::factory()->asset()->create();
    $revenue = Account::factory()->revenue()->create();
    $expense = Account::factory()->expense()->create();

    $service = new AccountingService;

    // Revenue entry
    $service->createJournalEntry([
        'description' => 'Revenue',
        'transaction_date' => '2026-01-15',
        'recognition_date' => '2026-01-15',
        'status' => 'posted',
        'lines' => [
            ['account_id' => $cash->id, 'debit_amount' => 2000, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 2000],
        ],
    ]);

    // Expense entry
    $service->createJournalEntry([
        'description' => 'Expense',
        'transaction_date' => '2026-01-20',
        'recognition_date' => '2026-01-20',
        'status' => 'posted',
        'lines' => [
            ['account_id' => $expense->id, 'debit_amount' => 800, 'credit_amount' => 0],
            ['account_id' => $cash->id, 'debit_amount' => 0, 'credit_amount' => 800],
        ],
    ]);

    $pl = $service->getProfitAndLoss(
        \Carbon\Carbon::parse('2026-01-01'),
        \Carbon\Carbon::parse('2026-01-31'),
        'cash'
    );

    expect($pl['total_revenue'])->toBe(2000.0);
    expect($pl['total_expenses'])->toBe(800.0);
    expect($pl['net_income'])->toBe(1200.0);
});

it('respects cash mode using transaction_date', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $cash = Account::factory()->asset()->create();
    $revenue = Account::factory()->revenue()->create();

    $service = new AccountingService;

    // Entry with only recognition_date (no transaction_date) should be invisible in cash mode
    $service->createJournalEntry([
        'description' => 'Accrual only',
        'transaction_date' => null,
        'recognition_date' => '2026-01-15',
        'status' => 'posted',
        'lines' => [
            ['account_id' => $cash->id, 'debit_amount' => 500, 'credit_amount' => 0],
            ['account_id' => $revenue->id, 'debit_amount' => 0, 'credit_amount' => 500],
        ],
    ]);

    $balance = $service->getAccountBalance($cash, now(), 'cash');
    expect($balance)->toBe(0.0); // not visible in cash mode

    $balance = $service->getAccountBalance($cash, now(), 'accrual');
    expect($balance)->toBe(500.0); // visible in accrual mode
});

// === JournalEntryService Tests ===

it('creates entry from payment', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    // Create chart of accounts
    $this->seed(ChartOfAccountsSeeder::class);

    $invoice = \App\Models\Invoice::factory()->sent()->create();
    $payment = \App\Models\Payment::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 500,
        'method' => 'check',
        'received_at' => now(),
        'recorded_by' => $user->id,
    ]);

    $service = new JournalEntryService;
    $entry = $service->createFromPayment($payment);

    expect($entry)->toBeInstanceOf(JournalEntry::class);
    expect($entry->reference_type)->toBe('payment');
    expect($entry->reference_id)->toBe($payment->id);
    expect($entry->lines)->toHaveCount(2);
    expect($entry->isBalanced())->toBeTrue();
    expect((float) $entry->lines->sum('debit_amount'))->toBe(500.0);
});

it('creates entry from invoice', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $this->seed(ChartOfAccountsSeeder::class);

    $invoice = \App\Models\Invoice::factory()->sent()->create(['total' => 1500]);

    $service = new JournalEntryService;
    $entry = $service->createFromInvoice($invoice);

    expect($entry)->toBeInstanceOf(JournalEntry::class);
    expect($entry->reference_type)->toBe('invoice');
    expect($entry->lines)->toHaveCount(2);
    expect($entry->isBalanced())->toBeTrue();
    expect((float) $entry->lines->sum('debit_amount'))->toBe(1500.0);
});

// === Livewire Component Tests ===

it('chart of accounts renders and shows accounts', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    Account::factory()->asset()->create(['name' => 'Test Cash']);

    Livewire::actingAs($user)
        ->test('business-plan.chart-of-accounts')
        ->assertSee('Chart of Accounts')
        ->assertSee('Test Cash')
        ->assertStatus(200);
});

it('chart of accounts requires business_plan.view permission', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('business-plan.chart-of-accounts')
        ->assertForbidden();
});

it('chart of accounts allows adding account with accounting.manage', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.chart-of-accounts')
        ->call('openCreateModal')
        ->assertSet('showAccountModal', true)
        ->set('accountCode', '9000')
        ->set('accountName', 'Test Account')
        ->set('accountType', 'expense')
        ->call('saveAccount')
        ->assertSet('showAccountModal', false);

    expect(Account::where('code', '9000')->exists())->toBeTrue();
});

it('journal entries renders', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.journal-entries')
        ->assertSee('Journal Entries')
        ->assertStatus(200);
});

it('journal entries filters by status', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    JournalEntry::factory()->posted()->create(['description' => 'Posted Entry']);
    JournalEntry::factory()->create(['description' => 'Draft Entry']);

    Livewire::actingAs($user)
        ->test('business-plan.journal-entries')
        ->set('statusFilter', 'posted')
        ->assertSee('Posted Entry')
        ->assertDontSee('Draft Entry');
});

it('journal entries allows creating balanced entry', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $cash = Account::factory()->asset()->create();
    $revenue = Account::factory()->revenue()->create();

    Livewire::actingAs($user)
        ->test('business-plan.journal-entries')
        ->call('openCreateModal')
        ->assertSet('showCreateModal', true)
        ->set('entryDescription', 'Test Manual Entry')
        ->set('entryTransactionDate', '2026-01-15')
        ->set('entryRecognitionDate', '2026-01-15')
        ->set('entryLines', [
            ['account_id' => $cash->id, 'debit_amount' => '100', 'credit_amount' => '', 'description' => ''],
            ['account_id' => $revenue->id, 'debit_amount' => '', 'credit_amount' => '100', 'description' => ''],
        ])
        ->call('createEntry')
        ->assertSet('showCreateModal', false);

    expect(JournalEntry::where('description', 'Test Manual Entry')->exists())->toBeTrue();
});

it('journal entries allows posting draft entry', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $entry = JournalEntry::factory()->create(['status' => 'draft']);

    Livewire::actingAs($user)
        ->test('business-plan.journal-entries')
        ->call('postEntry', $entry->id);

    $entry->refresh();
    expect($entry->status)->toBe('posted');
});

it('vendors renders and shows vendors', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    Vendor::factory()->create(['name' => 'Acme Supplies']);

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->assertSee('Vendors')
        ->assertSee('Acme Supplies')
        ->assertStatus(200);
});

it('vendors requires vendors.view permission', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->assertForbidden();
});

it('vendors allows CRUD with vendors.manage', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->call('openCreateModal')
        ->assertSet('showVendorModal', true)
        ->set('vendorName', 'New Vendor LLC')
        ->set('vendorCategory', 'supplier')
        ->set('vendorEmail', 'vendor@example.com')
        ->call('saveVendor')
        ->assertSet('showVendorModal', false);

    expect(Vendor::where('name', 'New Vendor LLC')->exists())->toBeTrue();
});

it('vendors allows editing', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $vendor = Vendor::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->call('openEditModal', $vendor->id)
        ->assertSet('editingVendorId', $vendor->id)
        ->set('vendorName', 'Updated Name')
        ->call('saveVendor')
        ->assertSet('showVendorModal', false);

    $vendor->refresh();
    expect($vendor->name)->toBe('Updated Name');
});

it('financial reports renders with trial balance', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.financial-reports')
        ->assertSee('Financial Reports')
        ->assertSee('Trial Balance')
        ->assertStatus(200);
});

it('financial reports requires accounting.view permission', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('business-plan.financial-reports')
        ->assertForbidden();
});

it('financial reports shows P&L for date range', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.financial-reports')
        ->call('setReportType', 'profit-loss')
        ->assertSet('reportType', 'profit-loss')
        ->assertSee('Revenue')
        ->assertSee('Expenses')
        ->assertStatus(200);
});

it('business plan index shows new tabs', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.index')
        ->assertSee('Vendors')
        ->assertSee('Chart of Accounts')
        ->assertSee('Journal')
        ->assertSee('Reports')
        ->assertStatus(200);
});

// === ChartOfAccountsSeeder Test ===

it('chart of accounts seeder creates expected accounts', function () {
    $this->seed(ChartOfAccountsSeeder::class);

    $settings = CompanySetting::getInstance();
    $accounts = Account::where('company_setting_id', $settings->id)->get();

    // Should have a substantial number of accounts
    expect($accounts->count())->toBeGreaterThan(20);

    // Key accounts should exist
    expect($accounts->where('code', '1000')->first())->not->toBeNull(); // Cash
    expect($accounts->where('code', '1100')->first())->not->toBeNull(); // AR
    expect($accounts->where('code', '2000')->first())->not->toBeNull(); // AP
    expect($accounts->where('code', '3000')->first())->not->toBeNull(); // Owner's Equity
    expect($accounts->where('code', '4000')->first())->not->toBeNull(); // Revenue
    expect($accounts->where('code', '5000')->first())->not->toBeNull(); // Prod Mgmt Labor

    // All system accounts
    expect($accounts->every(fn ($a) => $a->is_system))->toBeTrue();

    // Check type distribution
    expect($accounts->where('type', 'asset')->count())->toBeGreaterThan(3);
    expect($accounts->where('type', 'liability')->count())->toBeGreaterThan(3);
    expect($accounts->where('type', 'equity')->count())->toBeGreaterThan(1);
    expect($accounts->where('type', 'revenue')->count())->toBeGreaterThan(1);
    expect($accounts->where('type', 'expense')->count())->toBeGreaterThan(5);
});
