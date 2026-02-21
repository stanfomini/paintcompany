# Territory Hierarchy Session 4: Integration Review

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Verify the entire territory hierarchy system works end-to-end: create region → add zones → add blocks → assign team → knock doors → convert to lead → complete job → commissions flow → P&L tracks correctly. Fix any cross-session integration gaps. Performance test with realistic data. Mobile UX review.

**Architecture:** No new architecture — this session validates and integrates everything from Sessions 1-3.

**Tech Stack:** Laravel 12, Livewire 4, Pest 4, Browser tests (Pest 4 browser testing)

**Design Doc:** `docs/plans/2026-02-21-territory-hierarchy-design.md`

**Pre-requisites:** Sessions 1, 2, and 3 all complete.

---

### Task 1: End-to-End Integration Test — Full Pipeline

**Files:**
- Create: `tests/Feature/Sales/TerritoryPipelineIntegrationTest.php`

**Step 1: Write the integration test**

This test validates the complete flow from region creation through commission payout:

```php
<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\EmployeeProfile;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\SalesTerritory;
use App\Models\TerritoryBudget;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\DoorKnockService;
use App\Services\PermissionScopeService;
use App\Services\TerritoryPnlService;

it('completes full territory pipeline: region → knock → job → commission → P&L', function () {
    // === 1. SET UP HIERARCHY ===
    $owner = User::factory()->internal()->withRole('owner')->create();
    $regionManager = User::factory()->internal()->withRole('manager')->create();
    $estimator = User::factory()->internal()->withRole('estimator')->create();
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();

    // Employee profiles with commission rules
    EmployeeProfile::factory()->for($knocker, 'user')
        ->withDoorKnockCommission('flat', 25)->create();
    EmployeeProfile::factory()->for($estimator, 'user')->create();
    EmployeeProfile::factory()->for($regionManager, 'user')
        ->withTerritoryOverrideCommission(0.02)->create();

    // Territory hierarchy
    $region = SalesTerritory::factory()->region()
        ->assignedTo($regionManager)->managedBy($regionManager)->create(['name' => 'East Region']);
    $zone = SalesTerritory::factory()->zone()
        ->childOf($region)->assignedTo($estimator)->create(['name' => 'Zone 1']);
    $block = SalesTerritory::factory()->block()
        ->childOf($zone)->assignedTo($knocker)->create([
            'name' => 'Block 101',
            'boundary' => ['type' => 'Polygon', 'coordinates' => [[[-90.05, 34.95], [-89.95, 34.95], [-89.95, 35.05], [-90.05, 35.05], [-90.05, 34.95]]]],
        ]);

    // Budget
    TerritoryBudget::factory()->monthly()->create([
        'territory_id' => $region->id,
        'commission_budget_pct' => 12,
        'marketing_budget' => 1000,
    ]);

    // === 2. VERIFY PERMISSIONS ===
    $scopeService = app(PermissionScopeService::class);

    // Grant scoped permissions
    $scopeService->grantPermission($regionManager, 'territories.view_own',
        ['territory_ids' => [$region->id, $zone->id, $block->id]], $owner);
    $scopeService->grantPermission($knocker, 'territories.view_own',
        ['territory_ids' => [$block->id]], $owner);

    // Region manager sees their tree
    $visibleToManager = $scopeService->getVisibleTerritoryIds($regionManager);
    expect($visibleToManager)->toContain($region->id);
    expect($visibleToManager)->toContain($zone->id);
    expect($visibleToManager)->toContain($block->id);

    // Knocker sees only their block
    $visibleToKnocker = $scopeService->getVisibleTerritoryIds($knocker);
    expect($visibleToKnocker)->toContain($block->id);
    expect($visibleToKnocker)->not->toContain($zone->id);

    // === 3. DOOR KNOCK ===
    $this->actingAs($knocker);
    $doorKnockService = app(DoorKnockService::class);

    $knock = $doorKnockService->recordKnock($knocker, [
        'territory_id' => $block->id,
        'address' => '456 Oak St',
        'city' => 'Memphis',
        'state' => 'TN',
        'zip' => '38101',
        'lat' => 35.0,
        'lng' => -90.0,
        'status' => 'appointment_set',
        'customer_name' => 'John Doe',
        'email' => 'johndoe-test@example.com',
        'phone' => '555-111-2222',
    ]);

    expect($knock->territory_id)->toBe($block->id);
    expect($knock->status)->toBe('appointment_set');

    // === 4. CONVERT TO LEAD ===
    $deal = $doorKnockService->convertToLead($knock);

    expect($deal)->toBeInstanceOf(Deal::class);
    expect($deal->origin_door_knock_id)->toBe($knock->id);
    expect($deal->stage)->toBe('lead');

    // === 5. KNOCKER COMMISSION ===
    $commissionService = app(CommissionService::class);
    $knockerCommissions = $commissionService->createFromDoorKnock($knock);

    expect($knockerCommissions)->toHaveCount(1);
    expect($knockerCommissions[0]->amount)->toBe(25.0);

    // === 6. SIMULATE JOB COMPLETION ===
    $deal->update(['actual_value' => 10000, 'stage' => 'completed']);

    $estimate = Estimate::factory()->for($deal)->create([
        'total' => 10000,
        'assigned_to' => $estimator->id,
    ]);
    $job = Job::factory()->for($deal)->create([
        'estimate_id' => $estimate->id,
        'status' => 'completed',
    ]);

    // === 7. TERRITORY OVERRIDE COMMISSION ===
    $overrideCommissions = $commissionService->createTerritoryOverrideCommissions($job);

    // Region manager should get 2% of $10,000 = $200
    expect($overrideCommissions)->toHaveCount(1);
    expect($overrideCommissions[0]->amount)->toBe(200.0);

    // === 8. P&L CHECK ===
    $pnlService = app(TerritoryPnlService::class);

    // Block-level P&L
    $blockPnl = $pnlService->calculatePnl($block, now()->startOfMonth(), now()->endOfMonth());
    expect($blockPnl['revenue'])->toBe(10000.0);
    expect($blockPnl['commission_costs'])->toBe(25.0); // knocker flat commission

    // Region rollup P&L (includes all children)
    $regionPnl = $pnlService->calculatePnlRollup($region, now()->startOfMonth(), now()->endOfMonth());
    expect($regionPnl['revenue'])->toBe(10000.0);

    // === 9. BUDGET CHECK ===
    expect($blockPnl['within_budget'])->toBeTrue(); // 0.25% < 12%
});

it('hierarchy relationships are consistent', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    $block = SalesTerritory::factory()->block()->childOf($zone)->create();

    // Parent chain
    expect($block->parent->id)->toBe($zone->id);
    expect($zone->parent->id)->toBe($region->id);
    expect($region->parent)->toBeNull();

    // Children chain
    expect($region->children)->toHaveCount(1);
    expect($region->children->first()->id)->toBe($zone->id);
    expect($zone->children)->toHaveCount(1);
    expect($zone->children->first()->id)->toBe($block->id);

    // Level validation
    expect($region->level)->toBe('region');
    expect($zone->level)->toBe('zone');
    expect($block->level)->toBe('block');

    // Ancestor/descendant methods
    $ancestors = $block->getAncestors();
    expect($ancestors)->toHaveCount(2);
    expect($ancestors->pluck('id')->toArray())->toContain($zone->id, $region->id);

    $descendants = $region->getDescendantIds();
    expect($descendants)->toContain($zone->id, $block->id);
});

it('prevents orphaned territory hierarchy', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    $block = SalesTerritory::factory()->block()->childOf($zone)->create();

    // Verify parent_id is set correctly
    expect($zone->parent_id)->toBe($region->id);
    expect($block->parent_id)->toBe($zone->id);
    expect($region->parent_id)->toBeNull();
});
```

