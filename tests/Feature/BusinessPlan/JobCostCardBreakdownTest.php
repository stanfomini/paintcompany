<?php

use App\Models\Account;
use App\Models\CompanyAllocation;
use App\Models\CompanySetting;
use App\Models\JobCostCard;
use App\Models\JobCostCardAllocation;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\JobCostCardService;
use Database\Seeders\ChartOfAccountsSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'business_plan.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'business_plan.manage', 'guard_name' => 'web']);
    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());

    CompanySetting::query()->delete();
    CompanySetting::clearInstanceCache();

    $this->settings = CompanySetting::create([
        'company_name' => 'Test Painting Co',
        'interior_hourly_wage' => 25.00,
        'exterior_hourly_wage' => 27.00,
        'payroll_burden_multiplier' => 1.35,
        'interior_margin_percent' => 50.00,
        'exterior_margin_percent' => 41.00,
        'interior_cleanup_ratio' => 6.67,
        'exterior_cleanup_ratio' => 5.00,
        'interior_sundries_percent' => 1.50,
        'exterior_sundries_percent' => 2.00,
        'material_tax_rate' => 7.5,
        'labor_tax_rate' => 0,
        'marketing_percent' => 5,
        'sales_percent' => 10,
        'production_management_percent' => 5,
    ]);

    CompanySetting::clearInstanceCache();

    $this->user = User::factory()->internal()->withFullAccess()->create();
});

// ─── Model: Granular Actuals ───

it('computes actual_cogs from all granular fields', function () {
    $card = JobCostCard::factory()->create([
        'actual_labor_cost' => 500,
        'actual_material_cost' => 200,
        'actual_cleanup_cost' => 75,
        'actual_sundries_cost' => 25,
        'actual_material_tax' => 16,
        'actual_labor_tax' => 10,
        'actual_addon_cost' => 30,
        'actual_items_vendor_cost' => 0,
        'actual_warranty_reserve' => 0,
        'actual_subcontractor_cost' => 100,
        'actual_other_cost' => 50,
    ]);

    expect($card->actual_cogs)->toBe(1006.00);
});

it('computes actual_gross_profit from price minus actual cogs', function () {
    $card = JobCostCard::factory()->create([
        'price' => 5000,
        'actual_labor_cost' => 500,
        'actual_material_cost' => 300,
        'actual_cleanup_cost' => 0,
        'actual_sundries_cost' => 0,
        'actual_material_tax' => 0,
        'actual_labor_tax' => 0,
        'actual_addon_cost' => 0,
        'actual_items_vendor_cost' => 0,
        'actual_warranty_reserve' => 0,
        'actual_subcontractor_cost' => 0,
        'actual_other_cost' => 0,
    ]);

    expect($card->actual_gross_profit)->toBe(4200.00);
});

it('computes actual_net_profit subtracting allocation total', function () {
    $card = JobCostCard::factory()->create([
        'price' => 5000,
        'actual_labor_cost' => 500,
        'actual_material_cost' => 300,
        'actual_cleanup_cost' => 0,
        'actual_sundries_cost' => 0,
        'actual_material_tax' => 0,
        'actual_labor_tax' => 0,
        'actual_addon_cost' => 0,
        'actual_items_vendor_cost' => 0,
        'actual_warranty_reserve' => 0,
        'actual_subcontractor_cost' => 0,
        'actual_other_cost' => 0,
        'actual_allocation_total' => 700,
    ]);

    expect($card->actual_net_profit)->toBe(3500.00);
});

it('has_actuals returns true for granular fields', function () {
    $card = JobCostCard::factory()->create([
        'actual_labor_cost' => null,
        'actual_material_cost' => null,
        'actual_subcontractor_cost' => null,
        'actual_other_cost' => null,
        'actual_cleanup_cost' => 100,
    ]);

    expect($card->has_actuals)->toBeTrue();
});

// ─── Model: Allocation Variance ───

