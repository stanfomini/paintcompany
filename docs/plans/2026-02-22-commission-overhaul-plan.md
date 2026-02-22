# Commission System Overhaul Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a fully connected commission system with company defaults, per-employee overrides, working tiered brackets, and attribution chain visibility on deals/jobs.

**Architecture:** Company-wide commission defaults stored as JSON on `CompanySetting`, resolved by territory role. Each employee inherits their role's defaults but can have personal overrides on `employee_profiles.commission_rules`. The `CommissionService` merges both to get effective rules. A new attribution section on deals shows the full commission chain.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Pest 4, Flux UI, Tailwind v4

**Design Doc:** `docs/plans/2026-02-22-commission-system-overhaul-design.md`

---

### Task 1: Migration — Add commission_defaults to company_settings

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_commission_defaults_to_company_settings_table.php`
- Modify: `app/Models/CompanySetting.php`

**Step 1: Create migration**

```bash
php artisan make:migration add_commission_defaults_to_company_settings_table --table=company_settings --no-interaction
```

**Step 2: Write migration content**

```php
public function up(): void
{
    Schema::table('company_settings', function (Blueprint $table) {
        $table->json('commission_defaults')->nullable()->after('business_days');
    });
}

public function down(): void
{
    Schema::table('company_settings', function (Blueprint $table) {
        $table->dropColumn('commission_defaults');
    });
}
```

**Step 3: Run migration**

```bash
php artisan migrate --no-interaction
```

**Step 4: Update CompanySetting model**

Add `'commission_defaults'` to the `$fillable` array (after `'business_days'`).

Add to the `casts()` method:
```php
'commission_defaults' => 'array',
```

Add getter methods:
```php
/**
 * Get commission defaults for a specific role.
 *
 * @return array<int, array{source: string, type: string, rate?: float, amount?: float, trigger_on?: string, auto_trigger: bool}>
 */
public function getCommissionDefaultsForRole(string $role): array
{
    $defaults = $this->commission_defaults ?? [];
    return $defaults[$role] ?? [];
}

/**
 * Get tiered commission bracket defaults.
 *
 * @return array{period: string, brackets: array<int, array{min: float, max: ?float, rate: float}>}|null
 */
public function getCommissionTierDefaults(): ?array
{
    $defaults = $this->commission_defaults ?? [];
    return $defaults['tiers'] ?? null;
}

/**
 * Get all commission default roles.
 *
 * @return array<string>
 */
public function getCommissionDefaultRoles(): array
{
    $defaults = $this->commission_defaults ?? [];
    return array_keys(array_diff_key($defaults, ['tiers' => true]));
}
```

**Step 5: Write test for model methods**

Create `tests/Feature/Commission/CompanyCommissionDefaultsTest.php`:

```php
<?php

use App\Models\CompanySetting;

it('stores and retrieves commission defaults', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['commission_defaults' => [
        'knocker' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 25, 'auto_trigger' => true],
        ],
        'estimator' => [
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.06, 'auto_trigger' => true],
        ],
    ]]);

    $settings->refresh();

    expect($settings->getCommissionDefaultsForRole('knocker'))->toHaveCount(1)
        ->and($settings->getCommissionDefaultsForRole('knocker')[0]['amount'])->toBe(25)
        ->and($settings->getCommissionDefaultsForRole('estimator')[0]['rate'])->toBe(0.06)
        ->and($settings->getCommissionDefaultsForRole('unknown'))->toBe([]);
});

it('retrieves tiered bracket defaults', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['commission_defaults' => [
        'tiers' => [
            'period' => 'monthly',
            'brackets' => [
                ['min' => 0, 'max' => 5000, 'rate' => 0.08],
                ['min' => 5001, 'max' => null, 'rate' => 0.12],
            ],
        ],
    ]]);

    $settings->refresh();
    $tiers = $settings->getCommissionTierDefaults();

    expect($tiers)->not->toBeNull()
        ->and($tiers['period'])->toBe('monthly')
        ->and($tiers['brackets'])->toHaveCount(2);
});

