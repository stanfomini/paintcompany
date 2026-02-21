# Territory Hierarchy Session 2: Commission Pipeline & Financial Tracking

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add door-knock commission triggers, territory override commissions up the hierarchy chain, territory P&L tracking, budget management, and deal attribution — so every knock that converts to a job pays the right people in the chain.

**Architecture:** Extend existing `CommissionService` with `createFromDoorKnock()` and hierarchy-walk override logic. Add `origin_door_knock_id` to deals for attribution. New `TerritoryBudget` model and `TerritoryPnlService` for financial tracking.

**Tech Stack:** Laravel 12, existing CommissionService + EmployeeProfile commission_rules, Pest 4

**Design Doc:** `docs/plans/2026-02-21-territory-hierarchy-design.md`

**Pre-requisites:** Session 1 complete (territory hierarchy columns, team members, permission scopes, door_knocker role).

**What Session 3 needs from this:** `createFromDoorKnock()`, territory P&L calculations, budget tracking, deal attribution.

---

### Task 1: Migration — Add origin_door_knock_id to deals

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_origin_door_knock_id_to_deals.php`
- Modify: `app/Models/Deal.php` (add relationship + fillable)
- Test: `tests/Feature/Sales/DoorKnockCommissionTest.php`

**Step 1: Create the migration**

```bash
php artisan make:migration add_origin_door_knock_id_to_deals --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('origin_door_knock_id')->nullable()->after('lead_source')
                ->constrained('door_knocks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('origin_door_knock_id');
        });
    }
};
```

**Step 2: Run the migration**

```bash
php artisan migrate
```

**Step 3: Update Deal model**

In `app/Models/Deal.php`, add to `$fillable`:

```php
'origin_door_knock_id',
```

Add relationship:

```php
public function originDoorKnock(): BelongsTo
{
    return $this->belongsTo(DoorKnock::class, 'origin_door_knock_id');
}
```

**Step 4: Update DoorKnockService::convertToLead()**

In `app/Services/DoorKnockService.php`, find where the deal is created in `convertToLead()` and add the attribution:

```php
// In the Deal::create() call, add:
'origin_door_knock_id' => $knock->id,
```

**Step 5: Write the test**

Create `tests/Feature/Sales/DoorKnockCommissionTest.php`:

```php
<?php

use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\DoorKnockService;

