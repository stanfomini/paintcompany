<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\CompanySetting;
use App\Models\JobPayable;
use App\Models\User;
use App\Models\Vendor;
use Livewire\Livewire;

beforeEach(function () {
    $this->settings = CompanySetting::getInstance();
    $this->owner = User::factory()->internal()->withFullAccess()->create();
});

// ─── CRUD Tests ───

it('renders vendor management page for authorized users', function () {
    $this->actingAs($this->owner)
        ->get(route('vendors.edit'))
        ->assertOk()
        ->assertSeeLivewire('pages::settings.vendors');
});

it('denies access to users without vendors.view permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('vendors.edit'))
        ->assertForbidden();
});

it('can create a vendor with basic fields', function () {
    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('openAddModal')
        ->set('vendorName', 'Sherwin-Williams')
        ->set('contactName', 'John Doe')
        ->set('vendorEmail', 'john@sherwin.com')
        ->set('vendorPhone', '555-1234')
        ->set('vendorCategory', 'supplier')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('vendors', [
        'name' => 'Sherwin-Williams',
        'contact_name' => 'John Doe',
        'email' => 'john@sherwin.com',
        'phone' => '555-1234',
        'category' => 'supplier',
        'company_setting_id' => $this->settings->id,
        'is_active' => true,
    ]);
});

it('can create a vendor with full compliance and accounting fields', function () {
    $budgetCat = BudgetCategory::factory()->create(['name' => 'Materials']);
    $expenseAccount = Account::factory()->expense()->create(['code' => '5030', 'name' => 'Materials Expense']);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('openAddModal')
        ->set('vendorName', 'PPG Paints')
        ->set('vendorCategory', 'supplier')
        ->set('budgetCategoryId', $budgetCat->id)
        ->set('defaultExpenseAccountId', $expenseAccount->id)
        ->set('paymentTermsDays', 30)
        ->set('w9OnFile', true)
        ->set('holdHarmless', true)
        ->set('certificateOfInsurance', true)
        ->set('insuranceExpiration', '2027-01-01')
        ->set('additionalInsured', true)
        ->set('workersComp', true)
        ->set('workersCompExpiration', '2027-06-01')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('vendors', [
        'name' => 'PPG Paints',
        'budget_category_id' => $budgetCat->id,
        'default_expense_account_id' => $expenseAccount->id,
        'payment_terms_days' => 30,
        'w9_on_file' => true,
        'hold_harmless' => true,
        'certificate_of_insurance' => true,
        'workers_comp' => true,
    ]);
});

it('can update an existing vendor', function () {
    $vendor = Vendor::factory()->create(['name' => 'Old Name']);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('openEditModal', $vendor->id)
        ->assertSet('vendorName', 'Old Name')
        ->set('vendorName', 'New Name')
        ->set('vendorCategory', 'subcontractor')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('vendors', [
        'id' => $vendor->id,
        'name' => 'New Name',
        'category' => 'subcontractor',
    ]);
});

it('can toggle vendor active status', function () {
    $vendor = Vendor::factory()->create(['is_active' => true]);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('toggleActive', $vendor->id);

    expect($vendor->fresh()->is_active)->toBeFalse();
});

it('can delete a vendor without payables', function () {
    $vendor = Vendor::factory()->create();

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('confirmDelete', $vendor->id)
        ->assertSet('showDeleteModal', true)
        ->call('delete');

    $this->assertDatabaseMissing('vendors', ['id' => $vendor->id]);
});

it('cannot delete a vendor with payables', function () {
    $vendor = Vendor::factory()->create();
    JobPayable::factory()->create(['vendor_id' => $vendor->id]);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('confirmDelete', $vendor->id)
        ->assertSet('showDeleteModal', false);
});

// ─── Validation Tests ───

it('requires vendor name', function () {
    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('openAddModal')
        ->set('vendorName', '')
        ->call('save')
        ->assertHasErrors(['vendorName']);
});

it('validates vendor category is valid', function () {
    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('openAddModal')
        ->set('vendorName', 'Test')
        ->set('vendorCategory', 'invalid')
        ->call('save')
        ->assertHasErrors(['vendorCategory']);
});