it('returns null tiers when none configured', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['commission_defaults' => ['knocker' => []]]);

    expect($settings->getCommissionTierDefaults())->toBeNull();
});
```

**Step 6: Run tests**

```bash
php artisan test --compact tests/Feature/Commission/CompanyCommissionDefaultsTest.php
```

**Step 7: Run pint and commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add commission_defaults JSON column to company_settings"
```

---

### Task 2: CommissionService — Effective Rules Resolution

**Files:**
- Modify: `app/Services/CommissionService.php`
- Test: `tests/Feature/Commission/EffectiveRulesTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/Commission/EffectiveRulesTest.php`:

```php
<?php

use App\Models\CompanySetting;
use App\Models\EmployeeProfile;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\CommissionService;

beforeEach(function () {
    $this->service = app(CommissionService::class);

    // Set up company defaults
    CompanySetting::getInstance()->update(['commission_defaults' => [
        'knocker' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 25, 'auto_trigger' => true],
            ['source' => 'job', 'type' => 'revenue_percentage', 'rate' => 0.04, 'auto_trigger' => true],
        ],
        'estimator' => [
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.06, 'auto_trigger' => true],
        ],
        'manager_override' => [
            ['source' => 'territory_override', 'type' => 'revenue_percentage', 'rate' => 0.02, 'auto_trigger' => true],
        ],
    ]]);
});

it('returns company defaults when employee has no overrides', function () {
    $user = User::factory()->asInternal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id, 'commission_rules' => null]);

    // Assign as knocker in a territory
    $territory = SalesTerritory::create(['name' => 'Test Block', 'level' => 'block', 'status' => 'active']);
    $territory->teamMembers()->attach($user->id, ['role' => 'knocker', 'added_by' => $user->id]);

    $rules = $this->service->getEffectiveRulesForEmployee($employee);

    expect($rules)->toHaveCount(2)
        ->and($rules[0]['source'])->toBe('door_knock')
        ->and($rules[1]['source'])->toBe('job');
});

it('uses employee overrides instead of defaults for matching source', function () {
    $user = User::factory()->asInternal()->create();
    $employee = EmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'commission_rules' => [
            ['source' => 'door_knock', 'type' => 'flat', 'amount' => 50, 'active' => true, 'auto_trigger' => true],
        ],
    ]);

    $territory = SalesTerritory::create(['name' => 'Test Block', 'level' => 'block', 'status' => 'active']);
    $territory->teamMembers()->attach($user->id, ['role' => 'knocker', 'added_by' => $user->id]);

    $rules = $this->service->getEffectiveRulesForEmployee($employee);

    // Should have overridden door_knock rule ($50 not $25) but kept default job rule
    $knockRule = collect($rules)->firstWhere('source', 'door_knock');
    $jobRule = collect($rules)->firstWhere('source', 'job');

    expect($knockRule['amount'])->toBe(50)
        ->and($jobRule['rate'])->toBe(0.04);
});

it('adds additional employee rules not in defaults', function () {
    $user = User::factory()->asInternal()->create();
    $employee = EmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'commission_rules' => [
            ['source' => 'lead', 'type' => 'flat', 'amount' => 10, 'active' => true, 'auto_trigger' => true],
        ],
    ]);

    $territory = SalesTerritory::create(['name' => 'Test Block', 'level' => 'block', 'status' => 'active']);
    $territory->teamMembers()->attach($user->id, ['role' => 'knocker', 'added_by' => $user->id]);

    $rules = $this->service->getEffectiveRulesForEmployee($employee);

    // Should have 3 rules: door_knock default, job default, lead override
    expect($rules)->toHaveCount(3);
    $leadRule = collect($rules)->firstWhere('source', 'lead');
    expect($leadRule['amount'])->toBe(10);
});

it('returns empty array when no role and no overrides', function () {
    $user = User::factory()->asInternal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id, 'commission_rules' => null]);

    $rules = $this->service->getEffectiveRulesForEmployee($employee);

    expect($rules)->toBe([]);
});

it('allows explicit role override parameter', function () {
    $user = User::factory()->asInternal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id, 'commission_rules' => null]);

    $rules = $this->service->getEffectiveRulesForEmployee($employee, 'estimator');

    expect($rules)->toHaveCount(1)
        ->and($rules[0]['source'])->toBe('estimate');
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact tests/Feature/Commission/EffectiveRulesTest.php
```

