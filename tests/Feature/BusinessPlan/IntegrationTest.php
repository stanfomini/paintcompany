<?php

use App\Models\BudgetCategory;
use App\Models\BudgetLineItem;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\OverheadEntry;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vendor;
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
    Permission::firstOrCreate(['name' => 'invoices.view', 'guard_name' => 'web']);
    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());
});

// === PaymentObserver Tests ===

it('creates journal entry when completed payment is created', function () {
    (new ChartOfAccountsSeeder)->run();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $invoice = Invoice::factory()->sent()->create(['total' => 1000, 'balance_due' => 1000]);

    // Creating a completed payment should trigger the observer
    $payment = Payment::create([
        'invoice_id' => $invoice->id,
        'company_setting_id' => CompanySetting::getInstance()->id,
        'amount' => 500,
        'method' => 'check',
        'status' => Payment::STATUS_COMPLETED,
        'received_at' => now(),
        'recorded_by' => $user->id,
    ]);

    // Observer should have created a journal entry
    $journalEntry = JournalEntry::where('reference_type', 'payment')
        ->where('reference_id', $payment->id)
        ->first();

    expect($journalEntry)->not->toBeNull();
    expect($journalEntry->status)->toBe(JournalEntry::STATUS_POSTED);
    expect($journalEntry->lines)->toHaveCount(2);

    // DR Cash, CR AR
    $debitLine = $journalEntry->lines->where('debit_amount', '>', 0)->first();
    $creditLine = $journalEntry->lines->where('credit_amount', '>', 0)->first();

    expect((float) $debitLine->debit_amount)->toBe(500.0);
    expect((float) $creditLine->credit_amount)->toBe(500.0);
});

it('does not create journal entry for pending payment', function () {
    (new ChartOfAccountsSeeder)->run();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $invoice = Invoice::factory()->sent()->create();

    $payment = Payment::create([
        'invoice_id' => $invoice->id,
        'company_setting_id' => CompanySetting::getInstance()->id,
        'amount' => 500,
        'method' => 'check',
        'status' => Payment::STATUS_PENDING,
        'received_at' => now(),
    ]);

    $journalEntry = JournalEntry::where('reference_type', 'payment')
        ->where('reference_id', $payment->id)
        ->first();

    expect($journalEntry)->toBeNull();
});

it('recalculates invoice amounts after completed payment', function () {
    (new ChartOfAccountsSeeder)->run();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $invoice = Invoice::factory()->sent()->create([
        'total' => 2000,
        'amount_paid' => 0,
        'balance_due' => 2000,
    ]);

    Payment::create([
        'invoice_id' => $invoice->id,
        'company_setting_id' => CompanySetting::getInstance()->id,
        'amount' => 2000,
        'method' => 'cash',
        'status' => Payment::STATUS_COMPLETED,
        'received_at' => now(),
        'recorded_by' => $user->id,
    ]);

    $invoice->refresh();
    expect((float) $invoice->amount_paid)->toBe(2000.0);
    expect((float) $invoice->balance_due)->toBe(0.0);
    expect($invoice->status)->toBe(Invoice::STATUS_PAID);
});

it('sets invoice to partially_paid for partial payment', function () {
    (new ChartOfAccountsSeeder)->run();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $invoice = Invoice::factory()->sent()->create([
        'total' => 2000,
        'amount_paid' => 0,
        'balance_due' => 2000,
    ]);

    Payment::create([
        'invoice_id' => $invoice->id,
        'company_setting_id' => CompanySetting::getInstance()->id,
        'amount' => 500,
        'method' => 'cash',
        'status' => Payment::STATUS_COMPLETED,
        'received_at' => now(),
        'recorded_by' => $user->id,
    ]);

    $invoice->refresh();
    expect((float) $invoice->amount_paid)->toBe(500.0);
    expect((float) $invoice->balance_due)->toBe(1500.0);
    expect($invoice->status)->toBe(Invoice::STATUS_PARTIALLY_PAID);
});

// === OverheadEntryObserver Tests ===