it('validates email format when provided', function () {
    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->call('openAddModal')
        ->set('vendorName', 'Test')
        ->set('vendorEmail', 'not-an-email')
        ->call('save')
        ->assertHasErrors(['vendorEmail']);
});

// ─── Filter Tests ───

it('filters vendors by search term', function () {
    Vendor::factory()->create(['name' => 'Sherwin-Williams']);
    Vendor::factory()->create(['name' => 'Benjamin Moore']);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->set('search', 'Sherwin')
        ->assertSee('Sherwin-Williams')
        ->assertDontSee('Benjamin Moore');
});

it('filters vendors by category', function () {
    Vendor::factory()->create(['name' => 'Paint Supplier', 'category' => 'supplier']);
    Vendor::factory()->create(['name' => 'Sub Crew', 'category' => 'subcontractor']);
    Vendor::factory()->create(['name' => 'Other Supplier', 'category' => 'supplier']);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->set('categoryFilter', 'supplier')
        ->assertSee('Paint Supplier')
        ->assertSee('Other Supplier')
        ->assertDontSee('Sub Crew');
});

it('filters vendors by active status', function () {
    Vendor::factory()->create(['name' => 'Active Vendor 1', 'is_active' => true]);
    Vendor::factory()->create(['name' => 'Inactive Vendor', 'is_active' => false]);
    Vendor::factory()->create(['name' => 'Active Vendor 2', 'is_active' => true]);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->set('statusFilter', 'inactive')
        ->assertSee('Inactive Vendor')
        ->assertDontSee('Active Vendor 1')
        ->assertDontSee('Active Vendor 2');
});

// ─── Model Helper Tests ───

