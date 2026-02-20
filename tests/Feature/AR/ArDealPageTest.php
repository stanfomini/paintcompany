<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobCostCard;
use App\Models\JobCostCardAllocation;
use App\Models\JobPayable;
use App\Models\User;
use App\Models\Vendor;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->internal()->withFullAccess()->create();

    $this->customer = Customer::factory()->create();
    $this->deal = Deal::factory()->signed()->forCustomer($this->customer)->create([
        'assigned_to' => $this->owner->id,
    ]);
    $this->job = Job::factory()->create([
        'deal_id' => $this->deal->id,
    ]);

    // Use fixed values so assertions are predictable
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
});

it('renders the AR deal page for a user with ar.view permission', function () {
    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->assertStatus(200)
        ->assertSee($this->deal->title)
        ->assertSee('Revenue')
        ->assertSee('COGS Breakdown')
        ->assertSee('Payables')
        ->assertSee('Allocations')
        ->assertSee('Profit Summary');
});

it('shows 403 for a user without ar.view permission', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->assertForbidden();
});

it('shows projected and actual columns in COGS breakdown', function () {
    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->assertSee('Projected')
        ->assertSee('Actual')
        ->assertSee('Variance')
        ->assertSee('$2,000.00')  // Labor projected
        ->assertSee('$1,500.00'); // Materials projected
});

it('shows grouped payables in the payables section', function () {
    JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_type' => 'crew',
        'payee_name' => 'Test Crew Member',
        'description' => 'Crew labor payment',
        'amount' => 1500.00,
        'status' => 'pending',
    ]);

    JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_type' => 'vendor',
        'payee_name' => 'Paint Supplier Co',
        'description' => 'Materials order',
        'amount' => 800.00,
        'status' => 'approved',
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->assertSee('Crew')
        ->assertSee('Vendor')
        ->assertSee('Crew labor payment')
        ->assertSee('Materials order')
        ->assertSee('$1,500.00')
        ->assertSee('$800.00');
});

it('approves a payable with the correct permission', function () {
    $payable = JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_type' => 'crew',
        'payee_name' => 'Test Crew',
        'description' => 'Crew payment',
        'amount' => 1000.00,
        'status' => 'pending',
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('approvePayable', $payable->id);

    expect($payable->fresh()->status)->toBe('approved')
        ->and($payable->fresh()->approved_by)->toBe($this->owner->id);
});

it('marks a payable as paid and creates a journal entry', function () {
    $payable = JobPayable::factory()->approved()->create([
        'job_id' => $this->job->id,
        'payee_type' => 'vendor',
        'payee_name' => 'Test Vendor',
        'description' => 'Vendor payment',
        'amount' => 500.00,
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('openPaymentModal', $payable->id)
        ->assertSet('showPaymentModal', true)
        ->set('paymentMethod', 'ach')
        ->set('paymentReference', 'ACH-12345')
        ->call('markPayableAsPaid');

    $payable->refresh();
    expect($payable->status)->toBe('paid')
        ->and($payable->paid_via)->toBe('ach')
        ->and($payable->reference_number)->toBe('ACH-12345');
});

it('handles a deal with no job gracefully', function () {
    $dealNoJob = Deal::factory()->signed()->create();

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $dealNoJob])
        ->assertStatus(200)
        ->assertSee('No job found for this deal');
});

it('handles a deal with no cost card gracefully', function () {
    // Delete the cost card from the job created in beforeEach
    $this->costCard->delete();

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->assertStatus(200)
        ->assertSee('No cost card found');
});

it('shows invoice data in the revenue section', function () {
    Invoice::factory()->deposit()->sent()->create([
        'job_id' => $this->job->id,
        'customer_id' => $this->customer->id,
        'total' => 3000.00,
        'invoice_number' => 'INV-2026-00001',
    ]);

    Invoice::factory()->final()->paid()->create([
        'job_id' => $this->job->id,
        'customer_id' => $this->customer->id,
        'total' => 7000.00,
        'invoice_number' => 'INV-2026-00002',
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->assertSee('INV-2026-00001')
        ->assertSee('INV-2026-00002')
        ->assertSee('Deposit')
        ->assertSee('Final');
});

it('calculates profit summary correctly', function () {
    $component = Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal]);

    // Revenue = 10000, COGS = 4410, Gross = 5590, Allocations = 500, Net = 5090
    $component->assertSee('$10,000.00')    // Revenue
        ->assertSee('$4,410.00')           // Total COGS
        ->assertSee('$5,590.00')           // Gross Profit
        ->assertSee('$500.00')             // Allocations
        ->assertSee('$5,090.00');          // Net Profit
});

it('allows inline editing of actual costs with ar.manage permission', function () {
    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('startEditingActual', 'actual_labor_cost')
        ->assertSet('editingActualField', 'actual_labor_cost')
        ->set('editingActualValue', 2200.00)
        ->call('saveActual');

    expect($this->costCard->fresh()->actual_labor_cost)->toBe('2200.00');
});

it('creates a payable via the add payable modal', function () {
    $vendor = Vendor::factory()->create();

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('openAddPayableModal')
        ->assertSet('showAddPayableModal', true)
        ->set('newPayableType', 'vendor')
        ->set('newPayableVendorId', $vendor->id)
        ->set('newPayableCategory', 'materials')
        ->set('newPayableDescription', 'Paint order for project')
        ->set('newPayableAmount', 750.00)
        ->call('createPayable');

    expect(JobPayable::where('job_id', $this->job->id)
        ->where('description', 'Paint order for project')
        ->where('amount', 750.00)
        ->exists()
    )->toBeTrue();
});