it('allocation variance is positive when under budget', function () {
    $card = JobCostCard::factory()->create();
    $alloc = JobCostCardAllocation::factory()->forJobCostCard($card)->create([
        'amount' => 500,
        'actual_amount' => 450,
    ]);

    expect($alloc->variance)->toBe(50.00);
});

it('allocation variance is negative when over budget', function () {
    $card = JobCostCard::factory()->create();
    $alloc = JobCostCardAllocation::factory()->forJobCostCard($card)->create([
        'amount' => 500,
        'actual_amount' => 600,
    ]);

    expect($alloc->variance)->toBe(-100.00);
});

it('allocation variance is null when no actual entered', function () {
    $card = JobCostCard::factory()->create();
    $alloc = JobCostCardAllocation::factory()->forJobCostCard($card)->create([
        'amount' => 500,
        'actual_amount' => null,
    ]);

    expect($alloc->variance)->toBeNull();
});

// ─── Service: Granular updateActuals ───

it('service updateActuals saves all granular fields and recomputes total', function () {
    $card = JobCostCard::factory()->create();
    $service = new JobCostCardService;

    $service->updateActuals($card, [
        'actual_labor_cost' => 1000,
        'actual_material_cost' => 400,
        'actual_cleanup_cost' => 150,
        'actual_sundries_cost' => 50,
        'actual_material_tax' => 32,
        'actual_labor_tax' => 0,
        'actual_addon_cost' => 0,
        'actual_items_vendor_cost' => 0,
        'actual_warranty_reserve' => 0,
        'actual_subcontractor_cost' => 200,
        'actual_other_cost' => 0,
        'actual_crew_bonus' => 100,
        'actual_notes' => 'Test notes',
    ]);

    $card->refresh();
    expect((float) $card->actual_labor_cost)->toBe(1000.00)
        ->and((float) $card->actual_material_cost)->toBe(400.00)
        ->and((float) $card->actual_cleanup_cost)->toBe(150.00)
        ->and((float) $card->actual_sundries_cost)->toBe(50.00)
        ->and((float) $card->actual_material_tax)->toBe(32.00)
        ->and((float) $card->actual_subcontractor_cost)->toBe(200.00)
        ->and((float) $card->actual_crew_bonus)->toBe(100.00)
        ->and($card->actual_notes)->toBe('Test notes')
        ->and((float) $card->actual_total_cost)->toBe(1832.00); // sum of all COGS lines
});

// ─── Service: Allocation Actuals ───

it('service updateAllocationActuals saves per-allocation amounts and totals', function () {
    $card = JobCostCard::factory()->create();

    $companyAlloc1 = CompanyAllocation::factory()->create(['name' => 'Marketing', 'slug' => 'marketing']);
    $companyAlloc2 = CompanyAllocation::factory()->create(['name' => 'Sales', 'slug' => 'sales']);

    $alloc1 = JobCostCardAllocation::factory()->forJobCostCard($card)->forCompanyAllocation($companyAlloc1)->create(['amount' => 250]);
    $alloc2 = JobCostCardAllocation::factory()->forJobCostCard($card)->forCompanyAllocation($companyAlloc2)->create(['amount' => 350]);

    $service = new JobCostCardService;
    $service->updateAllocationActuals($card, [
        $alloc1->id => 240,
        $alloc2->id => 360,
    ]);

    $alloc1->refresh();
    $alloc2->refresh();
    $card->refresh();

    expect((float) $alloc1->actual_amount)->toBe(240.00)
        ->and((float) $alloc2->actual_amount)->toBe(360.00)
        ->and((float) $card->actual_allocation_total)->toBe(600.00);
});

// ─── Service: Journal Entry Creation ───