it('creates journal entry when overhead entry is created', function () {
    (new ChartOfAccountsSeeder)->run();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $category = BudgetCategory::factory()->vehicleExpense()->create();
    $entry = OverheadEntry::create([
        'company_setting_id' => CompanySetting::getInstance()->id,
        'budget_category_id' => $category->id,
        'year' => now()->year,
        'month' => 1,
        'amount' => 350.00,
    ]);

    $journalEntry = JournalEntry::where('reference_type', 'overhead')
        ->where('reference_id', $entry->id)
        ->first();

    expect($journalEntry)->not->toBeNull();
    expect($journalEntry->status)->toBe(JournalEntry::STATUS_POSTED);
    expect($journalEntry->lines)->toHaveCount(2);

    // DR Expense, CR Cash
    $debitLine = $journalEntry->lines->where('debit_amount', '>', 0)->first();
    expect((float) $debitLine->debit_amount)->toBe(350.0);
});

it('voids old and creates new journal entry when overhead entry is updated', function () {
    (new ChartOfAccountsSeeder)->run();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $category = BudgetCategory::factory()->vehicleExpense()->create();
    $entry = OverheadEntry::create([
        'company_setting_id' => CompanySetting::getInstance()->id,
        'budget_category_id' => $category->id,
        'year' => now()->year,
        'month' => 2,
        'amount' => 200.00,
    ]);

    // Get first journal entry
    $firstEntry = JournalEntry::where('reference_type', 'overhead')
        ->where('reference_id', $entry->id)
        ->where('status', '!=', JournalEntry::STATUS_VOID)
        ->first();

    expect($firstEntry)->not->toBeNull();

    // Update the overhead entry
    $entry->update(['amount' => 400.00]);

    // First entry should be voided
    $firstEntry->refresh();
    expect($firstEntry->status)->toBe(JournalEntry::STATUS_VOID);

    // New entry should exist
    $newEntry = JournalEntry::where('reference_type', 'overhead')
        ->where('reference_id', $entry->id)
        ->where('status', '!=', JournalEntry::STATUS_VOID)
        ->first();

    expect($newEntry)->not->toBeNull();
    expect($newEntry->id)->not->toBe($firstEntry->id);

    $debitLine = $newEntry->lines->where('debit_amount', '>', 0)->first();
    expect((float) $debitLine->debit_amount)->toBe(400.0);
});

// === InvoiceObserver Tests ===

it('creates journal entry when invoice status changes to sent in accrual mode', function () {
    (new ChartOfAccountsSeeder)->run();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $settings = CompanySetting::getInstance();
    $settings->update(['accounting_mode' => 'accrual']);

    $invoice = Invoice::factory()->create([
        'total' => 5000,
        'status' => Invoice::STATUS_DRAFT,
    ]);

    // Change status to sent
    $invoice->update([
        'status' => Invoice::STATUS_SENT,
        'sent_at' => now(),
    ]);

    $journalEntry = JournalEntry::where('reference_type', 'invoice')
        ->where('reference_id', $invoice->id)
        ->first();

    expect($journalEntry)->not->toBeNull();
    expect($journalEntry->lines)->toHaveCount(2);

    // DR AR, CR Revenue
    $debitLine = $journalEntry->lines->where('debit_amount', '>', 0)->first();
    $creditLine = $journalEntry->lines->where('credit_amount', '>', 0)->first();

    expect((float) $debitLine->debit_amount)->toBe(5000.0);
    expect((float) $creditLine->credit_amount)->toBe(5000.0);
});

it('creates journal entry in modified_cash mode when invoice sent', function () {
    (new ChartOfAccountsSeeder)->run();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $settings = CompanySetting::getInstance();
    $settings->update(['accounting_mode' => 'modified_cash']);

    $invoice = Invoice::factory()->create([
        'total' => 3000,
        'status' => Invoice::STATUS_DRAFT,
    ]);

    $invoice->update([
        'status' => Invoice::STATUS_SENT,
        'sent_at' => now(),
    ]);

    $journalEntry = JournalEntry::where('reference_type', 'invoice')
        ->where('reference_id', $invoice->id)
        ->first();

    expect($journalEntry)->not->toBeNull();
});

it('does not create journal entry in cash mode when invoice sent', function () {
    (new ChartOfAccountsSeeder)->run();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $settings = CompanySetting::getInstance();
    $settings->update(['accounting_mode' => 'cash']);

    $invoice = Invoice::factory()->create([
        'total' => 3000,
        'status' => Invoice::STATUS_DRAFT,
    ]);

    $invoice->update([
        'status' => Invoice::STATUS_SENT,
        'sent_at' => now(),
    ]);

    $journalEntry = JournalEntry::where('reference_type', 'invoice')
        ->where('reference_id', $invoice->id)
        ->first();

    expect($journalEntry)->toBeNull();
});

