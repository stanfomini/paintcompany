<?php

use App\Models\CommissionEntry;
use App\Models\CommissionTier;
use App\Models\Deal;
use App\Models\EmployeeProfile;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\PayPeriod;
use App\Models\PayStub;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new CommissionService;
});

// === Estimate Commissions ===

it('creates commission from estimate with revenue percentage rule', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.05, 'active' => true],
        ])
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $user->id,
        'total' => 10000,
        'status' => 'accepted',
    ]);

    $entries = $this->service->createFromEstimate($estimate);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->commission_type)->toBe('revenue_percentage')
        ->and((float) $entries[0]->basis_amount)->toBe(10000.00)
        ->and((float) $entries[0]->rate)->toEqualWithDelta(0.05, 0.0001)
        ->and((float) $entries[0]->amount)->toBe(500.00)
        ->and($entries[0]->status)->toBe('pending')
        ->and($entries[0]->source_type)->toBe(Estimate::class)
        ->and($entries[0]->source_id)->toBe($estimate->id);
});

it('creates commission with profit percentage', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'estimate', 'type' => 'profit_percentage', 'rate' => 0.10, 'active' => true],
        ])
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $user->id,
        'total' => 10000,
        'items_subtotal' => 0,
        'options_subtotal' => 0,
    ]);

    $entries = $this->service->createFromEstimate($estimate);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->commission_type)->toBe('profit_percentage')
        ->and((float) $entries[0]->rate)->toEqualWithDelta(0.10, 0.0001)
        ->and((float) $entries[0]->amount)->toBe(1000.00);
});

it('creates commission with flat amount from estimate', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'estimate', 'type' => 'flat', 'amount' => 250, 'active' => true],
        ])
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $user->id,
        'total' => 10000,
    ]);

    $entries = $this->service->createFromEstimate($estimate);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->commission_type)->toBe('flat')
        ->and((float) $entries[0]->amount)->toBe(250.00);
});

it('returns empty when deal assignee has no commission rules', function () {
    $user = User::factory()->internal()->create();
    EmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'commission_rules' => null,
    ]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $user->id,
        'total' => 10000,
    ]);

    $entries = $this->service->createFromEstimate($estimate);

    expect($entries)->toBeEmpty();
});

it('returns empty when deal has no assigned user', function () {
    $estimate = Estimate::factory()->create([
        'total' => 10000,
    ]);

    $entries = $this->service->createFromEstimate($estimate);

    expect($entries)->toBeEmpty();
});

// === Lead/Deal Commissions ===

it('creates lead commission with flat amount per lead', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'lead', 'type' => 'flat', 'amount' => 25, 'active' => true],
        ])
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create([
        'assigned_to' => $user->id,
        'title' => 'Smith Exterior Paint',
    ]);

    $entries = $this->service->createFromDeal($deal);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->commission_type)->toBe('flat')
        ->and((float) $entries[0]->amount)->toBe(25.00)
        ->and($entries[0]->source_type)->toBe(Deal::class)
        ->and($entries[0]->source_id)->toBe($deal->id)
        ->and($entries[0]->source_label)->toContain('Lead:');
});

it('returns empty for lead commission when user has no lead rules', function () {
    $user = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.05, 'active' => true],
        ])
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);

    $entries = $this->service->createFromDeal($deal);

    expect($entries)->toBeEmpty();
});

// === Job Commissions ===

it('creates job commission when job is completed', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'job', 'type' => 'revenue_percentage', 'rate' => 0.03, 'active' => true],
        ])
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'total' => 20000,
    ]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    $entries = $this->service->createFromJob($job);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->commission_type)->toBe('revenue_percentage')
        ->and((float) $entries[0]->amount)->toBe(600.00)
        ->and($entries[0]->source_type)->toBe(Job::class)
        ->and($entries[0]->source_id)->toBe($job->id);
});

// === Double Commission (Lead + Estimate) ===