Expected: FAIL — `getEffectiveRulesForEmployee` method doesn't exist yet.

**Step 3: Implement getEffectiveRulesForEmployee**

Add to `app/Services/CommissionService.php`:

```php
/**
 * Get the effective commission rules for an employee.
 * Merges company defaults (by territory role) with personal overrides.
 * Overrides replace defaults where source matches; additional overrides are appended.
 *
 * @return array<int, array{source: string, type: string, rate?: float, amount?: float, auto_trigger: bool}>
 */
public function getEffectiveRulesForEmployee(EmployeeProfile $employee, ?string $roleOverride = null): array
{
    // 1. Determine the employee's territory role
    $role = $roleOverride ?? $this->resolveEmployeeTerritoryRole($employee);

    // 2. Get company defaults for that role
    $defaults = $role
        ? CompanySetting::getInstance()->getCommissionDefaultsForRole($role)
        : [];

    // 3. Get employee personal overrides
    $overrides = collect($employee->commission_rules ?? [])
        ->filter(fn (array $rule) => ($rule['active'] ?? true))
        ->values()
        ->all();

    // 4. Merge: overrides replace defaults where source matches
    $overrideSources = collect($overrides)->pluck('source')->unique()->all();

    $merged = collect($defaults)
        ->reject(fn (array $rule) => in_array($rule['source'] ?? '', $overrideSources))
        ->values()
        ->merge($overrides)
        ->values()
        ->all();

    return $merged;
}

/**
 * Resolve the primary territory role for an employee.
 * Checks territory_team_members for their role assignment.
 */
public function resolveEmployeeTerritoryRole(EmployeeProfile $employee): ?string
{
    $userId = $employee->user_id;

    // Check territory_team_members pivot for this user
    $membership = \Illuminate\Support\Facades\DB::table('territory_team_members')
        ->where('user_id', $userId)
        ->first();

    if ($membership) {
        return $membership->role;
    }

    // Check if they're a territory manager
    $isManager = SalesTerritory::where('manager_id', $userId)->exists();
    if ($isManager) {
        return 'manager_override';
    }

    return null;
}
```

**Step 4: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/Commission/EffectiveRulesTest.php
```

**Step 5: Update lifecycle methods to use effective rules**

In `CommissionService.php`, update `applyRulesForSource` to accept an optional `$role` parameter and use effective rules when `$autoOnly` is true:

Add a new convenience method:
```php
/**
 * Get auto-trigger effective rules for a source.
 * Used by lifecycle hooks to fire automatic commissions using the effective rules.
 *
 * @return array<int, array>
 */
public function getAutoEffectiveRulesForSource(EmployeeProfile $employee, string $source, ?string $roleOverride = null): array
{
    return collect($this->getEffectiveRulesForEmployee($employee, $roleOverride))
        ->filter(fn (array $rule) => ($rule['source'] ?? '') === $source && ($rule['auto_trigger'] ?? true))
        ->values()
        ->all();
}
```

**Step 6: Run full commission test suite**

```bash
php artisan test --compact tests/Feature/Commission/
```

**Step 7: Run pint and commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add effective rules resolution to CommissionService"
```

---

### Task 3: Commission Attribution Chain Query