it('sets origin_door_knock_id when converting knock to lead', function () {
    $user = User::factory()->internal()->create();
    $this->actingAs($user);

    $knock = DoorKnock::factory()->appointmentSet()->forUser($user)->create([
        'territory_id' => SalesTerritory::factory(),
    ]);

    $service = app(DoorKnockService::class);
    $deal = $service->convertToLead($knock);

    expect($deal->origin_door_knock_id)->toBe($knock->id);
    expect($deal->originDoorKnock->id)->toBe($knock->id);
});
```

**Step 6: Run the test**

```bash
php artisan test --compact tests/Feature/Sales/DoorKnockCommissionTest.php
```

Expected: Pass.

**Step 7: Commit**

```bash
git add database/migrations/*origin_door_knock* app/Models/Deal.php app/Services/DoorKnockService.php tests/Feature/Sales/DoorKnockCommissionTest.php
git commit -m "feat: add origin_door_knock_id to deals for commission attribution"
```

---

### Task 2: CommissionService::createFromDoorKnock()

**Files:**
- Modify: `app/Services/CommissionService.php`
- Modify: `database/factories/EmployeeProfileFactory.php` (add door_knock commission state)
- Test: `tests/Feature/Sales/DoorKnockCommissionTest.php`

**Step 1: Add factory state for door knock commissions**

In `database/factories/EmployeeProfileFactory.php`, add:

```php
public function withDoorKnockCommission(string $type = 'flat', float $amount = 25): static
{
    return $this->state(function (array $attributes) use ($type, $amount) {
        $rules = $attributes['commission_rules'] ?? [];
        $rules[] = [
            'source' => 'door_knock',
            'type' => $type,
            'amount' => $type === 'flat' ? $amount : 0,
            'rate' => $type !== 'flat' ? $amount : 0,
            'active' => true,
            'auto_trigger' => true,
        ];

        return ['commission_rules' => $rules];
    });
}

public function withTerritoryOverrideCommission(float $rate = 0.02): static
{
    return $this->state(function (array $attributes) use ($rate) {
        $rules = $attributes['commission_rules'] ?? [];
        $rules[] = [
            'source' => 'territory_override',
            'type' => 'revenue_percentage',
            'rate' => $rate,
            'active' => true,
            'auto_trigger' => true,
        ];

        return ['commission_rules' => $rules];
    });
}
```

**Step 2: Write the failing tests**

Append to `tests/Feature/Sales/DoorKnockCommissionTest.php`:

```php
use App\Models\CommissionEntry;
use App\Models\EmployeeProfile;
use App\Services\CommissionService;

it('creates flat commission when knock status is appointment_set', function () {
    $knocker = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->for($knocker, 'user')
        ->withDoorKnockCommission('flat', 25)
        ->create();

    $knock = DoorKnock::factory()->appointmentSet()->forUser($knocker)->create([
        'territory_id' => SalesTerritory::factory(),
    ]);

    $service = app(CommissionService::class);
    $entries = $service->createFromDoorKnock($knock);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->amount)->toBe(25.0);
    expect($entries[0]->commission_type)->toBe('flat');
    expect($entries[0]->status)->toBe('pending');
    expect($entries[0]->source_type)->toBe(DoorKnock::class);
    expect($entries[0]->source_id)->toBe($knock->id);
});

it('creates percentage commission for door knock', function () {
    $knocker = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->for($knocker, 'user')
        ->withDoorKnockCommission('revenue_percentage', 0.04)
        ->create();

    $knock = DoorKnock::factory()->appointmentSet()->forUser($knocker)->create([
        'territory_id' => SalesTerritory::factory(),
    ]);

    // Create a deal linked to this knock with estimated value
    $this->actingAs($knocker);
    $deal = app(DoorKnockService::class)->convertToLead($knock);
    $deal->update(['estimated_value' => 5000]);
    $knock->refresh();

    $service = app(CommissionService::class);
    $entries = $service->createFromDoorKnock($knock);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->basis_amount)->toBe(5000.0);
    expect($entries[0]->amount)->toBe(200.0); // 4% of 5000
});

it('skips commission when knocker has no door_knock rules', function () {
    $knocker = User::factory()->internal()->create();
    EmployeeProfile::factory()->for($knocker, 'user')->create(); // no commission rules

    $knock = DoorKnock::factory()->appointmentSet()->forUser($knocker)->create();

    $service = app(CommissionService::class);
    $entries = $service->createFromDoorKnock($knock);

    expect($entries)->toBeEmpty();
});

it('skips commission for non-qualifying knock statuses', function () {
    $knocker = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->for($knocker, 'user')
        ->withDoorKnockCommission('flat', 25)
        ->create();

    $knock = DoorKnock::factory()->notHome()->forUser($knocker)->create();

    $service = app(CommissionService::class);
    $entries = $service->createFromDoorKnock($knock);

    expect($entries)->toBeEmpty();
});

it('prevents duplicate door knock commissions', function () {
    $knocker = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->for($knocker, 'user')
        ->withDoorKnockCommission('flat', 25)
        ->create();

    $knock = DoorKnock::factory()->appointmentSet()->forUser($knocker)->create();

    $service = app(CommissionService::class);
    $service->createFromDoorKnock($knock);
    $entries = $service->createFromDoorKnock($knock); // second call

    expect($entries)->toBeEmpty();
    expect(CommissionEntry::where('source_type', DoorKnock::class)->where('source_id', $knock->id)->count())->toBe(1);
});
```

**Step 3: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Sales/DoorKnockCommissionTest.php
```

Expected: Failures for missing `createFromDoorKnock`.

**Step 4: Implement createFromDoorKnock**

In `app/Services/CommissionService.php`, add after the existing `createFromJob()` method:

```php
/**
 * Create commission entries from a door knock (appointment set or sold).
 */