it('calculates total paid in year', function () {
    $vendor = Vendor::factory()->create();

    JobPayable::factory()->create([
        'vendor_id' => $vendor->id,
        'payee_type' => 'vendor',
        'amount' => 500,
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    JobPayable::factory()->create([
        'vendor_id' => $vendor->id,
        'payee_type' => 'vendor',
        'amount' => 300,
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    // Different year - should not count
    JobPayable::factory()->create([
        'vendor_id' => $vendor->id,
        'payee_type' => 'vendor',
        'amount' => 200,
        'status' => 'paid',
        'paid_at' => now()->subYear(),
    ]);

    expect($vendor->totalPaidInYear(now()->year))->toBe(800.0);
});

it('determines 1099 requirement based on threshold', function () {
    $vendor = Vendor::factory()->create();

    // Under threshold
    JobPayable::factory()->create([
        'vendor_id' => $vendor->id,
        'payee_type' => 'vendor',
        'amount' => 599,
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    expect($vendor->is1099Required(now()->year))->toBeFalse();

    // Add more to exceed threshold
    JobPayable::factory()->create([
        'vendor_id' => $vendor->id,
        'payee_type' => 'vendor',
        'amount' => 1,
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    expect($vendor->fresh()->is1099Required(now()->year))->toBeTrue();
});

it('returns compliance status correctly', function () {
    $vendor = Vendor::factory()->compliant()->create();
    $status = $vendor->complianceStatus();

    expect($status['w9']['status'])->toBe('ok')
        ->and($status['insurance']['status'])->toBe('ok')
        ->and($status['workers_comp']['status'])->toBe('ok')
        ->and($status['hold_harmless']['status'])->toBe('ok');

    $missingVendor = Vendor::factory()->create();
    $missingStatus = $missingVendor->complianceStatus();

    expect($missingStatus['w9']['status'])->toBe('missing')
        ->and($missingStatus['insurance']['status'])->toBe('missing')
        ->and($missingStatus['workers_comp']['status'])->toBe('missing')
        ->and($missingStatus['hold_harmless']['status'])->toBe('missing');
});

it('detects expired insurance', function () {
    $vendor = Vendor::factory()->expiredInsurance()->create();

    expect($vendor->hasExpiredInsurance())->toBeTrue();
});

it('detects expired workers comp', function () {
    $vendor = Vendor::factory()->expiredWorkersComp()->create();

    expect($vendor->hasExpiredWorkersComp())->toBeTrue();
});

it('recognizes rejection form as valid workers comp alternative', function () {
    $vendor = Vendor::factory()->create([
        'workers_comp' => false,
        'rejection_form' => true,
    ]);

    $status = $vendor->complianceStatus();

    expect($status['workers_comp']['status'])->toBe('ok')
        ->and($status['workers_comp']['detail'])->toBe('Rejection form on file');
});

// ─── Quick-Add Tests ───

it('quick-add vendor works from payments hub', function () {
    Livewire::actingAs($this->owner)
        ->test('accounting.payments-hub')
        ->set('showQuickAddVendor', true)
        ->set('quickVendorName', 'Quick Vendor LLC')
        ->set('quickVendorCategory', 'service')
        ->call('quickAddVendor')
        ->assertSet('showQuickAddVendor', false);

    $this->assertDatabaseHas('vendors', [
        'name' => 'Quick Vendor LLC',
        'category' => 'service',
        'is_active' => true,
    ]);
});

it('quick-add vendor works from AR deal page', function () {
    $customer = \App\Models\Customer::factory()->create();
    $deal = \App\Models\Deal::factory()->signed()->forCustomer($customer)->create([
        'assigned_to' => $this->owner->id,
    ]);
    \App\Models\Job::factory()->create(['deal_id' => $deal->id]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $deal])
        ->set('showQuickAddVendor', true)
        ->set('quickVendorName', 'Quick AR Vendor')
        ->set('quickVendorCategory', 'supplier')
        ->call('quickAddVendor')
        ->assertSet('showQuickAddVendor', false);

    $this->assertDatabaseHas('vendors', [
        'name' => 'Quick AR Vendor',
        'category' => 'supplier',
    ]);
});

// ─── Default Expense Account Tests ───

it('auto-populates debit account when vendor with default expense is selected in payments hub', function () {
    $account = Account::factory()->expense()->create(['code' => '5030']);
    $vendor = Vendor::factory()->create(['default_expense_account_id' => $account->id]);

    Livewire::actingAs($this->owner)
        ->test('accounting.payments-hub')
        ->set('billVendorId', $vendor->id)
        ->assertSet('billDebitAccountId', $account->id);
});

it('auto-populates debit account when vendor with default expense is selected in AR deal', function () {
    $account = Account::factory()->expense()->create(['code' => '5030']);
    $vendor = Vendor::factory()->create(['default_expense_account_id' => $account->id]);

    $customer = \App\Models\Customer::factory()->create();
    $deal = \App\Models\Deal::factory()->signed()->forCustomer($customer)->create([
        'assigned_to' => $this->owner->id,
    ]);
    \App\Models\Job::factory()->create(['deal_id' => $deal->id]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $deal])
        ->set('newPayableVendorId', $vendor->id)
        ->assertSet('newPayableDebitAccountId', $account->id);
});

// ─── Compliance Summary Tests ───

it('shows compliance warnings when vendors have issues', function () {
    Vendor::factory()->create(['w9_on_file' => false]);
    Vendor::factory()->expiredInsurance()->create();
    Vendor::factory()->expiredWorkersComp()->create();

    Livewire::actingAs($this->owner)
        ->test('pages::settings.vendors')
        ->assertSee('missing W-9');
});

// ─── Vendor Payables Relationship ───

it('vendor has payables relationship', function () {
    $vendor = Vendor::factory()->create();
    $payable = JobPayable::factory()->create(['vendor_id' => $vendor->id]);

    expect($vendor->payables)->toHaveCount(1)
        ->and($vendor->payables->first()->id)->toBe($payable->id);
});

it('hasPayables returns true when payables exist', function () {
    $vendor = Vendor::factory()->create();
    JobPayable::factory()->create(['vendor_id' => $vendor->id]);

    expect($vendor->hasPayables())->toBeTrue();
});

it('hasPayables returns false when no payables', function () {
    $vendor = Vendor::factory()->create();

    expect($vendor->hasPayables())->toBeFalse();
});