**Step 2: Run the integration test**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryPipelineIntegrationTest.php
```

Expected: All tests pass. If any fail, debug and fix the underlying issue in the session that introduced it.

**Step 3: Commit**

```bash
git add tests/Feature/Sales/TerritoryPipelineIntegrationTest.php
git commit -m "test: add end-to-end territory pipeline integration test"
```

---

### Task 2: Cross-Session Consistency Check

**Files:**
- Create: `tests/Feature/Sales/TerritoryConsistencyTest.php`

**Step 1: Write consistency validation tests**

```php
<?php

use App\Models\PermissionScope;
use App\Models\SalesTerritory;
use App\Models\TerritoryBudget;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('sales_territories table has all hierarchy columns', function () {
    expect(Schema::hasColumn('sales_territories', 'level'))->toBeTrue();
    expect(Schema::hasColumn('sales_territories', 'parent_id'))->toBeTrue();
    expect(Schema::hasColumn('sales_territories', 'manager_id'))->toBeTrue();
});

it('territory_team_members table exists', function () {
    expect(Schema::hasTable('territory_team_members'))->toBeTrue();
    expect(Schema::hasColumn('territory_team_members', 'territory_id'))->toBeTrue();
    expect(Schema::hasColumn('territory_team_members', 'user_id'))->toBeTrue();
    expect(Schema::hasColumn('territory_team_members', 'role'))->toBeTrue();
});