public function createFromDoorKnock(DoorKnock $knock): array
{
    // Only qualifying statuses trigger commissions
    if (! in_array($knock->status, ['appointment_set', 'sold'])) {
        return [];
    }

    // Prevent duplicates
    if ($this->hasDuplicateCommission(DoorKnock::class, $knock->id)) {
        return [];
    }

    $employee = EmployeeProfile::where('user_id', $knock->user_id)->first();
    if (! $employee) {
        return [];
    }

    // Get revenue from linked deal if available
    $revenue = 0;
    if ($knock->deal_id) {
        $revenue = $knock->deal?->estimated_value ?? 0;
    }

    $sourceLabel = 'Door Knock: ' . ($knock->address ?: "#{$knock->id}");

    return $this->applyRulesForSource($employee, 'door_knock', $knock, $revenue, 0, autoOnly: true);
}
```

Add the `use App\Models\DoorKnock;` import at the top if not already present.

**Step 5: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/DoorKnockCommissionTest.php
```

Expected: All tests pass.

**Step 6: Commit**

```bash
git add app/Services/CommissionService.php database/factories/EmployeeProfileFactory.php tests/Feature/Sales/DoorKnockCommissionTest.php
git commit -m "feat: add CommissionService::createFromDoorKnock() for knocker commissions"
```

---

### Task 3: Territory Override Commissions (Hierarchy Walk)

**Files:**
- Modify: `app/Services/CommissionService.php`
- Test: `tests/Feature/Sales/DoorKnockCommissionTest.php`

**Step 1: Write the failing tests**

Append to `tests/Feature/Sales/DoorKnockCommissionTest.php`:

```php
it('creates territory override commission for region manager when job completes', function () {
    // Setup hierarchy: region (manager) > zone (estimator) > block (knocker)
    $regionManager = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->for($regionManager, 'user')
        ->withTerritoryOverrideCommission(0.02) // 2% override
        ->create();

    $estimator = User::factory()->internal()->create();
    $knocker = User::factory()->internal()->create();

    $region = SalesTerritory::factory()->region()->assignedTo($regionManager)->managedBy($regionManager)->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->assignedTo($estimator)->create();
    $block = SalesTerritory::factory()->block()->childOf($zone)->assignedTo($knocker)->create();

    // Create a knock that becomes a deal
    $knock = DoorKnock::factory()->appointmentSet()->forUser($knocker)->create([
        'territory_id' => $block->id,
    ]);

    $this->actingAs($knocker);
    $deal = app(DoorKnockService::class)->convertToLead($knock);
    $deal->update(['estimated_value' => 10000, 'actual_value' => 10000]);

    // Create estimate and job (simulate job completion)
    $estimate = \App\Models\Estimate::factory()->for($deal)->create([
        'total' => 10000,
        'assigned_to' => $estimator->id,
    ]);
    $job = \App\Models\Job::factory()->for($deal)->create([
        'estimate_id' => $estimate->id,
        'status' => 'completed',
    ]);

    $service = app(CommissionService::class);
    $entries = $service->createTerritoryOverrideCommissions($job);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->employee_profile_id)->toBe($regionManager->employeeProfile->id);
    expect($entries[0]->amount)->toBe(200.0); // 2% of $10,000
    expect($entries[0]->commission_type)->toBe('revenue_percentage');
});

it('skips override commission when job has no door knock origin', function () {
    $regionManager = User::factory()->internal()->create();
    EmployeeProfile::factory()
        ->for($regionManager, 'user')
        ->withTerritoryOverrideCommission(0.02)
        ->create();

    $deal = Deal::factory()->create(['origin_door_knock_id' => null]);
    $estimate = \App\Models\Estimate::factory()->for($deal)->create(['total' => 10000]);
    $job = \App\Models\Job::factory()->for($deal)->create([
        'estimate_id' => $estimate->id,
        'status' => 'completed',
    ]);

    $service = app(CommissionService::class);
    $entries = $service->createTerritoryOverrideCommissions($job);

    expect($entries)->toBeEmpty();
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Sales/DoorKnockCommissionTest.php --filter="territory override"
```

**Step 3: Implement createTerritoryOverrideCommissions**

In `app/Services/CommissionService.php`:

```php
/**
 * Create territory override commissions by walking up the hierarchy.
 * Called when a job completes that originated from a door knock.
 */
public function createTerritoryOverrideCommissions(Job $job): array
{
    $deal = $job->deal;
    if (! $deal || ! $deal->origin_door_knock_id) {
        return [];
    }

    $knock = DoorKnock::find($deal->origin_door_knock_id);
    if (! $knock || ! $knock->territory_id) {
        return [];
    }

    $territory = SalesTerritory::with('parent.parent')->find($knock->territory_id);
    if (! $territory) {
        return [];
    }

    $revenue = $job->estimate?->total ?? $deal->actual_value ?? $deal->estimated_value ?? 0;
    $entries = [];

    // Walk up the hierarchy and apply override commissions
    $ancestors = $territory->getAncestors();
    foreach ($ancestors as $ancestor) {
        $managerId = $ancestor->manager_id ?? $ancestor->assigned_user_id;
        if (! $managerId) {
            continue;
        }

        $employee = EmployeeProfile::where('user_id', $managerId)->first();
        if (! $employee) {
            continue;
        }

        // Prevent duplicates per manager per job
        $existing = CommissionEntry::where('employee_profile_id', $employee->id)
            ->where('source_type', Job::class)
            ->where('source_id', $job->id)
            ->where('commission_type', '!=', 'reversed')
            ->exists();

        if ($existing) {
            continue;
        }

        $overrideEntries = $this->applyRulesForSource(
            $employee, 'territory_override', $job, $revenue, 0, autoOnly: true
        );

        $entries = array_merge($entries, $overrideEntries);
    }

    return $entries;
}
```

Add required imports: `use App\Models\Job;`

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/DoorKnockCommissionTest.php
```

Expected: All tests pass. (Note: If Estimate/Job factories don't exist or have different signatures, adjust accordingly — check existing factory files.)

**Step 5: Commit**

```bash
git add app/Services/CommissionService.php tests/Feature/Sales/DoorKnockCommissionTest.php
git commit -m "feat: add territory override commissions walking up hierarchy chain"
```

---

### Task 4: TerritoryBudget Model & Migration

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_territory_budgets_table.php`
- Create: `app/Models/TerritoryBudget.php`
- Create: `database/factories/TerritoryBudgetFactory.php`
- Test: `tests/Feature/Sales/TerritoryBudgetTest.php`

**Step 1: Create model with migration and factory**

```bash
php artisan make:model TerritoryBudget -mf --no-interaction
```

**Step 2: Edit the migration**

```php
public function up(): void
{
    Schema::create('territory_budgets', function (Blueprint $table) {
        $table->id();
        $table->foreignId('territory_id')->constrained('sales_territories')->cascadeOnDelete();
        $table->string('period_type'); // monthly, quarterly, yearly
        $table->date('period_start');
        $table->decimal('marketing_budget', 12, 2)->default(0);
        $table->decimal('commission_budget_pct', 5, 2)->default(12.00);
        $table->text('notes')->nullable();
        $table->timestamps();

        $table->unique(['territory_id', 'period_type', 'period_start']);
    });
}
```

**Step 3: Edit the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerritoryBudget extends Model
{
    use HasFactory;

    protected $fillable = [
        'territory_id',
        'period_type',
        'period_start',
        'marketing_budget',
        'commission_budget_pct',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'marketing_budget' => 'decimal:2',
            'commission_budget_pct' => 'decimal:2',
        ];
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(SalesTerritory::class, 'territory_id');
    }
}
```

**Step 4: Edit the factory**

```php
<?php

namespace Database\Factories;

use App\Models\SalesTerritory;
use Illuminate\Database\Eloquent\Factories\Factory;

class TerritoryBudgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'territory_id' => SalesTerritory::factory(),
            'period_type' => $this->faker->randomElement(['monthly', 'quarterly', 'yearly']),
            'period_start' => now()->startOfMonth(),
            'marketing_budget' => $this->faker->randomFloat(2, 500, 5000),
            'commission_budget_pct' => 12.00,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn () => ['period_type' => 'monthly']);
    }

    public function quarterly(): static
    {
        return $this->state(fn () => ['period_type' => 'quarterly']);
    }

    public function yearly(): static
    {
        return $this->state(fn () => ['period_type' => 'yearly']);
    }
}
```

**Step 5: Add relationship to SalesTerritory**

In `app/Models/SalesTerritory.php`:

```php
public function budgets(): HasMany
{
    return $this->hasMany(TerritoryBudget::class, 'territory_id');
}
```

**Step 6: Run migration and write tests**

```bash
php artisan migrate
```

Create `tests/Feature/Sales/TerritoryBudgetTest.php`:

```php
<?php

use App\Models\SalesTerritory;
use App\Models\TerritoryBudget;