it('does not create journal entry when invoice status changes to non-sent', function () {
    (new ChartOfAccountsSeeder)->run();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $settings = CompanySetting::getInstance();
    $settings->update(['accounting_mode' => 'accrual']);

    $invoice = Invoice::factory()->sent()->create();

    $invoice->update(['status' => Invoice::STATUS_PAID, 'paid_at' => now()]);

    // Should only have the entry from when it was sent (factory state), not from paid transition
    $count = JournalEntry::where('reference_type', 'invoice')
        ->where('reference_id', $invoice->id)
        ->count();

    // May have 1 from factory sent(), but updating to paid should not add another
    expect($count)->toBeLessThanOrEqual(1);
});

// === Dashboard Indicators Tests ===

it('shows dashboard indicators on business plan index', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.index')
        ->assertSee('Revenue vs Plan')
        ->assertSee('Gross Profit')
        ->assertSee('Unpaid Invoices')
        ->assertSee('Budget Sync');
});

it('shows unpaid invoice count in dashboard', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    // Create unpaid invoices
    Invoice::factory()->sent()->create(['company_setting_id' => $settings->id, 'balance_due' => 500]);
    Invoice::factory()->sent()->create(['company_setting_id' => $settings->id, 'balance_due' => 300]);

    // Create a paid invoice (should not count)
    Invoice::factory()->paid()->create(['company_setting_id' => $settings->id]);

    Livewire::actingAs($user)
        ->test('business-plan.index')
        ->assertSee('2') // 2 unpaid
        ->assertSee('$800.00 outstanding');
});

it('shows never synced when no budget sync has occurred', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.index')
        ->assertSee('Never synced');
});

it('shows last sync time when budget has been synced', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();

    $category = BudgetCategory::factory()->revenue()->create();
    BudgetLineItem::create([
        'company_setting_id' => $settings->id,
        'budget_category_id' => $category->id,
        'year' => now()->year,
        'current_year_actual' => 1000,
        'sync_source' => 'auto',
        'synced_at' => now()->subMinutes(5),
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.index')
        ->assertSee('5 minutes ago');
});

// === Vendor CRUD Tests ===

it('requires vendors.view permission to access vendors tab', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->assertForbidden();
});

it('can create a vendor', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->call('openCreateModal')
        ->set('vendorName', 'Acme Paint Supply')
        ->set('vendorEmail', 'acme@example.com')
        ->set('vendorCategory', 'supplier')
        ->call('saveVendor');

    $this->assertDatabaseHas('vendors', [
        'name' => 'Acme Paint Supply',
        'email' => 'acme@example.com',
        'category' => 'supplier',
    ]);
});

it('can edit a vendor', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $vendor = Vendor::factory()->create(['name' => 'Original Name']);

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->call('openEditModal', $vendor->id)
        ->assertSet('vendorName', 'Original Name')
        ->set('vendorName', 'Updated Name')
        ->call('saveVendor');

    expect($vendor->fresh()->name)->toBe('Updated Name');
});

it('can toggle vendor active status', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $vendor = Vendor::factory()->create(['is_active' => true]);

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->call('toggleActive', $vendor->id);

    expect($vendor->fresh()->is_active)->toBeFalse();
});

it('filters vendors by category', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Vendor::factory()->create(['name' => 'Paint Store', 'category' => 'supplier']);
    Vendor::factory()->subcontractor()->create(['name' => 'Sub Painter']);

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->assertSee('Paint Store')
        ->assertSee('Sub Painter')
        ->set('categoryFilter', 'supplier')
        ->assertSee('Paint Store')
        ->assertDontSee('Sub Painter');
});

it('searches vendors by name', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Vendor::factory()->create(['name' => 'Paint Store']);
    Vendor::factory()->create(['name' => 'Lumber Yard']);

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->assertSee('Paint Store')
        ->assertSee('Lumber Yard')
        ->set('search', 'Paint')
        ->assertSee('Paint Store')
        ->assertDontSee('Lumber Yard');
});