it('service creates draft journal entry from actuals', function () {
    // Seed chart of accounts
    (new ChartOfAccountsSeeder)->run();

    $card = JobCostCard::factory()->create([
        'price' => 5000,
        'scope_type' => 'interior',
        'actual_labor_cost' => 1000,
        'actual_material_cost' => 400,
        'actual_cleanup_cost' => 150,
        'actual_sundries_cost' => 50,
        'actual_material_tax' => 32,
        'actual_labor_tax' => 0,
        'actual_subcontractor_cost' => 200,
    ]);

    $service = new JobCostCardService;
    $entry = $service->createJournalEntryFromActuals($card, $this->user->id);

    expect($entry)->toBeInstanceOf(JournalEntry::class)
        ->and($entry->status)->toBe(JournalEntry::STATUS_DRAFT)
        ->and($entry->reference_type)->toBe('job_cost_card')
        ->and($entry->reference_id)->toBe($card->id);

    $card->refresh();
    expect($card->journal_entry_id)->toBe($entry->id);

    // Check lines exist
    $lines = $entry->lines;
    expect($lines->count())->toBeGreaterThanOrEqual(2);

    // Check revenue credit (4010 Interior)
    $revenueLine = $lines->first(fn ($l) => (float) $l->credit_amount > 0);
    expect($revenueLine)->not->toBeNull();
    expect((float) $revenueLine->credit_amount)->toBe(5000.00);

    // Check labor debit (5010)
    $laborAccount = Account::where('code', '5010')
        ->where('company_setting_id', $this->settings->id)
        ->first();
    $laborLine = $lines->first(fn ($l) => $l->account_id === $laborAccount->id);
    expect($laborLine)->not->toBeNull();
    expect((float) $laborLine->debit_amount)->toBe(1000.00);
});

it('journal entry is fully balanced with debits equaling credits', function () {
    (new ChartOfAccountsSeeder)->run();

    $this->settings->update([
        'tax_reserve_percent' => 25.00,
        'profit_reserve_percent' => 10.00,
    ]);
    CompanySetting::clearInstanceCache();

    $card = JobCostCard::factory()->create([
        'price' => 5000,
        'scope_type' => 'interior',
        'actual_labor_cost' => 1000,
        'actual_material_cost' => 400,
        'actual_cleanup_cost' => 150,
        'actual_sundries_cost' => 50,
        'actual_material_tax' => 32,
        'actual_labor_tax' => 0,
        'actual_subcontractor_cost' => 200,
    ]);

    $service = new JobCostCardService;
    $entry = $service->createJournalEntryFromActuals($card, $this->user->id);

    $lines = $entry->lines;
    $totalDebits = $lines->sum('debit_amount');
    $totalCredits = $lines->sum('credit_amount');

    // Entry must balance: debits = credits
    expect(round((float) $totalDebits, 2))->toBe(round((float) $totalCredits, 2));

    // Revenue credit = full price
    expect(round((float) $totalCredits, 2))->toBe(5000.00);

    // COGS total: 1000 + 400 + 150 + 50 + 200 = 1800
    // Sales tax: 32
    // Total expenses: 1832
    // Net profit: 5000 - 1832 = 3168
    // Tax reserve: 3168 * 25% = 792
    // Profit reserve: 3168 * 10% = 316.80
    // Retained earnings: 3168 - 792 - 316.80 = 2059.20

    $taxAccount = Account::where('code', '2800')
        ->where('company_setting_id', $this->settings->id)
        ->first();
    $taxLine = $lines->first(fn ($l) => $l->account_id === $taxAccount->id);
    expect($taxLine)->not->toBeNull();
    expect((float) $taxLine->debit_amount)->toBe(792.00);

    $profitAccount = Account::where('code', '3300')
        ->where('company_setting_id', $this->settings->id)
        ->first();
    $profitLine = $lines->first(fn ($l) => $l->account_id === $profitAccount->id);
    expect($profitLine)->not->toBeNull();
    expect((float) $profitLine->debit_amount)->toBe(316.80);

    $retainedAccount = Account::where('code', '3200')
        ->where('company_setting_id', $this->settings->id)
        ->first();
    $retainedLine = $lines->first(fn ($l) => $l->account_id === $retainedAccount->id);
    expect($retainedLine)->not->toBeNull();
    expect((float) $retainedLine->debit_amount)->toBe(2059.20);
});