it('creates a territory budget', function () {
    $territory = SalesTerritory::factory()->region()->create();

    $budget = TerritoryBudget::factory()->monthly()->create([
        'territory_id' => $territory->id,
        'marketing_budget' => 2500,
        'commission_budget_pct' => 12,
    ]);

    expect($budget->territory->id)->toBe($territory->id);
    expect($budget->marketing_budget)->toBe('2500.00');
    expect($budget->commission_budget_pct)->toBe('12.00');
});

it('prevents duplicate budgets for same territory period', function () {
    $territory = SalesTerritory::factory()->create();

    TerritoryBudget::factory()->create([
        'territory_id' => $territory->id,
        'period_type' => 'monthly',
        'period_start' => '2026-02-01',
    ]);

    expect(fn () => TerritoryBudget::factory()->create([
        'territory_id' => $territory->id,
        'period_type' => 'monthly',
        'period_start' => '2026-02-01',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('gets budgets from territory', function () {
    $territory = SalesTerritory::factory()->create();
    TerritoryBudget::factory()->count(3)->create(['territory_id' => $territory->id]);

    expect($territory->budgets)->toHaveCount(3);
});
```

**Step 7: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryBudgetTest.php
```

**Step 8: Commit**

```bash
git add database/migrations/*territory_budgets* app/Models/TerritoryBudget.php database/factories/TerritoryBudgetFactory.php app/Models/SalesTerritory.php tests/Feature/Sales/TerritoryBudgetTest.php
git commit -m "feat: add TerritoryBudget model for budget tracking per territory"
```

---

### Task 5: TerritoryPnlService

**Files:**
- Create: `app/Services/TerritoryPnlService.php`
- Test: `tests/Feature/Sales/TerritoryPnlTest.php`

**Step 1: Write failing tests**

Create `tests/Feature/Sales/TerritoryPnlTest.php`:

```php
<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\EmployeeProfile;
use App\Models\SalesTerritory;
use App\Models\TerritoryBudget;
use App\Models\User;
use App\Services\TerritoryPnlService;
use Carbon\Carbon;

it('calculates P&L for a territory with revenue and commissions', function () {
    $territory = SalesTerritory::factory()->zone()->create();
    $knocker = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->for($knocker, 'user')->create();

    // Create completed deals in this territory
    $knock = DoorKnock::factory()->sold()->forUser($knocker)->create([
        'territory_id' => $territory->id,
    ]);
    $deal = Deal::factory()->create([
        'origin_door_knock_id' => $knock->id,
        'actual_value' => 8000,
        'stage' => 'completed',
    ]);
    $knock->update(['deal_id' => $deal->id]);

    // Create commission entries
    CommissionEntry::factory()->flat(50)->create([
        'employee_profile_id' => $employee->id,
        'source_type' => DoorKnock::class,
        'source_id' => $knock->id,
        'earned_date' => now(),
    ]);

    $service = app(TerritoryPnlService::class);
    $pnl = $service->calculatePnl($territory, now()->startOfMonth(), now()->endOfMonth());

    expect($pnl['revenue'])->toBe(8000.0);
    expect($pnl['commission_costs'])->toBe(50.0);
    expect($pnl['margin'])->toBe(7950.0);
});

it('calculates budget percentage against target', function () {
    $territory = SalesTerritory::factory()->zone()->create();
    TerritoryBudget::factory()->monthly()->create([
        'territory_id' => $territory->id,
        'commission_budget_pct' => 12,
        'period_start' => now()->startOfMonth(),
    ]);

    $knocker = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->for($knocker, 'user')->create();

    $knock = DoorKnock::factory()->sold()->forUser($knocker)->create(['territory_id' => $territory->id]);
    $deal = Deal::factory()->create([
        'origin_door_knock_id' => $knock->id,
        'actual_value' => 10000,
        'stage' => 'completed',
    ]);
    $knock->update(['deal_id' => $deal->id]);

    CommissionEntry::factory()->flat(800)->create([
        'employee_profile_id' => $employee->id,
        'source_type' => DoorKnock::class,
        'source_id' => $knock->id,
        'earned_date' => now(),
    ]);

    $service = app(TerritoryPnlService::class);
    $pnl = $service->calculatePnl($territory, now()->startOfMonth(), now()->endOfMonth());

    expect($pnl['budget_pct'])->toBe(8.0); // 800/10000 = 8%
    expect($pnl['budget_target_pct'])->toBe(12.0);
    expect($pnl['within_budget'])->toBeTrue();
});

it('rolls up P&L from children', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zone1 = SalesTerritory::factory()->zone()->childOf($region)->create();
    $zone2 = SalesTerritory::factory()->zone()->childOf($region)->create();

    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->for($user, 'user')->create();

    // Revenue in zone1
    $knock1 = DoorKnock::factory()->sold()->forUser($user)->create(['territory_id' => $zone1->id]);
    $deal1 = Deal::factory()->create(['origin_door_knock_id' => $knock1->id, 'actual_value' => 5000, 'stage' => 'completed']);
    $knock1->update(['deal_id' => $deal1->id]);

    // Revenue in zone2
    $knock2 = DoorKnock::factory()->sold()->forUser($user)->create(['territory_id' => $zone2->id]);
    $deal2 = Deal::factory()->create(['origin_door_knock_id' => $knock2->id, 'actual_value' => 7000, 'stage' => 'completed']);
    $knock2->update(['deal_id' => $deal2->id]);

    $service = app(TerritoryPnlService::class);
    $pnl = $service->calculatePnlRollup($region, now()->startOfMonth(), now()->endOfMonth());

    expect($pnl['revenue'])->toBe(12000.0);
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryPnlTest.php
```

**Step 3: Create the service**

Create `app/Services/TerritoryPnlService.php`:

```php
<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\SalesTerritory;
use App\Models\TerritoryBudget;
use Carbon\Carbon;

class TerritoryPnlService
{
    /**
     * Calculate P&L for a single territory (no rollup from children).
     */
    public function calculatePnl(SalesTerritory $territory, Carbon $start, Carbon $end): array
    {
        // Revenue: completed deals attributed to this territory
        $revenue = $this->getRevenue($territory, $start, $end);

        // Commission costs: all commission entries for knocks in this territory
        $commissionCosts = $this->getCommissionCosts($territory, $start, $end);

        // Marketing budget for this period
        $budget = TerritoryBudget::where('territory_id', $territory->id)
            ->where('period_start', '<=', $end)
            ->where('period_start', '>=', $start->copy()->startOfMonth())
            ->first();

        $marketingCost = (float) ($budget?->marketing_budget ?? 0);
        $budgetTargetPct = (float) ($budget?->commission_budget_pct ?? 12);

        $totalCosts = $commissionCosts + $marketingCost;
        $margin = $revenue - $totalCosts;
        $budgetPct = $revenue > 0 ? round(($commissionCosts / $revenue) * 100, 1) : 0;

        return [
            'territory_id' => $territory->id,
            'territory_name' => $territory->name,
            'level' => $territory->level,
            'revenue' => $revenue,
            'commission_costs' => $commissionCosts,
            'marketing_costs' => $marketingCost,
            'total_costs' => $totalCosts,
            'margin' => $margin,
            'budget_pct' => $budgetPct,
            'budget_target_pct' => $budgetTargetPct,
            'within_budget' => $budgetPct <= $budgetTargetPct,
        ];
    }

    /**
     * Calculate P&L including all child territories (rollup).
     */
    public function calculatePnlRollup(SalesTerritory $territory, Carbon $start, Carbon $end): array
    {
        $territory->load('children.children');
        $allIds = array_merge([$territory->id], $territory->getDescendantIds());

        $revenue = 0;
        $commissionCosts = 0;
        $marketingCosts = 0;

        foreach ($allIds as $id) {
            $t = SalesTerritory::find($id);
            if (! $t) {
                continue;
            }
            $revenue += $this->getRevenue($t, $start, $end);
            $commissionCosts += $this->getCommissionCosts($t, $start, $end);

            $budget = TerritoryBudget::where('territory_id', $id)
                ->where('period_start', '<=', $end)
                ->where('period_start', '>=', $start->copy()->startOfMonth())
                ->first();
            $marketingCosts += (float) ($budget?->marketing_budget ?? 0);
        }

        $totalCosts = $commissionCosts + $marketingCosts;
        $margin = $revenue - $totalCosts;
        $budgetPct = $revenue > 0 ? round(($commissionCosts / $revenue) * 100, 1) : 0;

        return [
            'territory_id' => $territory->id,
            'territory_name' => $territory->name,
            'level' => $territory->level,
            'revenue' => $revenue,
            'commission_costs' => $commissionCosts,
            'marketing_costs' => $marketingCosts,
            'total_costs' => $totalCosts,
            'margin' => $margin,
            'budget_pct' => $budgetPct,
            'budget_target_pct' => 12.0,
            'within_budget' => $budgetPct <= 12.0,
        ];
    }

    /**
     * Get budget alert if trending over target.
     */
    public function getBudgetAlert(SalesTerritory $territory, Carbon $start, Carbon $end): ?array
    {
        $pnl = $this->calculatePnl($territory, $start, $end);

        if (! $pnl['within_budget']) {
            return [
                'territory' => $territory->name,
                'current_pct' => $pnl['budget_pct'],
                'target_pct' => $pnl['budget_target_pct'],
                'over_by' => round($pnl['budget_pct'] - $pnl['budget_target_pct'], 1),
            ];
        }

        return null;
    }

    private function getRevenue(SalesTerritory $territory, Carbon $start, Carbon $end): float
    {
        $knockIds = DoorKnock::where('territory_id', $territory->id)
            ->whereBetween('knocked_at', [$start, $end])
            ->pluck('id');

        return (float) Deal::whereIn('origin_door_knock_id', $knockIds)
            ->where('stage', 'completed')
            ->sum('actual_value');
    }

    private function getCommissionCosts(SalesTerritory $territory, Carbon $start, Carbon $end): float
    {
        $knockIds = DoorKnock::where('territory_id', $territory->id)
            ->pluck('id');

        return (float) CommissionEntry::where('source_type', DoorKnock::class)
            ->whereIn('source_id', $knockIds)
            ->whereIn('status', ['pending', 'approved', 'paid'])
            ->whereBetween('earned_date', [$start, $end])
            ->sum('amount');
    }
}
```

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryPnlTest.php
```

Expected: All 3 tests pass.

**Step 5: Commit**

```bash
git add app/Services/TerritoryPnlService.php tests/Feature/Sales/TerritoryPnlTest.php
git commit -m "feat: add TerritoryPnlService for territory P&L and budget tracking"
```

---

### Task 6: Run Full Test Suite & Format

**Step 1:** `vendor/bin/pint --dirty`

**Step 2:** `php artisan test --compact tests/Feature/Sales/`

**Step 3:** Fix any failures, then:

```bash
git add -A && git commit -m "style: format code with Pint"
```

---

## Important Design Notes for Implementation

### Estimator Solo vs Team Logic (Design Section 2.3)

When applying estimator commissions in `createFromEstimate()` or `createFromJob()`, the system must determine whether the estimator worked solo or with a knocker team:

- **Solo estimator** (knocked + estimated + sold): Gets 10% — the deal has NO `origin_door_knock_id`, or `origin_door_knock_id` points to a knock by the SAME user
- **Team estimator** (knocker brought the lead): Gets 6% — the deal has `origin_door_knock_id` pointing to a knock by a DIFFERENT user

This logic should be added to the existing `createFromEstimate()` or `createFromJob()` methods in `CommissionService`. The commission_rules for estimators should have two rate entries: `source: 'estimate'` with rate 10% and `source: 'estimate_team'` with rate 6%. The service checks `origin_door_knock_id` to pick the right source.

**Implementation note:** This can be deferred to Session 3 or 4 if the existing commission infrastructure doesn't easily support dual rates. The core knocker and manager override logic must work first.

---

## Session 2 Completion Checklist

- [ ] `deals.origin_door_knock_id` column exists
- [ ] `DoorKnockService::convertToLead()` sets `origin_door_knock_id`
- [ ] `CommissionService::createFromDoorKnock()` handles flat + percentage commissions
- [ ] `CommissionService::createTerritoryOverrideCommissions()` walks hierarchy chain
- [ ] `TerritoryBudget` model + migration + factory
- [ ] `TerritoryPnlService` with calculatePnl, calculatePnlRollup, getBudgetAlert
- [ ] Factory states: `withDoorKnockCommission()`, `withTerritoryOverrideCommission()`
- [ ] Estimator solo vs team logic documented (implementation note above)
- [ ] All tests passing

**Session 3 can now build on:** commission triggers, P&L calculations, budget data — all the backend data needed for the UI.