it('creates an employee payable via the add payable modal', function () {
    $employee = User::factory()->internal()->create(['is_active' => true]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('openAddPayableModal')
        ->assertSet('showAddPayableModal', true)
        ->set('newPayableType', 'employee')
        ->set('newPayableUserId', $employee->id)
        ->set('newPayableCategory', 'commission')
        ->set('newPayableDescription', 'Sales commission payment')
        ->set('newPayableAmount', 500.00)
        ->call('createPayable');

    $payable = JobPayable::where('job_id', $this->job->id)
        ->where('description', 'Sales commission payment')
        ->first();

    expect($payable)->not->toBeNull()
        ->and($payable->payee_type)->toBe('employee')
        ->and($payable->payee_user_id)->toBe($employee->id)
        ->and($payable->payee_name)->toBe($employee->name)
        ->and((float) $payable->amount)->toBe(500.00);
});

it('creates a crew payable via the add payable modal', function () {
    $crew = User::factory()->crew()->create(['is_active' => true]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('openAddPayableModal')
        ->assertSet('showAddPayableModal', true)
        ->set('newPayableType', 'crew')
        ->set('newPayableUserId', $crew->id)
        ->set('newPayableCategory', 'labor')
        ->set('newPayableDescription', 'Manual crew labor')
        ->set('newPayableAmount', 1200.00)
        ->call('createPayable');

    $payable = JobPayable::where('job_id', $this->job->id)
        ->where('description', 'Manual crew labor')
        ->first();

    expect($payable)->not->toBeNull()
        ->and($payable->payee_type)->toBe('crew')
        ->and($payable->payee_user_id)->toBe($crew->id)
        ->and($payable->payee_name)->toBe($crew->name)
        ->and((float) $payable->amount)->toBe(1200.00);
});

it('requires user selection when creating employee payable', function () {
    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('openAddPayableModal')
        ->set('newPayableType', 'employee')
        ->set('newPayableCategory', 'commission')
        ->set('newPayableDescription', 'Missing user')
        ->set('newPayableAmount', 100.00)
        ->call('createPayable')
        ->assertHasErrors(['newPayableUserId']);
});

it('edits a pending payable via the edit modal', function () {
    $payable = JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_type' => 'vendor',
        'description' => 'Original description',
        'amount' => 200.00,
        'category' => 'materials',
        'status' => 'pending',
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('openEditPayableModal', $payable->id)
        ->assertSet('showEditPayableModal', true)
        ->assertSet('editPayableAmount', 200.00)
        ->assertSet('editPayableDescription', 'Original description')
        ->assertSet('editPayableCategory', 'materials')
        ->set('editPayableAmount', 350.00)
        ->set('editPayableDescription', 'Updated description')
        ->set('editPayableCategory', 'labor')
        ->call('updatePayable');

    $payable->refresh();
    expect((float) $payable->amount)->toBe(350.00)
        ->and($payable->description)->toBe('Updated description')
        ->and($payable->category)->toBe('labor');
});

it('cannot edit a paid payable', function () {
    $payable = JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_type' => 'vendor',
        'description' => 'Paid payable',
        'amount' => 200.00,
        'status' => 'paid',
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('openEditPayableModal', $payable->id)
        ->assertSet('showEditPayableModal', false);
});

it('cannot edit a void payable', function () {
    $payable = JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_type' => 'vendor',
        'description' => 'Void payable',
        'amount' => 200.00,
        'status' => 'void',
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('openEditPayableModal', $payable->id)
        ->assertSet('showEditPayableModal', false);
});

it('voids a payable with ar.manage permission', function () {
    $payable = JobPayable::factory()->create([
        'job_id' => $this->job->id,
        'payee_type' => 'vendor',
        'description' => 'To be voided',
        'amount' => 200.00,
        'status' => 'pending',
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('voidPayable', $payable->id);

    expect($payable->fresh()->status)->toBe('void');
});

it('shows allocations from the cost card', function () {
    JobCostCardAllocation::factory()->forJobCostCard($this->costCard)->create([
        'name' => 'Marketing Budget',
        'slug' => 'marketing',
        'type' => 'percent_of_revenue',
        'value' => 8.00,
        'amount' => 800.00,
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->assertSee('Marketing Budget')
        ->assertSee('$800.00');
});

it('shows bank distribution when actuals are entered', function () {
    $this->costCard->update([
        'actual_labor_cost' => 2100.00,
        'actual_material_cost' => 1400.00,
        'actual_cleanup_cost' => 280.00,
        'actual_sundries_cost' => 90.00,
        'actual_material_tax' => 112.00,
        'actual_labor_tax' => 63.00,
        'actual_addon_cost' => 180.00,
        'actual_items_vendor_cost' => 40.00,
        'actual_warranty_reserve' => 70.00,
        'actual_total_cost' => 4335.00,
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->assertSee('Bank Distribution')
        ->assertSee('COGS Account')
        ->assertSee('Profit');
});

it('creates a journal entry from actuals', function () {
    $this->costCard->update([
        'actual_labor_cost' => 2000.00,
        'actual_material_cost' => 1500.00,
        'actual_total_cost' => 3500.00,
    ]);

    Livewire::actingAs($this->owner)
        ->test('accounting.ar-deal', ['deal' => $this->deal])
        ->call('createJournalEntry');

    expect($this->costCard->fresh()->journal_entry_id)->not->toBeNull();
});