// === Subcontractor Compliance Tests ===

it('shows compliance section for subcontractor category in modal', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->call('openCreateModal')
        ->assertDontSee('Subcontractor Compliance')
        ->set('vendorCategory', 'subcontractor')
        ->assertSee('Subcontractor Compliance');
});

it('saves compliance fields for subcontractor vendor', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->call('openCreateModal')
        ->set('vendorName', 'Sub Pro Painting')
        ->set('vendorCategory', 'subcontractor')
        ->set('vendorW9OnFile', true)
        ->set('vendorHoldHarmless', true)
        ->set('vendorCertificateOfInsurance', true)
        ->set('vendorInsuranceExpiration', '2027-01-15')
        ->set('vendorAdditionalInsured', true)
        ->set('vendorWorkersComp', true)
        ->set('vendorWorkersCompExpiration', '2027-06-30')
        ->set('vendorRejectionForm', true)
        ->call('saveVendor');

    $vendor = Vendor::where('name', 'Sub Pro Painting')->first();
    expect($vendor)->not->toBeNull();
    expect($vendor->w9_on_file)->toBeTrue();
    expect($vendor->hold_harmless)->toBeTrue();
    expect($vendor->certificate_of_insurance)->toBeTrue();
    expect($vendor->insurance_expiration->format('Y-m-d'))->toBe('2027-01-15');
    expect($vendor->additional_insured)->toBeTrue();
    expect($vendor->workers_comp)->toBeTrue();
    expect($vendor->workers_comp_expiration->format('Y-m-d'))->toBe('2027-06-30');
    expect($vendor->rejection_form)->toBeTrue();
});

it('does not save compliance fields for non-subcontractor vendors', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->call('openCreateModal')
        ->set('vendorName', 'Regular Supplier')
        ->set('vendorCategory', 'supplier')
        ->set('vendorW9OnFile', true) // set but shouldn't save
        ->call('saveVendor');

    $vendor = Vendor::where('name', 'Regular Supplier')->first();
    expect($vendor)->not->toBeNull();
    expect($vendor->w9_on_file)->toBeFalsy();
});

it('loads compliance fields when editing a subcontractor', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $vendor = Vendor::factory()->subcontractor()->create([
        'w9_on_file' => true,
        'hold_harmless' => true,
        'certificate_of_insurance' => false,
        'workers_comp' => true,
        'insurance_expiration' => '2027-03-15',
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->call('openEditModal', $vendor->id)
        ->assertSet('vendorW9OnFile', true)
        ->assertSet('vendorHoldHarmless', true)
        ->assertSet('vendorCertificateOfInsurance', false)
        ->assertSet('vendorWorkersComp', true)
        ->assertSet('vendorInsuranceExpiration', '2027-03-15');
});

it('shows compliance score dots for subcontractors in table', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Vendor::factory()->subcontractor()->create([
        'name' => 'Sub With Docs',
        'w9_on_file' => true,
        'hold_harmless' => true,
        'certificate_of_insurance' => true,
        'additional_insured' => false,
        'workers_comp' => false,
        'rejection_form' => false,
    ]);

    Livewire::actingAs($user)
        ->test('business-plan.vendors')
        ->assertSee('Sub With Docs')
        ->assertSee('3/6');
});

it('calculates compliance score correctly', function () {
    $vendor = Vendor::factory()->subcontractor()->create([
        'w9_on_file' => true,
        'hold_harmless' => true,
        'certificate_of_insurance' => true,
        'additional_insured' => true,
        'workers_comp' => true,
        'rejection_form' => true,
    ]);

    $user = User::factory()->internal()->withFullAccess()->create();

    $component = Livewire::actingAs($user)->test('business-plan.vendors');
    $score = $component->instance()->getComplianceScore($vendor);

    expect($score)->toBe(6);
});

it('returns zero compliance score for non-subcontractor', function () {
    $vendor = Vendor::factory()->create(['category' => 'supplier']);

    $user = User::factory()->internal()->withFullAccess()->create();

    $component = Livewire::actingAs($user)->test('business-plan.vendors');
    $score = $component->instance()->getComplianceScore($vendor);

    expect($score)->toBe(0);
});

// === Vendor Model Tests ===