**Files:**
- Modify: `app/Services/CommissionService.php`
- Test: `tests/Feature/Commission/AttributionChainTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/Commission/AttributionChainTest.php`:

```php
<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\EmployeeProfile;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\CommissionService;

it('builds attribution chain for a door knock deal', function () {
    $knocker = User::factory()->asInternal()->create(['name' => 'John Knocker']);
    $knockerProfile = EmployeeProfile::factory()->create(['user_id' => $knocker->id]);

    $territory = SalesTerritory::create([
        'name' => 'Block 1', 'level' => 'block', 'status' => 'active',
    ]);

    $knock = DoorKnock::create([
        'user_id' => $knocker->id,
        'territory_id' => $territory->id,
        'address' => '123 Main St',
        'status' => 'appointment_set',
        'knocked_at' => now(),
    ]);

    $deal = Deal::factory()->create([
        'lead_source' => 'door_knock',
        'origin_door_knock_id' => $knock->id,
        'assigned_to' => $knocker->id,
    ]);

    // Create a commission entry
    CommissionEntry::create([
        'employee_profile_id' => $knockerProfile->id,
        'commissionable_type' => EmployeeProfile::class,
        'commissionable_id' => $knockerProfile->id,
        'source_type' => DoorKnock::class,
        'source_id' => $knock->id,
        'source_label' => 'Door Knock: 123 Main St',
        'commission_type' => 'flat',
        'amount' => 25,
        'status' => 'pending',
        'earned_date' => now(),
    ]);

    $chain = app(CommissionService::class)->getCommissionChainForDeal($deal);

    expect($chain['lead_source'])->toBe('door_knock')
        ->and($chain['knocker'])->not->toBeNull()
        ->and($chain['knocker']['name'])->toBe('John Knocker')
        ->and($chain['territory_path'])->not->toBeNull()
        ->and($chain['entries'])->toHaveCount(1);
});

it('returns minimal chain for non-knock deals', function () {
    $deal = Deal::factory()->create(['lead_source' => 'website', 'origin_door_knock_id' => null]);

    $chain = app(CommissionService::class)->getCommissionChainForDeal($deal);

    expect($chain['lead_source'])->toBe('website')
        ->and($chain['knocker'])->toBeNull()
        ->and($chain['territory_path'])->toBeNull()
        ->and($chain['entries'])->toBe([]);
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact tests/Feature/Commission/AttributionChainTest.php
```

**Step 3: Implement getCommissionChainForDeal**

Add to `CommissionService.php`:

```php
/**
 * Get the full commission attribution chain for a deal.
 *
 * @return array{
 *     lead_source: ?string,
 *     knocker: ?array{name: string, knock_date: string, knock_address: string},
 *     territory_path: ?string,
 *     estimator: ?array{name: string},
 *     entries: array<int, array{person_name: string, role: string, source: string, amount: float, status: string}>,
 *     total_amount: float,
 *     revenue: float,
 *     commission_percent: float
 * }
 */
public function getCommissionChainForDeal(Deal $deal): array
{
    $chain = [
        'lead_source' => $deal->lead_source,
        'knocker' => null,
        'territory_path' => null,
        'estimator' => null,
        'entries' => [],
        'total_amount' => 0,
        'revenue' => (float) ($deal->actual_value ?? $deal->estimated_value ?? 0),
        'commission_percent' => 0,
    ];

    // Door knock attribution
    if ($deal->origin_door_knock_id) {
        $knock = $deal->originDoorKnock()->with('user', 'territory')->first();
        if ($knock) {
            $chain['knocker'] = [
                'name' => $knock->user?->name ?? 'Unknown',
                'knock_date' => $knock->knocked_at?->format('M j, Y') ?? '-',
                'knock_address' => $knock->address ?? '-',
            ];

            if ($knock->territory) {
                $ancestors = $knock->territory->getAncestors();
                $path = $ancestors->pluck('name')->reverse()->push($knock->territory->name)->implode(' > ');
                $chain['territory_path'] = $path;
            }
        }
    }

    // Estimator (deal creator or assigned estimator)
    if ($deal->assigned_to) {
        $assignee = $deal->assignedTo;
        if ($assignee) {
            $chain['estimator'] = ['name' => $assignee->name];
        }
    }

    // Gather all commission entries related to this deal chain
    $dealIds = [$deal->id];
    $estimateIds = $deal->estimates()->pluck('id')->all();
    $jobId = $deal->job?->id;

    $entries = CommissionEntry::query()
        ->with(['employeeProfile.user', 'commissionable'])
        ->where(function ($q) use ($deal, $estimateIds, $jobId) {
            $q->where(function ($q2) use ($deal) {
                $q2->where('source_type', Deal::class)->where('source_id', $deal->id);
            });

            if (! empty($estimateIds)) {
                $q->orWhere(function ($q2) use ($estimateIds) {
                    $q2->where('source_type', \App\Models\Estimate::class)->whereIn('source_id', $estimateIds);
                });
            }

            if ($jobId) {
                $q->orWhere(function ($q2) use ($jobId) {
                    $q2->where('source_type', \App\Models\Job::class)->where('source_id', $jobId);
                });
            }

            if ($deal->origin_door_knock_id) {
                $q->orWhere(function ($q2) use ($deal) {
                    $q2->where('source_type', DoorKnock::class)->where('source_id', $deal->origin_door_knock_id);
                });
            }
        })
        ->orderBy('earned_date')
        ->get();

    $totalAmount = 0;
    foreach ($entries as $entry) {
        $personName = $entry->employeeProfile?->user?->name ?? 'Unknown';
        $chain['entries'][] = [
            'id' => $entry->id,
            'person_name' => $personName,
            'role' => $this->inferRoleFromSource($entry->source_label ?? ''),
            'source_label' => $entry->source_label ?? '-',
            'commission_type' => $entry->commission_type,
            'amount' => (float) $entry->amount,
            'status' => $entry->status,
            'earned_date' => $entry->earned_date?->format('M j, Y'),
        ];
        $totalAmount += (float) $entry->amount;
    }

    $chain['total_amount'] = round($totalAmount, 2);
    if ($chain['revenue'] > 0) {
        $chain['commission_percent'] = round(($totalAmount / $chain['revenue']) * 100, 1);
    }

    return $chain;
}

/**
 * Infer role from source label.
 */
protected function inferRoleFromSource(string $sourceLabel): string
{
    if (str_starts_with($sourceLabel, 'Door Knock:')) {
        return 'Knocker';
    }
    if (str_starts_with($sourceLabel, 'Estimate:')) {
        return 'Estimator';
    }
    if (str_starts_with($sourceLabel, 'Territory Override:')) {
        return 'Manager';
    }
    if (str_starts_with($sourceLabel, 'Job:')) {
        return 'Sales';
    }
    if (str_starts_with($sourceLabel, 'Lead:')) {
        return 'Lead Gen';
    }
    if (str_starts_with($sourceLabel, 'Crew Bonus:')) {
        return 'Crew';
    }
    return 'Other';
}
```

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Commission/AttributionChainTest.php
```

**Step 5: Run pint and commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add commission attribution chain query to CommissionService"
```

---

### Task 4: Settings Page — /settings/commissions

**Files:**
- Create: `resources/views/pages/settings/⚡commissions.blade.php`
- Modify: `routes/settings.php`
- Modify: `resources/views/pages/settings/layout.blade.php`
- Test: `tests/Feature/Livewire/Settings/CommissionSettingsTest.php`

This is the largest task. It creates the full `/settings/commissions` page with role-based cards for knocker, estimator, manager override, and tiered brackets.

**Step 1: Add route**

In `routes/settings.php`, add after the workflows route:

```php
Route::livewire('settings/commissions', 'pages::settings.commissions')
    ->middleware('permission:payroll.manage')
    ->name('commissions.edit');
```