it('journal entry is not duplicated if already linked', function () {
    (new ChartOfAccountsSeeder)->run();

    $card = JobCostCard::factory()->withGranularActuals()->create([
        'price' => 5000,
        'scope_type' => 'interior',
    ]);

    $service = new JobCostCardService;
    $entry = $service->createJournalEntryFromActuals($card, $this->user->id);

    // Try again via the component postToJournal (should show message, not create new)
    $card->refresh();
    expect($card->journal_entry_id)->toBe($entry->id);
});

// ─── Service: Bank Distribution ───

it('service calculates bank distribution from actuals', function () {
    $card = JobCostCard::factory()->create([
        'price' => 5000,
        'actual_labor_cost' => 1000,
        'actual_material_cost' => 400,
        'actual_cleanup_cost' => 100,
        'actual_sundries_cost' => 50,
        'actual_material_tax' => 32,
        'actual_labor_tax' => 0,
        'actual_addon_cost' => 0,
        'actual_items_vendor_cost' => 0,
        'actual_warranty_reserve' => 0,
        'actual_subcontractor_cost' => 0,
        'actual_other_cost' => 0,
        'actual_allocation_total' => 600,
    ]);

    $service = new JobCostCardService;
    $distribution = $service->calculateBankDistribution($card);

    expect($distribution)->toBeArray()
        ->and($distribution)->toHaveKeys(['cogs_account', 'tax_account', 'profit_account'])
        ->and($distribution['cogs_account'])->toBe(1582.00)  // sum of all actuals
        ->and($distribution['tax_account'])->toBe(32.00)     // material + labor tax
        ->and($distribution['profit_account'])->toBe(2818.00); // price - cogs - allocations
});

// ─── Service: Running Totals include actuals ───

it('running totals include actual aggregates', function () {
    $card = JobCostCard::factory()->withGranularActuals()->create([
        'year' => now()->year,
    ]);

    $service = new JobCostCardService;
    $totals = $service->getRunningTotals(now()->year);

    expect($totals['cards_with_actuals'])->toBe(1)
        ->and($totals['actual_total_cost'])->toBeGreaterThan(0);
});

// ─── Component: Expandable Detail ───

it('detail component renders fallback for manual cards without estimates', function () {
    $card = JobCostCard::factory()->create([
        'price' => 8000,
        'labor_cost' => 2000,
        'cleanup_cost' => 300,
        'sundries_cost' => 100,
        'material_cost' => 800,
        'material_tax' => 60,
        'total_cogs' => 3260,
        'labor_hours' => 20,
        'cleanup_hours' => 3,
    ]);

    Livewire::actingAs($this->user)
        ->test('business-plan.job-cost-card-detail', ['card' => $card])
        ->assertStatus(200)
        ->assertSee('No estimate linked');
});

it('detail component saves actuals via saveActuals method', function () {
    $card = JobCostCard::factory()->create([
        'price' => 5000,
        'labor_cost' => 1000,
        'material_cost' => 400,
        'total_cogs' => 1400,
    ]);

    Livewire::actingAs($this->user)
        ->test('business-plan.job-cost-card-detail', ['card' => $card])
        ->set('actualLaborCost', '950')
        ->set('actualMaterialCost', '420')
        ->call('saveActuals')
        ->assertSee('Actuals saved.');

    $card->refresh();
    expect((float) $card->actual_labor_cost)->toBe(950.00)
        ->and((float) $card->actual_material_cost)->toBe(420.00);
});

it('detail component shows fallback message for cards without estimate', function () {
    $card = JobCostCard::factory()->create([
        'price' => 8000,
        'total_cogs' => 3000,
        'allocation_total' => 1000,
    ]);

    // Manual cards without estimate show fallback message
    Livewire::actingAs($this->user)
        ->test('business-plan.job-cost-card-detail', ['card' => $card])
        ->assertSee('No estimate linked');
});