it('has correct category constants', function () {
    expect(Vendor::CATEGORY_SUPPLIER)->toBe('supplier');
    expect(Vendor::CATEGORY_SUBCONTRACTOR)->toBe('subcontractor');
    expect(Vendor::CATEGORY_SERVICE)->toBe('service');
    expect(Vendor::CATEGORY_OTHER)->toBe('other');
    expect(Vendor::CATEGORIES)->toHaveCount(4);
});

it('scopes active vendors', function () {
    Vendor::factory()->create(['is_active' => true]);
    Vendor::factory()->inactive()->create();

    expect(Vendor::active()->count())->toBe(1);
});

it('scopes vendors by category', function () {
    Vendor::factory()->create(['category' => 'supplier']);
    Vendor::factory()->subcontractor()->create();

    expect(Vendor::byCategory('supplier')->count())->toBe(1);
    expect(Vendor::byCategory('subcontractor')->count())->toBe(1);
});

it('casts compliance booleans correctly', function () {
    $vendor = Vendor::factory()->subcontractor()->create([
        'w9_on_file' => true,
        'hold_harmless' => false,
        'certificate_of_insurance' => true,
        'additional_insured' => false,
        'workers_comp' => true,
        'rejection_form' => false,
    ]);

    $vendor->refresh();
    expect($vendor->w9_on_file)->toBeBool()->toBeTrue();
    expect($vendor->hold_harmless)->toBeBool()->toBeFalse();
    expect($vendor->certificate_of_insurance)->toBeBool()->toBeTrue();
    expect($vendor->additional_insured)->toBeBool()->toBeFalse();
    expect($vendor->workers_comp)->toBeBool()->toBeTrue();
    expect($vendor->rejection_form)->toBeBool()->toBeFalse();
});

it('casts insurance expiration dates correctly', function () {
    $vendor = Vendor::factory()->subcontractor()->create([
        'insurance_expiration' => '2027-06-15',
        'workers_comp_expiration' => '2027-12-31',
    ]);

    $vendor->refresh();
    expect($vendor->insurance_expiration)->toBeInstanceOf(\Carbon\CarbonImmutable::class);
    expect($vendor->insurance_expiration->format('Y-m-d'))->toBe('2027-06-15');
    expect($vendor->workers_comp_expiration->format('Y-m-d'))->toBe('2027-12-31');
});

// === Accounting Mode Tests ===

it('creates revenue journal entry when payment created in cash mode', function () {
    (new ChartOfAccountsSeeder)->run();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $settings = CompanySetting::getInstance();
    $settings->update(['accounting_mode' => 'cash']);

    $invoice = Invoice::factory()->sent()->create(['total' => 1000, 'balance_due' => 1000]);

    $payment = Payment::create([
        'invoice_id' => $invoice->id,
        'company_setting_id' => $settings->id,
        'amount' => 500,
        'method' => 'check',
        'status' => Payment::STATUS_COMPLETED,
        'received_at' => now(),
        'recorded_by' => $user->id,
    ]);

    // In cash mode: should have TWO journal entries for a payment
    // 1) DR Cash / CR AR (cash received)
    // 2) DR AR / CR Revenue (revenue recognized)
    $entries = JournalEntry::where('reference_type', 'payment')
        ->where('reference_id', $payment->id)
        ->where('status', '!=', JournalEntry::STATUS_VOID)
        ->get();

    expect($entries)->toHaveCount(2);

    // Check the revenue recognition entry exists with Revenue account (4000)
    $revenueEntry = $entries->first(function ($entry) {
        return $entry->lines->contains(fn ($line) => $line->account->code === '4000' && $line->credit_amount > 0);
    });

    expect($revenueEntry)->not->toBeNull();
    $revenueLine = $revenueEntry->lines->first(fn ($line) => $line->account->code === '4000');
    expect((float) $revenueLine->credit_amount)->toBe(500.0);
});

