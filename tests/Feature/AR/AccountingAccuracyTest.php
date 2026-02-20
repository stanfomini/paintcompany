<?php

use App\Models\Account;
use App\Models\CommissionEntry;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\EmployeeProfile;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\JobCostCard;
use App\Models\JobCostCardAllocation;
use App\Models\JobPayable;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CommissionService;
use App\Services\JobPayableService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->settings = CompanySetting::getInstance();

    // Core GL accounts
    $this->cogsAccount = Account::factory()->expense()->create(['code' => '5010', 'name' => 'COGS - Labor']);
    $this->materialsAccount = Account::factory()->expense()->create(['code' => '5030', 'name' => 'COGS - Materials']);
    $this->ownerSalaryAccount = Account::factory()->expense()->create(['code' => '6010', 'name' => 'Owner Salary']);
    $this->apAccount = Account::factory()->liability()->create(['code' => '2000', 'name' => 'Accounts Payable']);
    $this->bankAccount = Account::factory()->bankAccount()->create(['code' => '1000', 'name' => 'Operating Account']);
    $this->bankAccount2 = Account::factory()->bankAccount()->create(['code' => '1010', 'name' => 'Payroll Account']);
    $this->inactiveBankAccount = Account::factory()->bankAccount()->inactive()->create(['code' => '1099', 'name' => 'Closed Account']);

    // Users
    $this->owner = User::factory()->internal()->withFullAccess()->create();

    // Deal + Job setup
    $this->customer = Customer::factory()->create();
    $this->deal = Deal::factory()->signed()->forCustomer($this->customer)->create([
        'assigned_to' => $this->owner->id,
    ]);
    $this->job = Job::factory()->create(['deal_id' => $this->deal->id]);
    $this->costCard = JobCostCard::factory()->forJob($this->job)->create([
        'price' => 10000.00,
        'labor_cost' => 2000.00,
        'cleanup_cost' => 300.00,
        'sundries_cost' => 100.00,
        'material_cost' => 1500.00,
        'material_tax' => 120.00,
        'labor_tax' => 60.00,
        'addon_cost' => 200.00,
        'items_vendor_cost' => 50.00,
        'warranty_reserve' => 80.00,
        'total_cogs' => 4410.00,
        'allocation_total' => 500.00,
        'projected_gross_profit' => 5590.00,
        'projected_gross_profit_percent' => 55.90,
    ]);

    $this->vendor = Vendor::factory()->create();
});

// === Phase 1: Bank Account Infrastructure ===

it('filters bank accounts scope to only active bank accounts', function () {
    $bankAccounts = Account::bankAccounts()->get();

    expect($bankAccounts)->toHaveCount(2);
    expect($bankAccounts->pluck('code')->sort()->values()->all())->toBe(['1000', '1010']);

    // Inactive bank account should NOT appear
    expect($bankAccounts->pluck('id')->toArray())->not->toContain($this->inactiveBankAccount->id);
});

it('does not include non-bank accounts in bank account scope', function () {
    $bankAccounts = Account::bankAccounts()->get();

    $bankAccountIds = $bankAccounts->pluck('id')->toArray();
    expect($bankAccountIds)->not->toContain($this->cogsAccount->id);
    expect($bankAccountIds)->not->toContain($this->apAccount->id);
});

// === Phase 2: GL Account Selection ===

it('creates journal entry with selected bank account when paying payable', function () {
    $payable = JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_type' => 'vendor',
        'vendor_id' => $this->vendor->id,
        'amount' => 500.00,
        'status' => JobPayable::STATUS_APPROVED,
        'debit_account_id' => $this->materialsAccount->id,
        'credit_account_id' => $this->apAccount->id,
    ]);

    $service = app(JobPayableService::class);
    $result = $service->markPaid($payable, [
        'paid_via' => 'check',
        'reference_number' => '1001',
        'paid_from_account_id' => $this->bankAccount->id,
    ]);

    expect($result->isPaid())->toBeTrue();
    expect($result->paid_from_account_id)->toBe($this->bankAccount->id);

    // Journal entry should credit the bank account, not AP
    $entry = JournalEntry::find($result->journal_entry_id);
    expect($entry)->not->toBeNull();

    $creditLine = $entry->lines->firstWhere('credit_amount', '>', 0);
    expect($creditLine->account_id)->toBe($this->bankAccount->id);
});