it('supports double commission: lead flat + estimate percentage', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'lead', 'type' => 'flat', 'amount' => 25, 'active' => true],
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.05, 'active' => true],
        ])
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);

    // Step 1: Lead comes in -> $25
    $leadEntries = $this->service->createFromDeal($deal);
    expect($leadEntries)->toHaveCount(1)
        ->and((float) $leadEntries[0]->amount)->toBe(25.00);

    // Step 2: Estimate accepted -> 5% of $10k = $500
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'total' => 10000,
    ]);
    $estimateEntries = $this->service->createFromEstimate($estimate);
    expect($estimateEntries)->toHaveCount(1)
        ->and((float) $estimateEntries[0]->amount)->toBe(500.00);

    // Total: $525
    $total = CommissionEntry::where('employee_profile_id', $employee->id)->sum('amount');
    expect((float) $total)->toBe(525.00);
});

// === Triple Commission (Lead + Estimate + Job) ===

it('supports triple commission across the full lifecycle', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'lead', 'type' => 'flat', 'amount' => 50, 'active' => true],
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.05, 'active' => true],
            ['source' => 'job', 'type' => 'revenue_percentage', 'rate' => 0.02, 'active' => true],
        ])
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);

    // Lead: $50 flat
    $this->service->createFromDeal($deal);

    // Estimate accepted: 5% of $20k = $1000
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'total' => 20000,
    ]);
    $this->service->createFromEstimate($estimate);

    // Job completed: 2% of $20k = $400
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);
    $this->service->createFromJob($job);

    // Total: $50 + $1000 + $400 = $1450
    $total = CommissionEntry::where('employee_profile_id', $employee->id)->sum('amount');
    expect((float) $total)->toBe(1450.00);
    expect(CommissionEntry::where('employee_profile_id', $employee->id)->count())->toBe(3);
});

// === Multiple Rules Per Source ===

it('applies multiple rules for the same source', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'estimate', 'type' => 'flat', 'amount' => 100, 'active' => true],
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.03, 'active' => true],
        ])
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'total' => 10000,
    ]);

    $entries = $this->service->createFromEstimate($estimate);

    expect($entries)->toHaveCount(2);
    $amounts = collect($entries)->pluck('amount')->map(fn ($a) => (float) $a)->sort()->values()->all();
    expect($amounts)->toBe([100.00, 300.00]);
});

// === Inactive Rules ===

it('ignores inactive commission rules', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.05, 'active' => false],
            ['source' => 'estimate', 'type' => 'flat', 'amount' => 100, 'active' => true],
        ])
        ->create(['user_id' => $user->id]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'total' => 10000,
    ]);

    $entries = $this->service->createFromEstimate($estimate);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->commission_type)->toBe('flat')
        ->and((float) $entries[0]->amount)->toBe(100.00);
});

// === Tiered Commission ===

it('calculates tiered commission correctly', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'estimate', 'type' => 'tiered', 'period' => 'monthly', 'active' => true],
        ])
        ->create(['user_id' => $user->id]);

    CommissionTier::factory()->create([
        'employee_profile_id' => $employee->id,
        'threshold_min' => 0,
        'threshold_max' => 10000,
        'rate' => 0.03,
        'period_type' => 'monthly',
        'sort_order' => 0,
    ]);

    CommissionTier::factory()->create([
        'employee_profile_id' => $employee->id,
        'threshold_min' => 10000,
        'threshold_max' => 25000,
        'rate' => 0.05,
        'period_type' => 'monthly',
        'sort_order' => 1,
    ]);

    CommissionTier::factory()->create([
        'employee_profile_id' => $employee->id,
        'threshold_min' => 25000,
        'threshold_max' => null,
        'rate' => 0.07,
        'period_type' => 'monthly',
        'sort_order' => 2,
    ]);

    $deal = Deal::factory()->create(['assigned_to' => $user->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'total' => 15000,
    ]);

    $entries = $this->service->createFromEstimate($estimate);

    // $15k: first $10k at 3% = $300, next $5k at 5% = $250, total = $550
    expect($entries)->toHaveCount(1)
        ->and((float) $entries[0]->amount)->toBe(550.00);
});