**Step 2: Add nav link**

In `resources/views/pages/settings/layout.blade.php`, add after the workflows nav item block:

```blade
@can('payroll.manage')
    <flux:navlist.item :href="route('commissions.edit')" wire:navigate>{{ __('Commissions') }}</flux:navlist.item>
@endcan
```

**Step 3: Create the Livewire SFC page**

Create `resources/views/pages/settings/⚡commissions.blade.php`.

The component stores the commission defaults structure and provides methods to add/remove rules per role and manage tiered brackets.

Key properties:
- `$knockerRules` (array) — rules for knocker role
- `$estimatorRules` (array) — rules for estimator role
- `$managerRules` (array) — rules for manager override role
- `$tierPeriod` (string) — monthly/quarterly/yearly
- `$tierBrackets` (array) — min/max/rate brackets

Key methods:
- `mount()` — load from `CompanySetting::getInstance()->commission_defaults`
- `addRule($role)` — add blank rule to a role
- `removeRule($role, $index)` — remove rule from a role
- `addBracket()` — add tier bracket
- `removeBracket($index)` — remove tier bracket
- `save()` — validate and save back to `CompanySetting`

Each role card shows:
- Rule rows with: Source (select), Type (select: flat/revenue_percentage/profit_percentage), Rate (number input, shown for % types), Amount (number input with $ prefix, shown for flat type), Auto-trigger toggle
- "+ Add Rule" button

Tiered Brackets section shows:
- Period selector (monthly/quarterly/yearly)
- Bracket rows: Min (number), Max (number, nullable for infinity), Rate (number)
- "+ Add Bracket" button

The template uses the settings layout with `fullWidth` enabled: `<x-settings.layout heading="..." subheading="..." :fullWidth="true">`

**Step 4: Write tests**

Create `tests/Feature/Livewire/Settings/CommissionSettingsTest.php`:

Test cases:
- `it('requires payroll.manage permission')`
- `it('loads existing commission defaults')`
- `it('saves commission defaults for all roles')`
- `it('adds and removes rules for a role')`
- `it('adds and removes tier brackets')`
- `it('validates required fields before saving')`

**Step 5: Run tests, pint, commit**

```bash
php artisan test --compact tests/Feature/Livewire/Settings/CommissionSettingsTest.php
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add /settings/commissions page with role-based defaults and tiered brackets"
```

---

### Task 5: Employee Commission Tab — Inherited + Override View

**Files:**
- Modify: `resources/views/components/employees/⚡show.blade.php`
- Test: `tests/Feature/Livewire/Employees/EmployeeCommissionTabTest.php`

**Step 1: Update the Commission tab validation**

In the `saveCommissionRules()` method, update the source validation to include all sources:

```php
'commissionRules.*.source' => 'required|in:lead,estimate,job,door_knock,territory_override',
```

**Step 2: Add a computed property for territory role**

```php
#[Computed]
public function employeeTerritoryInfo(): ?array
{
    $userId = $this->employeeProfile->user_id;

    $membership = \Illuminate\Support\Facades\DB::table('territory_team_members')
        ->where('user_id', $userId)
        ->join('sales_territories', 'sales_territories.id', '=', 'territory_team_members.territory_id')
        ->select('territory_team_members.role', 'sales_territories.name', 'sales_territories.id', 'sales_territories.level')
        ->first();

    if (! $membership) {
        // Check if manager
        $managedTerritory = \App\Models\SalesTerritory::where('manager_id', $userId)->first();
        if ($managedTerritory) {
            return ['role' => 'manager_override', 'territory_name' => $managedTerritory->name, 'level' => $managedTerritory->level];
        }
        return null;
    }

    return [
        'role' => $membership->role,
        'territory_name' => $membership->name,
        'level' => $membership->level,
    ];
}
```

**Step 3: Add computed property for effective rules**