it('persists custom GL accounts on payable creation via AR deal page', function () {
    $customExpense = Account::factory()->expense()->create(['code' => '5099', 'name' => 'Custom Expense']);
    $customLiability = Account::factory()->liability()->create(['code' => '2099', 'name' => 'Custom Liability']);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('openAddPayableModal')
        ->set('newPayableType', 'vendor')
        ->set('newPayableVendorId', $this->vendor->id)
        ->set('newPayableAmount', 250.00)
        ->set('newPayableDescription', 'Custom GL test')
        ->set('newPayableCategory', 'materials')
        ->set('newPayableDebitAccountId', $customExpense->id)
        ->set('newPayableCreditAccountId', $customLiability->id)
        ->call('createPayable');

    $payable = JobPayable::where('description', 'Custom GL test')->first();
    expect($payable)->not->toBeNull();
    expect($payable->debit_account_id)->toBe($customExpense->id);
    expect($payable->credit_account_id)->toBe($customLiability->id);
});

it('defaults crew payables to COGS account 5010', function () {
    $service = app(JobPayableService::class);
    $crew = User::factory()->crew()->create();

    $payable = $service->createCrewManualPayable($this->job, $crew, [
        'amount' => 300.00,
        'description' => 'Crew manual pay',
        'category' => 'labor',
    ]);

    expect($payable->debit_account_id)->toBe($this->cogsAccount->id);
    expect($payable->credit_account_id)->toBe($this->apAccount->id);
});

it('defaults employee payables to owner salary account 6010', function () {
    $service = app(JobPayableService::class);
    $employee = User::factory()->internal()->create();

    $payable = $service->createEmployeePayable($this->job, $employee, [
        'amount' => 1000.00,
        'description' => 'Owner draw',
        'category' => 'commission',
    ]);

    expect($payable->debit_account_id)->toBe($this->ownerSalaryAccount->id);
    expect($payable->credit_account_id)->toBe($this->apAccount->id);
});

it('defaults vendor payables to materials account 5030', function () {
    $service = app(JobPayableService::class);

    $payable = $service->createVendorPayable($this->job, $this->vendor, [
        'amount' => 400.00,
        'description' => 'Paint supplies',
        'category' => 'materials',
    ]);

    expect($payable->debit_account_id)->toBe($this->materialsAccount->id);
    expect($payable->credit_account_id)->toBe($this->apAccount->id);
});

// === Phase 3: Crew Bonus Commission Auto-Creation ===

it('creates crew bonus commission entry on job completion', function () {
    $crew = User::factory()->crew()->create();
    $crew->crewProfile->update(['bonus_percent' => 5.00]);

    // Create employee profile so commission entry is created (not payable)
    EmployeeProfile::factory()->create([
        'user_id' => $crew->id,
        'employment_status' => 'active',
    ]);

    // Attach crew to job as accepted
    $this->job->crews()->attach($crew->id, ['status' => 'accepted']);

    // Need an estimate with a total for revenue calculation
    $estimate = Estimate::factory()->create([
        'deal_id' => $this->deal->id,
        'total' => 10000.00,
    ]);
    $this->job->update(['estimate_id' => $estimate->id]);

    $service = app(CommissionService::class);
    $entries = $service->createCrewBonusFromJob($this->job->refresh());

    expect($entries)->toHaveCount(1);
    expect((float) $entries[0]->amount)->toBe(500.00); // 5% of 10000
    expect($entries[0]->status)->toBe('pending');
    expect($entries[0]->source_label)->toContain('Crew Bonus');
});