it('detail component shows save and journal buttons for estimate-linked cards', function () {
    // Create a real estimate with area so the breakdown service can work
    $estimate = \App\Models\Estimate::factory()
        ->has(\App\Models\EstimateArea::factory()->state(['scope_type' => 'interior', 'name' => 'Living Room']), 'areas')
        ->create(['project_type' => 'interior']);

    $job = \App\Models\Job::factory()->create(['estimate_id' => $estimate->id]);

    $card = JobCostCard::factory()->forJob($job)->create([
        'price' => 5000,
        'total_cogs' => 2000,
        'estimate_id' => $estimate->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('business-plan.job-cost-card-detail', ['card' => $card])
        ->assertSee('Save Actuals')
        ->assertSee('Post to Journal')
        ->assertSee('Direct Costs (COGS)')
        ->assertSee('Gross Profit')
        ->assertSee('Net Profit');
});

it('detail component shows journal badge when entry already linked', function () {
    (new ChartOfAccountsSeeder)->run();

    // Create an estimate-linked card so the breakdown renders
    $estimate = \App\Models\Estimate::factory()
        ->has(\App\Models\EstimateArea::factory()->state(['scope_type' => 'interior', 'name' => 'Bedroom']), 'areas')
        ->create(['project_type' => 'interior']);

    $job = \App\Models\Job::factory()->create(['estimate_id' => $estimate->id]);

    $card = JobCostCard::factory()->forJob($job)->withGranularActuals()->create([
        'price' => 5000,
        'scope_type' => 'interior',
        'estimate_id' => $estimate->id,
    ]);

    $service = new JobCostCardService;
    $entry = $service->createJournalEntryFromActuals($card, $this->user->id);

    $card->refresh();

    Livewire::actingAs($this->user)
        ->test('business-plan.job-cost-card-detail', ['card' => $card])
        ->assertSee('Journal Entry')
        ->assertDontSee('Post to Journal');
});

// ─── Parent Component: Expandable Row ───

it('parent component has expand button per row', function () {
    JobCostCard::factory()->create(['year' => now()->year]);

    Livewire::actingAs($this->user)
        ->test('business-plan.job-cost-cards')
        ->assertStatus(200)
        ->assertSeeHtml('wire:click="toggleExpand');
});

it('parent component toggles expanded card', function () {
    $card = JobCostCard::factory()->create(['year' => now()->year]);

    $component = Livewire::actingAs($this->user)
        ->test('business-plan.job-cost-cards')
        ->assertSet('expandedCardId', null)
        ->call('toggleExpand', $card->id)
        ->assertSet('expandedCardId', $card->id);

    // Toggle again to collapse
    $component->call('toggleExpand', $card->id)
        ->assertSet('expandedCardId', null);
});

it('parent component running totals show actual data when present', function () {
    JobCostCard::factory()->withGranularActuals()->create([
        'year' => now()->year,
        'price' => 5000,
    ]);

    Livewire::actingAs($this->user)
        ->test('business-plan.job-cost-cards')
        ->assertSee('Actual COGS')
        ->assertSee('COGS Variance')
        ->assertSee('Cards w/ Actuals');
});

// ─── Factory: withGranularActuals ───

it('factory withGranularActuals creates valid data', function () {
    $card = JobCostCard::factory()->withGranularActuals()->create();

    expect($card->has_actuals)->toBeTrue()
        ->and($card->actual_labor_cost)->not->toBeNull()
        ->and($card->actual_material_cost)->not->toBeNull()
        ->and($card->actual_cleanup_cost)->not->toBeNull()
        ->and($card->actual_total_cost)->not->toBeNull();
});

// ─── Component: Use Projected Values as Actuals ───

it('shows confirmation prompt when posting to journal without actuals', function () {
    $estimate = \App\Models\Estimate::factory()
        ->has(\App\Models\EstimateArea::factory()->state(['scope_type' => 'interior', 'name' => 'Test Room']), 'areas')
        ->create(['project_type' => 'interior']);

    $job = \App\Models\Job::factory()->create(['estimate_id' => $estimate->id]);

    $card = JobCostCard::factory()->forJob($job)->create([
        'price' => 5000,
        'labor_cost' => 1000,
        'material_cost' => 400,
        'cleanup_cost' => 150,
        'sundries_cost' => 50,
        'total_cogs' => 1600,
        'estimate_id' => $estimate->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('business-plan.job-cost-card-detail', ['card' => $card])
        ->call('postToJournal')
        ->assertSet('showProjectedConfirm', true)
        ->assertSee('No actuals were entered')
        ->assertSee('Use projected values as actuals');
});

it('copies projected values to actuals and posts journal when confirmed', function () {
    (new ChartOfAccountsSeeder)->run();

    $estimate = \App\Models\Estimate::factory()
        ->has(\App\Models\EstimateArea::factory()->state(['scope_type' => 'interior', 'name' => 'Test Room']), 'areas')
        ->create(['project_type' => 'interior']);

    $job = \App\Models\Job::factory()->create(['estimate_id' => $estimate->id]);

    $card = JobCostCard::factory()->forJob($job)->create([
        'price' => 5000,
        'scope_type' => 'interior',
        'labor_cost' => 1000,
        'material_cost' => 400,
        'cleanup_cost' => 150,
        'sundries_cost' => 50,
        'material_tax' => 30,
        'labor_tax' => 0,
        'total_cogs' => 1630,
        'estimate_id' => $estimate->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('business-plan.job-cost-card-detail', ['card' => $card])
        ->call('postToJournal')
        ->assertSet('showProjectedConfirm', true)
        ->call('confirmUseProjected')
        ->assertSet('showProjectedConfirm', false)
        ->assertSee('Draft journal entry');

    $card->refresh();
    expect($card->journal_entry_id)->not->toBeNull()
        ->and((float) $card->actual_labor_cost)->toBe(1000.00)
        ->and((float) $card->actual_material_cost)->toBe(400.00)
        ->and((float) $card->actual_cleanup_cost)->toBe(150.00)
        ->and((float) $card->actual_sundries_cost)->toBe(50.00)
        ->and((float) $card->actual_material_tax)->toBe(30.00);
});

it('can cancel the projected values confirmation', function () {
    $estimate = \App\Models\Estimate::factory()
        ->has(\App\Models\EstimateArea::factory()->state(['scope_type' => 'interior', 'name' => 'Test Room']), 'areas')
        ->create(['project_type' => 'interior']);

    $job = \App\Models\Job::factory()->create(['estimate_id' => $estimate->id]);

    $card = JobCostCard::factory()->forJob($job)->create([
        'price' => 5000,
        'labor_cost' => 1000,
        'total_cogs' => 1000,
        'estimate_id' => $estimate->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('business-plan.job-cost-card-detail', ['card' => $card])
        ->call('postToJournal')
        ->assertSet('showProjectedConfirm', true)
        ->call('cancelProjectedConfirm')
        ->assertSet('showProjectedConfirm', false)
        ->assertDontSee('No actuals were entered');

    $card->refresh();
    expect($card->actual_labor_cost)->toBeNull()
        ->and($card->journal_entry_id)->toBeNull();
});

it('posts directly to journal when actuals already exist', function () {
    (new ChartOfAccountsSeeder)->run();

    $estimate = \App\Models\Estimate::factory()
        ->has(\App\Models\EstimateArea::factory()->state(['scope_type' => 'interior', 'name' => 'Test Room']), 'areas')
        ->create(['project_type' => 'interior']);

    $job = \App\Models\Job::factory()->create(['estimate_id' => $estimate->id]);

    $card = JobCostCard::factory()->forJob($job)->withGranularActuals()->create([
        'price' => 5000,
        'scope_type' => 'interior',
        'estimate_id' => $estimate->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('business-plan.job-cost-card-detail', ['card' => $card])
        ->call('postToJournal')
        ->assertSet('showProjectedConfirm', false)
        ->assertSee('Draft journal entry');

    $card->refresh();
    expect($card->journal_entry_id)->not->toBeNull();
});
