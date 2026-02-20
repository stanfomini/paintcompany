<?php

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Job;
use App\Models\JobCostCard;
use App\Models\JobPayable;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Services\JobDispatchService;
use App\Services\JobPayableService;

function paintingCategory(): ServiceCategory
{
    return ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'description' => 'Interior and exterior painting services', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );
}

beforeEach(function () {
    $this->settings = CompanySetting::getInstance();

    // Create the COGS and AP accounts used by the service
    $this->cogsAccount = Account::factory()->expense()->create([
        'code' => '5010',
        'name' => 'Cost of Goods Sold',
    ]);
    $this->apAccount = Account::factory()->liability()->create([
        'code' => '2000',
        'name' => 'Accounts Payable',
    ]);

    $this->service = new JobPayableService;
});

it('creates crew deposit and final payables for painting crew', function () {
    $paintingCat = paintingCategory();
    $job = Job::factory()->create();
    $crew = User::factory()->crew()->create();
    $crew->crewProfile->update(['service_category_id' => $paintingCat->id]);

    JobCostCard::factory()->forJob($job)->create([
        'labor_cost' => 1000.00,
        'material_cost' => 300.00,
        'sundries_cost' => 50.00,
        'material_tax' => 24.00,
        'cleanup_cost' => 150.00,
        'labor_tax' => 30.00,
        'warranty_reserve' => 25.00,
    ]);

    $payables = $this->service->createCrewPayables($job, $crew, $paintingCat);

    expect($payables)->toHaveCount(2);
    expect($payables[0]->category)->toBe('materials');
    expect($payables[1]->category)->toBe('labor');
    expect($payables[0]->payee_user_id)->toBe($crew->id);
    expect($payables[1]->payee_user_id)->toBe($crew->id);
});

it('calculates correct deposit amount for painting crew', function () {
    $paintingCat = paintingCategory();
    $job = Job::factory()->create();
    $crew = User::factory()->crew()->create();

    JobCostCard::factory()->forJob($job)->create([
        'labor_cost' => 1000.00,
        'material_cost' => 300.00,
        'sundries_cost' => 50.00,
        'material_tax' => 24.00,
    ]);

    $payables = $this->service->createCrewPayables($job, $crew, $paintingCat);

    // Deposit = materials (300) + sundries (50) + material_tax (24) + 5% of labor (50) = 424.00
    $deposit = $payables[0];
    expect((float) $deposit->amount)->toBe(424.00);
});

it('calculates correct final amount for painting crew', function () {
    $paintingCat = paintingCategory();
    $job = Job::factory()->create();
    $crew = User::factory()->crew()->create();

    JobCostCard::factory()->forJob($job)->create([
        'labor_cost' => 1000.00,
        'cleanup_cost' => 150.00,
        'labor_tax' => 30.00,
        'warranty_reserve' => 25.00,
    ]);

    $payables = $this->service->createCrewPayables($job, $crew, $paintingCat);

    // Final = 95% of labor (950) + cleanup (150) + labor_tax (30) + warranty_reserve (25) = 1155.00
    $final = $payables[1];
    expect((float) $final->amount)->toBe(1155.00);
});

it('marks payable as paid and creates journal entry', function () {
    $job = Job::factory()->create();
    $admin = User::factory()->internal()->create();
    $this->actingAs($admin);

    $payable = JobPayable::factory()->create([
        'job_id' => $job->id,
        'amount' => 500.00,
        'debit_account_id' => $this->cogsAccount->id,
        'credit_account_id' => $this->apAccount->id,
        'created_by' => $admin->id,
    ]);

    $result = $this->service->markPaid($payable, [
        'paid_via' => 'check',
        'reference_number' => 'CHK-001',
    ]);

    expect($result->status)->toBe('paid');
    expect($result->paid_via)->toBe('check');
    expect($result->reference_number)->toBe('CHK-001');
    expect($result->paid_at)->not->toBeNull();
    expect($result->journal_entry_id)->not->toBeNull();

    // Verify journal entry DR COGS / CR AP
    $entry = $result->journalEntry;
    expect($entry)->not->toBeNull();
    expect($entry->lines)->toHaveCount(2);

    $debitLine = $entry->lines->firstWhere('debit_amount', '>', 0);
    $creditLine = $entry->lines->firstWhere('credit_amount', '>', 0);

    expect($debitLine->account_id)->toBe($this->cogsAccount->id);
    expect((float) $debitLine->debit_amount)->toBe(500.00);
    expect($creditLine->account_id)->toBe($this->apAccount->id);
    expect((float) $creditLine->credit_amount)->toBe(500.00);
});

it('approves payable and records approver', function () {
    $admin = User::factory()->internal()->create();

    $payable = JobPayable::factory()->create([
        'status' => 'pending',
        'created_by' => $admin->id,
    ]);

    $result = $this->service->approve($payable, $admin);

    expect($result->status)->toBe('approved');
    expect($result->approved_by)->toBe($admin->id);
});