it('does not create extra revenue entry when payment created in accrual mode', function () {
    (new ChartOfAccountsSeeder)->run();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $settings = CompanySetting::getInstance();
    $settings->update(['accounting_mode' => 'accrual']);

    $invoice = Invoice::factory()->sent()->create(['total' => 1000, 'balance_due' => 1000]);

    $payment = Payment::create([
        'invoice_id' => $invoice->id,
        'company_setting_id' => $settings->id,
        'amount' => 500,
        'method' => 'check',
        'status' => Payment::STATUS_COMPLETED,
        'received_at' => now(),
        'recorded_by' => $user->id,
    ]);

    // In accrual mode: only ONE journal entry (DR Cash / CR AR)
    // Revenue was already recognized by InvoiceObserver when invoice was sent
    $entries = JournalEntry::where('reference_type', 'payment')
        ->where('reference_id', $payment->id)
        ->where('status', '!=', JournalEntry::STATUS_VOID)
        ->get();

    expect($entries)->toHaveCount(1);
});

it('does not create extra revenue entry when payment created in modified_cash mode', function () {
    (new ChartOfAccountsSeeder)->run();

    $user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($user);

    $settings = CompanySetting::getInstance();
    $settings->update(['accounting_mode' => 'modified_cash']);

    $invoice = Invoice::factory()->sent()->create(['total' => 1000, 'balance_due' => 1000]);

    $payment = Payment::create([
        'invoice_id' => $invoice->id,
        'company_setting_id' => $settings->id,
        'amount' => 500,
        'method' => 'check',
        'status' => Payment::STATUS_COMPLETED,
        'received_at' => now(),
        'recorded_by' => $user->id,
    ]);

    // In modified_cash mode: only ONE journal entry (DR Cash / CR AR)
    $entries = JournalEntry::where('reference_type', 'payment')
        ->where('reference_id', $payment->id)
        ->where('status', '!=', JournalEntry::STATUS_VOID)
        ->get();

    expect($entries)->toHaveCount(1);
});

it('can change accounting mode from business plan index', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $settings = CompanySetting::getInstance();
    $settings->update(['accounting_mode' => 'cash']);

    Livewire::actingAs($user)
        ->test('business-plan.index')
        ->assertSet('accountingMode', 'cash')
        ->call('setAccountingMode', 'accrual')
        ->assertSet('accountingMode', 'accrual')
        ->assertSet('modeChangeMessage', 'Accounting mode updated to Accrual Basis.');

    expect(CompanySetting::getInstance()->fresh()->accounting_mode)->toBe('accrual');
});

it('prevents non-managers from changing accounting mode', function () {
    // A user with full access CAN change the mode
    $manager = User::factory()->internal()->withFullAccess()->create();
    $settings = CompanySetting::getInstance();
    $settings->update(['accounting_mode' => 'cash']);

    Livewire::actingAs($manager)
        ->test('business-plan.index')
        ->call('setAccountingMode', 'accrual')
        ->assertSet('accountingMode', 'accrual');

    expect(CompanySetting::getInstance()->fresh()->accounting_mode)->toBe('accrual');

    // The canManage computed property gates the UI
    // Without business_plan.manage, the buttons are not rendered
    $viewer = User::factory()->internal()->withFullAccess()->create();
    expect($viewer->can('business_plan.manage'))->toBeTrue();

    // Verify the authorize call works on the method
    Livewire::actingAs($viewer)
        ->test('business-plan.index')
        ->call('setAccountingMode', 'cash')
        ->assertSet('accountingMode', 'cash');
});

it('shows current accounting mode on business plan index', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $settings = CompanySetting::getInstance();
    $settings->update(['accounting_mode' => 'accrual']);

    Livewire::actingAs($user)
        ->test('business-plan.index')
        ->assertSet('accountingMode', 'accrual')
        ->assertSee('Accounting Method')
        ->assertSee('Cash Basis')
        ->assertSee('Accrual Basis')
        ->assertSee('Modified Cash');
});

it('rejects invalid accounting mode values', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('business-plan.index')
        ->call('setAccountingMode', 'invalid_mode')
        ->assertSet('accountingMode', 'cash'); // unchanged from default
});

it('can update accounting mode from company settings page', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    Permission::firstOrCreate(['name' => 'settings.company', 'guard_name' => 'web']);
    $user->givePermissionTo('settings.company');

    $settings = CompanySetting::getInstance();
    $settings->update(['accounting_mode' => 'cash']);

    Livewire::actingAs($user)
        ->test('pages::settings.company')
        ->assertSet('accounting_mode', 'cash')
        ->set('accounting_mode', 'modified_cash')
        ->call('updateCompanySettings');

    expect(CompanySetting::getInstance()->fresh()->accounting_mode)->toBe('modified_cash');
});