it('prevents duplicate crew bonus commission entries', function () {
    $crew = User::factory()->crew()->create();
    $crew->crewProfile->update(['bonus_percent' => 5.00]);

    EmployeeProfile::factory()->create([
        'user_id' => $crew->id,
        'employment_status' => 'active',
    ]);

    $this->job->crews()->attach($crew->id, ['status' => 'accepted']);

    $estimate = Estimate::factory()->create([
        'deal_id' => $this->deal->id,
        'total' => 10000.00,
    ]);
    $this->job->update(['estimate_id' => $estimate->id]);

    $service = app(CommissionService::class);
    $first = $service->createCrewBonusFromJob($this->job->refresh());
    $second = $service->createCrewBonusFromJob($this->job->refresh());

    expect($first)->toHaveCount(1);
    expect($second)->toHaveCount(0);

    // Only one entry in the database
    $count = CommissionEntry::where('source_type', Job::class)
        ->where('source_id', $this->job->id)
        ->where('source_label', 'like', 'Crew Bonus:%')
        ->count();
    expect($count)->toBe(1);
});

it('skips crew bonus when bonus_percent is zero', function () {
    $crew = User::factory()->crew()->create();
    $crew->crewProfile->update(['bonus_percent' => 0.00]);

    EmployeeProfile::factory()->create([
        'user_id' => $crew->id,
        'employment_status' => 'active',
    ]);

    $this->job->crews()->attach($crew->id, ['status' => 'accepted']);

    $estimate = Estimate::factory()->create([
        'deal_id' => $this->deal->id,
        'total' => 10000.00,
    ]);
    $this->job->update(['estimate_id' => $estimate->id]);

    $service = app(CommissionService::class);
    $entries = $service->createCrewBonusFromJob($this->job->refresh());

    expect($entries)->toHaveCount(0);
});