// === Approve / Reverse ===

it('approves a commission entry', function () {
    $entry = CommissionEntry::factory()->create();
    $approver = User::factory()->internal()->create();

    $this->service->approveCommission($entry, $approver);
    $entry->refresh();

    expect($entry->status)->toBe('approved')
        ->and($entry->approved_by)->toBe($approver->id)
        ->and($entry->approved_at)->not->toBeNull();
});

it('reverses a commission entry with reason', function () {
    $entry = CommissionEntry::factory()->approved()->create();

    $this->service->reverseCommission($entry, 'Customer cancelled job');
    $entry->refresh();

    expect($entry->status)->toBe('reversed')
        ->and($entry->notes)->toContain('Reversed: Customer cancelled job');
});

// === Payroll Integration ===

it('gets pending commissions for payroll', function () {
    $employee = EmployeeProfile::factory()->create();
    $period = PayPeriod::factory()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
    ]);

    $approved = CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'earned_date' => now()->subDays(5),
    ]);

    // Pending (not approved)
    CommissionEntry::factory()->create([
        'employee_profile_id' => $employee->id,
        'earned_date' => now()->subDays(3),
        'status' => 'pending',
    ]);

    // Already paid
    $paidStub = PayStub::factory()->create(['employee_profile_id' => $employee->id]);
    CommissionEntry::factory()->paid()->create([
        'employee_profile_id' => $employee->id,
        'earned_date' => now()->subDays(10),
        'pay_period_id' => $period->id,
        'pay_stub_id' => $paidStub->id,
    ]);

    $pending = $this->service->getPendingForPayroll($employee, $period);

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->id)->toBe($approved->id);
});

it('marks commissions as paid', function () {
    $employee = EmployeeProfile::factory()->create();
    $period = PayPeriod::factory()->create();
    $stub = PayStub::factory()->create([
        'employee_profile_id' => $employee->id,
        'pay_period_id' => $period->id,
    ]);

    $entries = CommissionEntry::factory()->approved()->count(3)->create([
        'employee_profile_id' => $employee->id,
    ]);

    $this->service->markAsPaid(
        $entries->pluck('id')->toArray(),
        $period->id,
        $stub->id,
    );

    foreach ($entries as $entry) {
        $entry->refresh();
        expect($entry->status)->toBe('paid')
            ->and($entry->pay_period_id)->toBe($period->id)
            ->and($entry->pay_stub_id)->toBe($stub->id);
    }
});

// === Manual Entry ===

it('creates manual commission entry', function () {
    $employee = EmployeeProfile::factory()->create();

    $entry = $this->service->createManual($employee, 350, 'flat', 'Bonus for project');

    expect($entry->amount)->toBe('350.00')
        ->and($entry->commission_type)->toBe('flat')
        ->and($entry->status)->toBe('pending')
        ->and($entry->source_label)->toBe('Manual Entry')
        ->and($entry->notes)->toBe('Bonus for project');
});

// === Multiple People on Same Estimate ===

it('creates commissions for both deal assignee and estimate creator', function () {
    $assignee = User::factory()->internal()->create();
    $creator = User::factory()->internal()->create();

    EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.05, 'active' => true],
        ])
        ->create(['user_id' => $assignee->id]);

    EmployeeProfile::factory()
        ->commissioned([
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.03, 'active' => true],
        ])
        ->create(['user_id' => $creator->id]);

    $deal = Deal::factory()->create(['assigned_to' => $assignee->id]);
    $estimate = Estimate::factory()->create([
        'deal_id' => $deal->id,
        'created_by' => $creator->id,
        'total' => 10000,
    ]);

    $entries = $this->service->createFromEstimate($estimate);

    // Assignee 5% ($500) + creator 3% ($300)
    expect($entries)->toHaveCount(2);
    $amounts = collect($entries)->pluck('amount')->map(fn ($a) => (float) $a)->sort()->values()->all();
    expect($amounts)->toBe([300.00, 500.00]);
});