it('permission_scopes table exists', function () {
    expect(Schema::hasTable('permission_scopes'))->toBeTrue();
    expect(Schema::hasColumn('permission_scopes', 'user_id'))->toBeTrue();
    expect(Schema::hasColumn('permission_scopes', 'permission'))->toBeTrue();
    expect(Schema::hasColumn('permission_scopes', 'scope_type'))->toBeTrue();
    expect(Schema::hasColumn('permission_scopes', 'scope_value'))->toBeTrue();
});

it('deals table has origin_door_knock_id', function () {
    expect(Schema::hasColumn('deals', 'origin_door_knock_id'))->toBeTrue();
});

it('territory_budgets table exists', function () {
    expect(Schema::hasTable('territory_budgets'))->toBeTrue();
});

it('compensation_scenarios table exists', function () {
    expect(Schema::hasTable('compensation_scenarios'))->toBeTrue();
});

it('door_knocker role exists with correct permissions', function () {
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();

    expect($knocker->hasRole('door_knocker'))->toBeTrue();
    expect($knocker->hasPermissionTo('door_knocks.record'))->toBeTrue();
    expect($knocker->hasPermissionTo('territories.view_own'))->toBeTrue();
    // Should NOT have admin permissions
    expect($knocker->hasPermissionTo('territories.manage_regions'))->toBeFalse();
});

it('all new permissions exist in the system', function () {
    $expectedPermissions = [
        'territories.manage_regions',
        'territories.manage_zones',
        'territories.manage_blocks',
        'territories.assign_team',
        'territories.view_all',
        'territories.view_own',
        'territories.dispatch_blocks',
        'commission.configure',
        'commission.view_team',
        'commission.view_all',
        'analytics.territory_pnl',
        'compensation.planner',
    ];

    foreach ($expectedPermissions as $perm) {
        expect(\Spatie\Permission\Models\Permission::where('name', $perm)->exists())
            ->toBeTrue("Permission '$perm' should exist");
    }
});

it('factory states work for all new models and states', function () {
    // Territory hierarchy factories
    $region = SalesTerritory::factory()->region()->create();
    expect($region->level)->toBe('region');

    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    expect($zone->level)->toBe('zone');
    expect($zone->parent_id)->toBe($region->id);

    $block = SalesTerritory::factory()->block()->childOf($zone)->create();
    expect($block->level)->toBe('block');
    expect($block->parent_id)->toBe($zone->id);

    // Budget factory
    $budget = TerritoryBudget::factory()->monthly()->create(['territory_id' => $region->id]);
    expect($budget->period_type)->toBe('monthly');
});
```

**Step 2: Run consistency tests**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryConsistencyTest.php
```

**Step 3: Fix any failures**

If any test fails, it indicates a gap between sessions. Debug and fix:
- Missing columns → check migration ran
- Missing permissions → check seeder ran
- Missing factory states → add to respective factory

**Step 4: Commit**

```bash
git add tests/Feature/Sales/TerritoryConsistencyTest.php
git commit -m "test: add cross-session consistency validation tests"
```

---

### Task 3: Performance Testing with Realistic Data

**Files:**
- Create: `tests/Feature/Sales/TerritoryPerformanceTest.php`

**Step 1: Write performance tests**