it('displays commissions on the AR deal page', function () {
    $crew = User::factory()->crew()->create();
    $crew->crewProfile->update(['bonus_percent' => 5.00]);

    $empProfile = EmployeeProfile::factory()->create([
        'user_id' => $crew->id,
        'employment_status' => 'active',
    ]);

    CommissionEntry::factory()->create([
        'employee_profile_id' => $empProfile->id,
        'source_type' => Job::class,
        'source_id' => $this->job->id,
        'source_label' => 'Crew Bonus: Test Job',
        'commission_type' => 'flat',
        'basis_amount' => 10000.00,
        'rate' => 0.0500,
        'amount' => 500.00,
        'status' => 'pending',
        'earned_date' => now()->toDateString(),
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->assertSee('Crew Bonus: Test Job')
        ->assertSee('$500.00');
});

it('approves and reverses commissions from AR deal page', function () {
    $crew = User::factory()->crew()->create();
    $empProfile = EmployeeProfile::factory()->create([
        'user_id' => $crew->id,
        'employment_status' => 'active',
    ]);

    $commission = CommissionEntry::factory()->create([
        'employee_profile_id' => $empProfile->id,
        'source_type' => Job::class,
        'source_id' => $this->job->id,
        'source_label' => 'Crew Bonus: Approve Test',
        'amount' => 300.00,
        'status' => 'pending',
        'earned_date' => now()->toDateString(),
    ]);

    // Approve
    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('approveCommission', $commission->id);

    $commission->refresh();
    expect($commission->status)->toBe('approved');
    expect($commission->approved_by)->toBe($this->owner->id);

    // Reverse
    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('reverseCommission', $commission->id);

    $commission->refresh();
    expect($commission->status)->toBe('reversed');
});

// === Phase 4: Deal Money Allocation Override ===

it('saves allocation actual amount overrides and resets them', function () {
    $allocation = JobCostCardAllocation::factory()->create([
        'job_cost_card_id' => $this->costCard->id,
        'name' => 'Marketing',
        'slug' => 'marketing',
        'type' => 'percentage',
        'value' => '5.0000',
        'amount' => 500.00,
        'actual_amount' => null,
    ]);

    $component = Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('startEditingAllocations');

    $component
        ->set("allocationOverrides.{$allocation->id}", 350.00)
        ->call('saveAllocations');

    $allocation->refresh();
    expect((float) $allocation->actual_amount)->toBe(350.00);

    // Reset
    $component = Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('startEditingAllocations')
        ->call('resetAllocations');

    $allocation->refresh();
    expect($allocation->actual_amount)->toBeNull();
});

it('saves allocation GL account assignment', function () {
    $allocation = JobCostCardAllocation::factory()->create([
        'job_cost_card_id' => $this->costCard->id,
        'name' => 'Sales Commission',
        'slug' => 'sales_commission',
        'type' => 'percentage',
        'value' => '3.0000',
        'amount' => 300.00,
        'account_id' => null,
    ]);

    $expenseAccount = Account::factory()->expense()->create(['code' => '6200', 'name' => 'Sales Expense']);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('startEditingAllocations')
        ->set("allocationAccounts.{$allocation->id}", $expenseAccount->id)
        ->call('saveAllocations');

    $allocation->refresh();
    expect($allocation->account_id)->toBe($expenseAccount->id);
});

// === Phase 5: Vendor Bills and Attachments ===

it('creates vendor payable with invoice number and attachment via AR deal', function () {
    Storage::fake('public');

    $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('openAddPayableModal')
        ->set('newPayableType', 'vendor')
        ->set('newPayableVendorId', $this->vendor->id)
        ->set('newPayableAmount', 750.00)
        ->set('newPayableDescription', 'Vendor bill with attachment')
        ->set('newPayableCategory', 'materials')
        ->set('newPayableInvoiceNumber', 'INV-2026-001')
        ->set('newPayableAttachment', $file)
        ->call('createPayable');

    $payable = JobPayable::where('description', 'Vendor bill with attachment')->first();
    expect($payable)->not->toBeNull();
    expect($payable->vendor_invoice_number)->toBe('INV-2026-001');
    expect($payable->attachment_path)->not->toBeNull();
    expect($payable->hasAttachment())->toBeTrue();

    Storage::disk('public')->assertExists($payable->attachment_path);
});

it('creates standalone vendor bill without job_id via payments hub', function () {
    // SQLite cannot make job_id nullable via ALTER, so skip on SQLite
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite does not support nullable job_id migration');
    }

    Livewire::actingAs($this->owner)
        ->test('accounting.payments-hub')
        ->call('openCreateBillModal')
        ->set('billVendorId', $this->vendor->id)
        ->set('billAmount', 200.00)
        ->set('billDescription', 'Standalone vendor bill')
        ->set('billCategory', 'sundries')
        ->call('createVendorBill');

    $payable = JobPayable::where('description', 'Standalone vendor bill')->first();
    expect($payable)->not->toBeNull();
    expect($payable->job_id)->toBeNull();
    expect($payable->vendor_id)->toBe($this->vendor->id);
    expect((float) $payable->amount)->toBe(200.00);
});

it('saves paid_from_account_id when marking payable as paid', function () {
    $payable = JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_type' => 'vendor',
        'vendor_id' => $this->vendor->id,
        'amount' => 300.00,
        'status' => JobPayable::STATUS_APPROVED,
        'debit_account_id' => $this->materialsAccount->id,
        'credit_account_id' => $this->apAccount->id,
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('openPaymentModal', $payable->id)
        ->set('paymentMethod', 'ach')
        ->set('paymentReference', 'ACH-99')
        ->set('paymentFromAccountId', $this->bankAccount2->id)
        ->call('markPayableAsPaid');

    $payable->refresh();
    expect($payable->isPaid())->toBeTrue();
    expect($payable->paid_from_account_id)->toBe($this->bankAccount2->id);
    expect($payable->paid_via)->toBe('ach');
    expect($payable->reference_number)->toBe('ACH-99');
});

// === Phase 6: Cost Breakdown Display ===

it('renders cost breakdown section on AR deal page', function () {
    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->assertSee('COGS Breakdown')
        ->assertSee('Projected')
        ->assertSee('Actual')
        ->assertSee('Variance');
});

// === Cross-cutting: Existing tests still pass (regression check) ===

it('renders AR deal page with all sections', function () {
    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->assertStatus(200)
        ->assertSee($this->deal->title)
        ->assertSee('Payables')
        ->assertSee('Allocations')
        ->assertSee('Profit Summary');
});

it('denies access to users without ar.view permission', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->assertForbidden();
});