it('voids a payable', function () {
    $payable = JobPayable::factory()->create([
        'status' => 'pending',
        'created_by' => User::factory()->internal(),
    ]);

    $payable->void();

    expect($payable->fresh()->status)->toBe('void');
    expect($payable->isVoid())->toBeTrue();
});

it('creates vendor payable linked to vendor model', function () {
    $job = Job::factory()->create();
    $vendor = Vendor::factory()->create();
    $admin = User::factory()->internal()->create();
    $this->actingAs($admin);

    $payable = $this->service->createVendorPayable($job, $vendor, [
        'amount' => 750.00,
        'category' => 'materials',
        'description' => 'Paint supply order',
    ]);

    expect($payable->vendor_id)->toBe($vendor->id);
    expect($payable->payee_type)->toBe('vendor');
    expect($payable->payee_name)->toBe($vendor->name);
    expect((float) $payable->amount)->toBe(750.00);
});

it('creates employee payable linked to user', function () {
    $job = Job::factory()->create();
    $employee = User::factory()->internal()->create();
    $admin = User::factory()->internal()->create();
    $this->actingAs($admin);

    $payable = $this->service->createEmployeePayable($job, $employee, [
        'amount' => 200.00,
        'category' => 'commission',
        'description' => 'Sales commission',
    ]);

    expect($payable->payee_user_id)->toBe($employee->id);
    expect($payable->payee_type)->toBe('employee');
    expect($payable->payee_name)->toBe($employee->name);
    expect((float) $payable->amount)->toBe(200.00);
});

it('creates manual crew payable linked to user', function () {
    $job = Job::factory()->create();
    $crew = User::factory()->crew()->create();
    $admin = User::factory()->internal()->create();
    $this->actingAs($admin);

    $payable = $this->service->createCrewManualPayable($job, $crew, [
        'amount' => 600.00,
        'category' => 'labor',
        'description' => 'Manual crew payment',
    ]);

    expect($payable->payee_user_id)->toBe($crew->id)
        ->and($payable->payee_type)->toBe('crew')
        ->and($payable->payee_name)->toBe($crew->name)
        ->and((float) $payable->amount)->toBe(600.00)
        ->and($payable->category)->toBe('labor')
        ->and($payable->description)->toBe('Manual crew payment')
        ->and($payable->debit_account_id)->toBe($this->cogsAccount->id)
        ->and($payable->credit_account_id)->toBe($this->apAccount->id);
});

it('returns correct job payables summary', function () {
    $job = Job::factory()->create();
    $admin = User::factory()->internal()->create();

    JobPayable::factory()->create([
        'job_id' => $job->id,
        'status' => 'pending',
        'amount' => 100.00,
        'category' => 'labor',
        'payee_type' => 'crew',
        'created_by' => $admin->id,
    ]);
    JobPayable::factory()->create([
        'job_id' => $job->id,
        'status' => 'approved',
        'amount' => 200.00,
        'category' => 'materials',
        'payee_type' => 'vendor',
        'created_by' => $admin->id,
    ]);
    JobPayable::factory()->create([
        'job_id' => $job->id,
        'status' => 'paid',
        'amount' => 300.00,
        'category' => 'labor',
        'payee_type' => 'crew',
        'created_by' => $admin->id,
    ]);

    $summary = $this->service->getJobPayablesSummary($job);

    expect($summary['total_pending'])->toBe(100.00);
    expect($summary['total_approved'])->toBe(200.00);
    expect($summary['total_paid'])->toBe(300.00);
    expect($summary['by_payee_type']['crew'])->toBe(400.00);
    expect($summary['by_payee_type']['vendor'])->toBe(200.00);
    expect($summary['by_category']['labor'])->toBe(400.00);
    expect($summary['by_category']['materials'])->toBe(200.00);
});

it('auto-creates crew payables when crew accepts job via dispatch service', function () {
    $paintingCat = paintingCategory();

    $crew = User::factory()->crew()->create();
    $crew->crewProfile->update([
        'service_category_id' => $paintingCat->id,
        'crew_size' => 3,
    ]);

    $job = Job::factory()->create(['required_crew_size' => 2]);

    JobCostCard::factory()->forJob($job)->create([
        'labor_cost' => 1000.00,
        'material_cost' => 300.00,
        'sundries_cost' => 50.00,
        'material_tax' => 24.00,
        'cleanup_cost' => 150.00,
        'labor_tax' => 30.00,
        'warranty_reserve' => 25.00,
    ]);

    $dispatchService = new JobDispatchService;
    $dispatchService->dispatchToCrews($job, [$crew->id], $paintingCat);
    $dispatchService->acceptJob($job, $crew);

    $payables = JobPayable::where('job_id', $job->id)
        ->where('payee_user_id', $crew->id)
        ->get();

    expect($payables)->toHaveCount(2);
    expect($payables->where('category', 'materials')->first())->not->toBeNull();
    expect($payables->where('category', 'labor')->first())->not->toBeNull();
});