```php
#[Computed]
public function effectiveRules(): array
{
    return app(\App\Services\CommissionService::class)
        ->getEffectiveRulesForEmployee($this->employeeProfile);
}

#[Computed]
public function companyDefaults(): array
{
    $info = $this->employeeTerritoryInfo;
    if (! $info) {
        return [];
    }
    return \App\Models\CompanySetting::getInstance()
        ->getCommissionDefaultsForRole($info['role']);
}
```

**Step 4: Rebuild the Commission tab template**

Replace the commission tab content with sections:

1. **Territory Role** — shows role badge and territory name (from `employeeTerritoryInfo`)
2. **Company Defaults (inherited)** — read-only display of `companyDefaults`, with lock icon, grayed styling
3. **Personal Overrides** — the existing editable rules (keep current add/remove/toggle/save logic). Add `door_knock` and `territory_override` options to the source dropdown.
4. **Effective Rules** — merged view from `effectiveRules` computed. Each row shows "(default)" or "(override)" badge.
5. **Recent Commission Entries** — keep existing

**Step 5: Write test**

Create `tests/Feature/Livewire/Employees/EmployeeCommissionTabTest.php`:

Test cases:
- `it('shows territory role on commission tab')`
- `it('shows company defaults as inherited rules')`
- `it('shows effective merged rules')`
- `it('allows door_knock and territory_override sources')`
- `it('saves override that replaces default for same source')`

**Step 6: Run tests, pint, commit**

```bash
php artisan test --compact tests/Feature/Livewire/Employees/EmployeeCommissionTabTest.php
vendor/bin/pint --dirty
git add -A && git commit -m "feat: overhaul employee commission tab with inherited defaults + override view"
```

---

### Task 6: Attribution Section on Deal Show Page

**Files:**
- Modify: `resources/views/components/deals/⚡show.blade.php`
- Test: `tests/Feature/Livewire/Deals/DealAttributionTest.php`

**Step 1: Add computed property for attribution chain**

In the deal show component, add:

```php
#[Computed]
public function commissionChain(): array
{
    return app(\App\Services\CommissionService::class)
        ->getCommissionChainForDeal($this->deal);
}
```

**Step 2: Add Attribution & Commissions section to the template**

Add after the existing deal info sections (below estimates, above notes if possible). Only visible to users with `payroll.manage` permission.

Show:
- Lead Source badge with icon
- Knocker info (if door knock): name, date, address
- Territory path (if applicable): "North Region > Zone A > Block 3"
- Commission chain table: person, role badge, source, amount, status badge
- Total: sum + % of revenue

**Step 3: Write test**

Test cases:
- `it('shows attribution section for door knock deals')`
- `it('shows commission entries in the chain')`
- `it('hides attribution from non-payroll users')`
- `it('handles deals with no commissions')`

**Step 4: Run tests, pint, commit**

```bash
php artisan test --compact tests/Feature/Livewire/Deals/DealAttributionTest.php
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add attribution and commissions section to deal show page"
```

---

### Task 7: Update Compensation Planner to Read Real Defaults

**Files:**
- Modify: `resources/views/components/sales/⚡compensation-planner.blade.php`

**Step 1: Load real defaults in mount()**

In `mount()`, after the auth check, load company defaults:

```php
$defaults = CompanySetting::getInstance()->commission_defaults ?? [];
$knockerRules = $defaults['knocker'] ?? [];
$estimatorRules = $defaults['estimator'] ?? [];
$managerRules = $defaults['manager_override'] ?? [];

// Map to slider values
foreach ($knockerRules as $rule) {
    if ($rule['source'] === 'job' && $rule['type'] === 'revenue_percentage') {
        $this->knockerCommissionPct = ($rule['rate'] ?? 0) * 100;
    }
}
foreach ($estimatorRules as $rule) {
    if ($rule['source'] === 'estimate' && $rule['type'] === 'revenue_percentage') {
        $this->estimatorTeamPct = ($rule['rate'] ?? 0) * 100;
    }
}
foreach ($managerRules as $rule) {
    if ($rule['source'] === 'territory_override' && $rule['type'] === 'revenue_percentage') {
        $this->regionManagerPct = ($rule['rate'] ?? 0) * 100;
    }
}
```