```php
<?php

use App\Models\DoorKnock;
use App\Models\EmployeeProfile;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\PermissionScopeService;
use App\Services\TerritoryAnalyticsService;
use App\Services\TerritoryPnlService;
use Livewire\Livewire;

it('loads hierarchy tree with 5 regions, 20 zones, 100 blocks in under 2 seconds', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    // Create realistic data volume
    $regions = SalesTerritory::factory()->region()->count(5)->create();
    foreach ($regions as $region) {
        $zones = SalesTerritory::factory()->zone()->childOf($region)->count(4)->create();
        foreach ($zones as $zone) {
            SalesTerritory::factory()->block()->childOf($zone)->count(5)->create();
        }
    }

    $start = microtime(true);

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->assertSuccessful();

    $elapsed = microtime(true) - $start;
    expect($elapsed)->toBeLessThan(2.0, "Hierarchy loaded in {$elapsed}s, expected < 2s");
});

it('calculates P&L rollup for region with 20 zones in under 3 seconds', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zones = SalesTerritory::factory()->zone()->childOf($region)->count(20)->create();

    // Create some knock/deal data
    $user = User::factory()->internal()->create();
    foreach ($zones->take(5) as $zone) {
        DoorKnock::factory()->count(10)->forUser($user)->create(['territory_id' => $zone->id]);
    }

    $start = microtime(true);

    $pnl = app(TerritoryPnlService::class)->calculatePnlRollup(
        $region, now()->startOfMonth(), now()->endOfMonth()
    );

    $elapsed = microtime(true) - $start;
    expect($elapsed)->toBeLessThan(3.0, "P&L rollup completed in {$elapsed}s, expected < 3s");
    expect($pnl)->toHaveKey('revenue');
});

it('territory map loads with 100+ polygons without error', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    // Create territories with boundaries
    $boundary = ['type' => 'Polygon', 'coordinates' => [[[-90, 35], [-89, 35], [-89, 36], [-90, 36], [-90, 35]]]];
    SalesTerritory::factory()->count(100)->create(['boundary' => $boundary]);

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertSuccessful();
});
```

**Step 2: Run performance tests**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryPerformanceTest.php
```

**Step 3: Optimize if any test fails timing threshold**

Common optimizations:
- Add eager loading: `->with(['children.children.assignedUser'])`
- Add database indexes: `parent_id`, `level`, `territory_id` on knocks/commissions
- Cache P&L calculations for same territory/period
- Paginate large hierarchy trees

**Step 4: Commit**

```bash
git add tests/Feature/Sales/TerritoryPerformanceTest.php
git commit -m "test: add performance tests for territory hierarchy, P&L, and map loading"
```

---

### Task 4: UI Integration Tests — Tab Navigation & Permission Visibility

**Files:**
- Create: `tests/Feature/Sales/TerritoryUIIntegrationTest.php`

**Step 1: Write UI integration tests**

```php
<?php

use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\PermissionScopeService;
use Livewire\Livewire;

it('owner can access all 4 tabs', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertSee('Map')
        ->assertSee('Hierarchy')
        ->assertSee('Dispatch')
        ->assertSee('Analytics')
        ->set('activeTab', 'hierarchy')
        ->assertSuccessful()
        ->set('activeTab', 'dispatch')
        ->assertSuccessful()
        ->set('activeTab', 'analytics')
        ->assertSuccessful()
        ->set('activeTab', 'map')
        ->assertSuccessful();
});

it('door knocker cannot access territory manager page', function () {
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();

    // Door knockers should use the door-knock-map page instead
    $this->actingAs($knocker)
        ->get('/sales/territories')
        ->assertForbidden();
});

it('door knocker can access door knock page', function () {
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();

    $this->actingAs($knocker)
        ->get('/sales/door-knocks')
        ->assertSuccessful();
});

it('compensation planner page is owner-only', function () {
    $owner = User::factory()->internal()->withRole('owner')->create();
    $manager = User::factory()->internal()->withRole('manager')->create();

    $this->actingAs($owner)
        ->get('/sales/compensation-planner')
        ->assertSuccessful();

    $this->actingAs($manager)
        ->get('/sales/compensation-planner')
        ->assertForbidden();
});

it('hierarchy shows correct structure when creating zone under region', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $region = SalesTerritory::factory()->region()->create(['name' => 'Test Region']);

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->assertSee('Test Region')
        ->call('startCreateChild', $region->id, 'zone')
        ->assertSet('creatingChildOfId', $region->id)
        ->set('childName', 'Test Zone')
        ->call('saveChild')
        ->assertSee('Test Zone');
});

it('analytics tab shows P&L data with correct period filtering', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    SalesTerritory::factory()->region()->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->set('activeTab', 'analytics')
        ->assertSee('Territory P&L')
        ->set('pnlPeriod', 'quarter')
        ->assertSet('pnlPeriod', 'quarter');
});
```

**Step 2: Run UI integration tests**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryUIIntegrationTest.php
```

**Step 3: Fix any route or permission issues found**

**Step 4: Commit**

```bash
git add tests/Feature/Sales/TerritoryUIIntegrationTest.php
git commit -m "test: add UI integration tests for tab navigation and permission visibility"
```

---

### Task 5: Mobile UX Review for Door Knocker Experience

**Files:**
- Modify: `resources/views/components/sales/⚡door-knock-map.blade.php` (responsive tweaks if needed)
- Test: Browser test (optional, if Pest 4 browser testing is configured)