**Step 2: Add a callout noting these are loaded from real defaults**

Add at the top of the template:

```blade
<flux:callout variant="info" class="mb-4">
    Starting values are loaded from your <a href="{{ route('commissions.edit') }}" class="underline font-medium" wire:navigate>Commission Settings</a>.
    Changes here are for modeling only — they don't update actual commission rates.
</flux:callout>
```

**Step 3: Run pint and commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat: wire compensation planner to read from real commission defaults"
```

---

### Task 8: Enhance Admin Commissions Page

**Files:**
- Modify: `resources/views/components/payroll/⚡commissions.blade.php`

**Step 1: Add source_type filter**

Add a new filter dropdown for source type:

Property: `public string $sourceFilter = 'all';`

In the `commissions()` computed, add:
```php
->when($this->sourceFilter !== 'all', fn ($q) => $q->where('source_label', 'like', match ($this->sourceFilter) {
    'door_knock' => 'Door Knock:%',
    'estimate' => 'Estimate:%',
    'job' => 'Job:%',
    'territory_override' => 'Territory Override:%',
    'lead' => 'Lead:%',
    'manual' => 'Manual Entry',
    'crew' => 'Crew Bonus:%',
    default => '%',
}))
```

**Step 2: Add the filter dropdown in the template**

```blade
<flux:select wire:model.live="sourceFilter" class="w-44">
    <option value="all">{{ __('All Sources') }}</option>
    <option value="door_knock">{{ __('Door Knocks') }}</option>
    <option value="estimate">{{ __('Estimates') }}</option>
    <option value="job">{{ __('Jobs') }}</option>
    <option value="territory_override">{{ __('Territory Overrides') }}</option>
    <option value="lead">{{ __('Leads') }}</option>
    <option value="manual">{{ __('Manual') }}</option>
    <option value="crew">{{ __('Crew Bonuses') }}</option>
</flux:select>
```

**Step 3: Run pint and commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add source filter to admin commissions page"
```

---

### Task 9: Integration Tests — End-to-End Commission Flow

**Files:**
- Create: `tests/Feature/Commission/EndToEndCommissionFlowTest.php`

**Step 1: Write end-to-end test**

This test verifies the full flow: knocker records knock → deal created → estimate accepted → job completed → all commissions fire correctly using company defaults.

Test cases:
- `it('creates full commission chain from door knock to job completion')`
- `it('applies employee override instead of company default')`
- `it('walks territory hierarchy for manager override commissions')`

**Step 2: Run tests**

```bash
php artisan test --compact tests/Feature/Commission/EndToEndCommissionFlowTest.php
```

**Step 3: Run full commission test suite**

```bash
php artisan test --compact tests/Feature/Commission/
```

**Step 4: Run pint and commit**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "test: add end-to-end commission flow integration tests"
```

---

### Task 10: Final Polish and Full Test Suite

**Step 1: Run pint on all modified files**

```bash
vendor/bin/pint --dirty
```

**Step 2: Run the full test suite (in batches if needed)**

```bash
php artisan test --compact tests/Feature/Commission/
php artisan test --compact tests/Feature/Livewire/Settings/CommissionSettingsTest.php
php artisan test --compact tests/Feature/Livewire/Employees/EmployeeCommissionTabTest.php
php artisan test --compact tests/Feature/Livewire/Deals/DealAttributionTest.php
```

**Step 3: Verify all routes work**

```bash
php artisan route:list --name=commissions
```

**Step 4: Final commit**

```bash
git add -A && git commit -m "chore: commission system overhaul complete — defaults, overrides, attribution"
```