**Step 1: Review and fix mobile responsiveness**

Check the door-knock-map component for mobile-specific issues:

1. **Map height**: Ensure the map takes full viewport height on mobile (minus stats bar and form)
2. **Touch targets**: All buttons should be at least 44px tap targets
3. **Stats bar**: Should be compact on mobile (single row, abbreviations)
4. **Form**: Should slide up from bottom on mobile, not overlay map
5. **Tracking button**: Large, easy to tap while walking
6. **Re-center button**: Positioned in bottom-right, above form if open

Key responsive tweaks to verify in the template:

```blade
{{-- Map container should fill available space --}}
<div class="h-[calc(100vh-12rem)] sm:h-[600px]" ...>

{{-- Stats bar should be compact on mobile --}}
<div class="flex items-center gap-2 sm:gap-4 px-2 sm:px-3 py-1.5 sm:py-2 ...">

{{-- Tracking button should be large on mobile --}}
<button class="p-3 sm:p-2 rounded-full ...">
```

**Step 2: Write mobile-focused assertions (optional browser test)**

If Pest 4 browser testing is configured:

```php
<?php

use App\Models\User;

it('door knock map is usable on mobile viewport', function () {
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();
    $this->actingAs($knocker);

    $page = visit('/sales/door-knocks')
        ->resize(375, 812); // iPhone viewport

    $page->assertSee('Today')
        ->assertNoJavascriptErrors();
})->skip(! class_exists('Pest\Browser\PendingTest'), 'Browser testing not configured');
```

**Step 3: Apply responsive fixes if needed**

Update the Blade template with any identified mobile issues.

**Step 4: Build and commit**

```bash
npm run build
git add resources/views/components/sales/⚡door-knock-map.blade.php
git commit -m "fix: improve door knocker mobile UX responsiveness"
```

---

### Task 6: Run Complete Test Suite

**Step 1: Format code**

```bash
vendor/bin/pint --dirty
```

**Step 2: Run all territory/sales/settings tests**

```bash
php artisan test --compact tests/Feature/Sales/
php artisan test --compact tests/Feature/Settings/
```

**Step 3: Run the full test suite**

```bash
php artisan test --compact
```

Note: If the full suite hits the 512MB memory limit, run in batches:

```bash
php artisan test --compact tests/Feature/Sales/
php artisan test --compact tests/Feature/Settings/
php artisan test --compact tests/Feature/Livewire/
php artisan test --compact tests/Feature/ --exclude-group=sales,settings,livewire
php artisan test --compact tests/Unit/
```

**Step 4: Fix any failures**

**Step 5: Final commit**

```bash
git add -A && git commit -m "chore: session 4 integration review complete - all tests passing"
```

---

## Session 4 Completion Checklist

- [ ] End-to-end pipeline test passes: region → knock → deal → job → commission → P&L
- [ ] Hierarchy relationships consistent (parent/child/ancestor/descendant)
- [ ] All database tables and columns from Sessions 1-3 verified
- [ ] All new permissions seeded and role assignments correct
- [ ] Factory states work for all new models
- [ ] Performance: hierarchy loads < 2s, P&L rollup < 3s, 100+ polygons OK
- [ ] UI tabs all accessible with correct permissions
- [ ] Door knocker restricted to their page only
- [ ] Compensation planner owner-only access enforced
- [ ] Mobile door knocker UX reviewed and responsive
- [ ] Full test suite passing
- [ ] Code formatted with Pint

## Project Completion Summary

After Session 4, the territory hierarchy system provides:

1. **3-level hierarchy**: Region > Zone > Block with parent/child relationships
2. **5 roles**: owner, admin, manager, estimator, door_knocker
3. **Scoped permissions**: Spatie base + user-level grant/revoke overrides
4. **Commission pipeline**: Knocker (flat/%) → Estimator → Manager override
5. **3 pay models**: Commission Only, Flat+Commission, Flat Only
6. **Deal attribution**: origin_door_knock_id traces revenue back through hierarchy
7. **Territory P&L**: Revenue, costs, margin, budget % with rollup
8. **Budget alerts**: Territories trending over 12% target
9. **Compensation planner**: 3-model comparison, reverse calculator, scenario save/load
10. **Permission-scoped UI**: Users only see their territory tree
11. **4-tab territory page**: Map, Hierarchy, Dispatch, Analytics
12. **Door knocker dashboard**: Minimal scoped map + today stats
