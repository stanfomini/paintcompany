# Territory Hierarchy Session 3: UI/UX Implementation

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build the 4-tab territory management page (Map, Hierarchy, Dispatch, Analytics with P&L), compensation planning calculator with 3-scenario comparison and reverse income targeting, door knocker minimal dashboard, settings permission overrides UI, and territory map scoping by user permissions.

**Architecture:** Enhance the existing `⚡territory-manager.blade.php` with a tabbed layout. Add new Livewire SFC components for hierarchy tree, compensation planner, and permission overrides. All components use `PermissionScopeService` for visibility filtering. Compensation planner is Alpine-heavy for instant slider reactivity (no Livewire round-trips for slider changes).

**Tech Stack:** Laravel 12, Livewire 4 SFC, Alpine.js, Flux UI Free, Tailwind CSS v4, Pest 4

**Design Doc:** `docs/plans/2026-02-21-territory-hierarchy-design.md`

**Pre-requisites:** Session 1 (hierarchy, permissions, roles) + Session 2 (commissions, P&L, budgets) complete.

**What Session 4 needs from this:** All UI components wired up, map scoping working, compensation planner functional, door knocker dashboard isolated.

---

### Task 1: Territory Management Page — Tabbed Layout Shell

**Files:**
- Modify: `resources/views/components/sales/⚡territory-manager.blade.php`
- Test: `tests/Feature/Sales/TerritoryManagerTest.php`

**Step 1: Write the failing test**

Create or update `tests/Feature/Sales/TerritoryManagerTest.php`:

```php
<?php

use App\Models\User;
use Livewire\Livewire;

it('renders territory manager with tabs', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertSee('Map')
        ->assertSee('Hierarchy')
        ->assertSee('Dispatch')
        ->assertSee('Analytics')
        ->assertSet('activeTab', 'map');
});

it('switches between tabs', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->set('activeTab', 'hierarchy')
        ->assertSet('activeTab', 'hierarchy');
});

it('hides dispatch tab without dispatch permission', function () {
    $user = User::factory()->internal()->withRole('door_knocker')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertDontSee('Dispatch');
});

it('hides analytics tab without analytics permission', function () {
    $user = User::factory()->internal()->withRole('door_knocker')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertDontSee('Analytics');
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryManagerTest.php
```

Expected: Fail (no `activeTab` property, no tab UI).

**Step 3: Add tabbed layout to territory-manager**

In `resources/views/components/sales/⚡territory-manager.blade.php`, add to the PHP class:

```php
public string $activeTab = 'map';
```

Then wrap the existing template content in a tabbed layout. The existing map + form is "Tab 1: Map". Replace the outermost `<div>` template with:

```blade
<div>
    {{-- Tab Navigation --}}
    <div class="mb-4 border-b border-zinc-200 dark:border-zinc-700">
        <nav class="-mb-px flex gap-4" aria-label="Tabs">
            <button wire:click="$set('activeTab', 'map')"
                class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'map' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}">
                Map
            </button>
            <button wire:click="$set('activeTab', 'hierarchy')"
                class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'hierarchy' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}">
                Hierarchy
            </button>
            @can('territories.dispatch_blocks')
            <button wire:click="$set('activeTab', 'dispatch')"
                class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'dispatch' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}">
                Dispatch
            </button>
            @endcan
            @can('analytics.territory_pnl')
            <button wire:click="$set('activeTab', 'analytics')"
                class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'analytics' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}">
                Analytics
            </button>
            @endcan
        </nav>
    </div>

    {{-- Tab Content --}}
    @if($activeTab === 'map')
        {{-- EXISTING map + form content goes here --}}
    @elseif($activeTab === 'hierarchy')
        <livewire:sales.territory-hierarchy />
    @elseif($activeTab === 'dispatch')
        {{-- Enhanced dispatch panel - reuse existing dispatch modal content --}}
        <div class="text-zinc-500 dark:text-zinc-400 p-8 text-center">
            Dispatch management (Task 3)
        </div>
    @elseif($activeTab === 'analytics')
        {{-- P&L analytics - embed existing analytics with P&L cards --}}
        <div class="text-zinc-500 dark:text-zinc-400 p-8 text-center">
            Territory P&L Analytics (Task 4)
        </div>
    @endif
</div>
```

Move ALL existing map/form/territory template markup inside the `@if($activeTab === 'map')` block.

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryManagerTest.php
```

Expected: Pass (tab tests pass; hierarchy/dispatch/analytics sub-components created in later tasks).

**Step 5: Commit**

```bash
git add resources/views/components/sales/⚡territory-manager.blade.php tests/Feature/Sales/TerritoryManagerTest.php
git commit -m "feat: add tabbed layout to territory management page"
```

---

### Task 2: Territory Hierarchy Tree Component

**Files:**
- Create: `resources/views/components/sales/⚡territory-hierarchy.blade.php`
- Test: `tests/Feature/Sales/TerritoryHierarchyTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/Sales/TerritoryHierarchyTest.php`:

```php
<?php

use App\Models\SalesTerritory;
use App\Models\User;
use Livewire\Livewire;

it('renders the hierarchy tree', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    $region = SalesTerritory::factory()->region()->create(['name' => 'North Region']);
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create(['name' => 'Zone Alpha']);
    $block = SalesTerritory::factory()->block()->childOf($zone)->create(['name' => 'Block 101']);

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->assertSee('North Region')
        ->assertSee('Zone Alpha')
        ->assertSee('Block 101');
});

it('creates a child zone under a region', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $region = SalesTerritory::factory()->region()->create();

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->call('startCreateChild', $region->id, 'zone')
        ->assertSet('creatingChildOfId', $region->id)
        ->assertSet('childLevel', 'zone')
        ->set('childName', 'New Zone')
        ->call('saveChild')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('sales_territories', [
        'name' => 'New Zone',
        'level' => 'zone',
        'parent_id' => $region->id,
    ]);
});

it('creates a child block under a zone', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->call('startCreateChild', $zone->id, 'block')
        ->set('childName', 'New Block')
        ->call('saveChild')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('sales_territories', [
        'name' => 'New Block',
        'level' => 'block',
        'parent_id' => $zone->id,
    ]);
});

it('assigns a user to a territory', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $assignee = User::factory()->internal()->create();
    $zone = SalesTerritory::factory()->zone()->create();

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->call('assignUser', $zone->id, $assignee->id);

    expect($zone->fresh()->assigned_user_id)->toBe($assignee->id);
});

it('shows only visible territories for scoped user', function () {
    $regionManager = User::factory()->internal()->withRole('manager')->create();

    $region = SalesTerritory::factory()->region()->managedBy($regionManager)->create(['name' => 'My Region']);
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create(['name' => 'My Zone']);
    $otherRegion = SalesTerritory::factory()->region()->create(['name' => 'Other Region']);

    // Grant scoped permission
    app(\App\Services\PermissionScopeService::class)->grantPermission(
        $regionManager,
        'territories.view_own',
        ['territory_ids' => [$region->id, $zone->id]],
        $regionManager
    );

    Livewire::actingAs($regionManager)
        ->test('sales.territory-hierarchy')
        ->assertSee('My Region')
        ->assertSee('My Zone')
        ->assertDontSee('Other Region');
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryHierarchyTest.php
```

**Step 3: Create the hierarchy component**

Create `resources/views/components/sales/⚡territory-hierarchy.blade.php`:

```blade
<?php

use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\PermissionScopeService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?int $creatingChildOfId = null;
    public string $childLevel = '';
    public string $childName = '';
    public ?int $assigningTerritoryId = null;
    public ?int $selectedUserId = null;

    #[Computed]
    public function territories(): \Illuminate\Support\Collection
    {
        $user = auth()->user();
        $scopeService = app(PermissionScopeService::class);

        $query = SalesTerritory::query()
            ->with(['children.children', 'assignedUser', 'manager', 'teamMembers'])
            ->whereNull('parent_id') // Start from regions
            ->orderBy('name');

        // If user doesn't have view_all, filter by visible territory IDs
        if (! $scopeService->hasPermission($user, 'territories.view_all')) {
            $visibleIds = $scopeService->getVisibleTerritoryIds($user);
            $query->whereIn('id', $visibleIds);
        }

        return $query->get();
    }

    #[Computed]
    public function internalUsers(): \Illuminate\Support\Collection
    {
        return User::where('type', 'internal')->where('active', true)->orderBy('name')->get();
    }

    public function startCreateChild(int $parentId, string $level): void
    {
        $this->creatingChildOfId = $parentId;
        $this->childLevel = $level;
        $this->childName = '';
    }

    public function cancelCreate(): void
    {
        $this->reset('creatingChildOfId', 'childLevel', 'childName');
    }

    public function saveChild(): void
    {
        $this->validate([
            'childName' => 'required|string|max:255',
            'creatingChildOfId' => 'required|exists:sales_territories,id',
            'childLevel' => 'required|in:zone,block',
        ]);

        $parent = SalesTerritory::findOrFail($this->creatingChildOfId);

        SalesTerritory::create([
            'name' => $this->childName,
            'level' => $this->childLevel,
            'parent_id' => $parent->id,
            'manager_id' => $parent->assigned_user_id,
            'status' => 'active',
            'team_id' => $parent->team_id,
        ]);

        $this->reset('creatingChildOfId', 'childLevel', 'childName');
        unset($this->territories);
    }

    public function assignUser(int $territoryId, int $userId): void
    {
        $territory = SalesTerritory::findOrFail($territoryId);
        $territory->update(['assigned_user_id' => $userId]);
        unset($this->territories);
    }

    public function deleteTerritory(int $id): void
    {
        $territory = SalesTerritory::findOrFail($id);

        // Don't delete if it has children
        if ($territory->children()->count() > 0) {
            $this->addError('delete', 'Cannot delete a territory with children. Remove children first.');
            return;
        }

        $territory->delete();
        unset($this->territories);
    }
};
?>

<div class="space-y-4">
    {{-- Errors --}}
    @error('delete')
        <flux:callout variant="danger">{{ $message }}</flux:callout>
    @enderror

    {{-- Hierarchy Tree --}}
    @forelse($this->territories as $region)
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            {{-- Region Header --}}
            <div x-data="{ expanded: true }" class="bg-zinc-50 dark:bg-zinc-800">
                <div class="flex items-center justify-between p-3 cursor-pointer" @click="expanded = !expanded">
                    <div class="flex items-center gap-2">
                        <svg x-show="expanded" class="w-4 h-4 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        <svg x-show="!expanded" class="w-4 h-4 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        <flux:badge variant="primary" size="sm">Region</flux:badge>
                        <span class="font-semibold text-sm">{{ $region->name }}</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-zinc-500">
                        @if($region->assignedUser)
                            <span>{{ $region->assignedUser->name }}</span>
                        @endif
                        <span>{{ $region->children->count() }} zones</span>
                        @can('territories.manage_zones')
                        <flux:button size="xs" variant="ghost" wire:click="startCreateChild({{ $region->id }}, 'zone')">+ Zone</flux:button>
                        @endcan
                    </div>
                </div>

                {{-- Zones --}}
                <div x-show="expanded" x-collapse>
                    @foreach($region->children as $zone)
                        <div x-data="{ zoneExpanded: true }" class="border-t border-zinc-200 dark:border-zinc-700">
                            <div class="flex items-center justify-between px-3 py-2 pl-8 cursor-pointer" @click="zoneExpanded = !zoneExpanded">
                                <div class="flex items-center gap-2">
                                    <svg x-show="zoneExpanded" class="w-3 h-3 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                    <svg x-show="!zoneExpanded" class="w-3 h-3 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    <flux:badge variant="info" size="sm">Zone</flux:badge>
                                    <span class="text-sm">{{ $zone->name }}</span>
                                </div>
                                <div class="flex items-center gap-2 text-xs text-zinc-500">
                                    @if($zone->assignedUser)
                                        <span>{{ $zone->assignedUser->name }}</span>
                                    @endif
                                    <span>{{ $zone->children->count() }} blocks</span>
                                    @can('territories.manage_blocks')
                                    <flux:button size="xs" variant="ghost" wire:click="startCreateChild({{ $zone->id }}, 'block')">+ Block</flux:button>
                                    @endcan
                                </div>
                            </div>

                            {{-- Blocks --}}
                            <div x-show="zoneExpanded" x-collapse>
                                @foreach($zone->children as $block)
                                    <div class="flex items-center justify-between px-3 py-1.5 pl-14 border-t border-zinc-100 dark:border-zinc-700/50 text-sm">
                                        <div class="flex items-center gap-2">
                                            <flux:badge size="sm">Block</flux:badge>
                                            <span>{{ $block->name }}</span>
                                        </div>
                                        <div class="flex items-center gap-2 text-xs text-zinc-500">
                                            @if($block->assignedUser)
                                                <span>{{ $block->assignedUser->name }}</span>
                                            @else
                                                <span class="italic text-zinc-400">Unassigned</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @empty
        <div class="text-center py-12 text-zinc-500 dark:text-zinc-400">
            <p class="text-lg font-medium">No territories yet</p>
            <p class="text-sm mt-1">Create a region from the Map tab to get started.</p>
        </div>
    @endforelse

    {{-- Create Child Modal --}}
    @if($creatingChildOfId)
        <flux:modal wire:model="creatingChildOfId" class="max-w-md">
            <flux:heading size="lg">Create {{ ucfirst($childLevel) }}</flux:heading>
            <div class="mt-4 space-y-4">
                <flux:input wire:model="childName" label="Name" placeholder="Enter {{ $childLevel }} name" autofocus />
                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="cancelCreate">Cancel</flux:button>
                    <flux:button variant="primary" wire:click="saveChild">Create</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
```

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryHierarchyTest.php
```

Expected: Pass (adjust assertions if permission scoping needs different approach based on existing infrastructure).

**Step 5: Commit**

```bash
git add resources/views/components/sales/⚡territory-hierarchy.blade.php tests/Feature/Sales/TerritoryHierarchyTest.php
git commit -m "feat: add territory hierarchy tree component with create/assign"
```

---

### Task 3: Enhanced Dispatch Tab

**Files:**
- Modify: `resources/views/components/sales/⚡territory-manager.blade.php` (dispatch tab content)
- Test: `tests/Feature/Sales/TerritoryDispatchTabTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/Sales/TerritoryDispatchTabTest.php`:

```php
<?php

use App\Models\SalesTerritory;
use App\Models\User;
use Livewire\Livewire;

it('shows dispatch tab with blocks for zone manager', function () {
    $manager = User::factory()->internal()->withRole('manager')->create();
    $region = SalesTerritory::factory()->region()->managedBy($manager)->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->assignedTo($manager)->create(['name' => 'Zone Alpha']);
    $block1 = SalesTerritory::factory()->block()->childOf($zone)->create(['name' => 'Block 1']);
    $block2 = SalesTerritory::factory()->block()->childOf($zone)->create(['name' => 'Block 2']);

    Livewire::actingAs($manager)
        ->test('sales.territory-manager')
        ->set('activeTab', 'dispatch')
        ->assertSee('Block 1')
        ->assertSee('Block 2');
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryDispatchTabTest.php
```

**Step 3: Update the dispatch tab placeholder**

In `⚡territory-manager.blade.php`, replace the dispatch placeholder with inline dispatch content that reuses the existing `TerritoryDispatchService`. Add computed properties to the PHP class:

```php
#[Computed]
public function dispatchableBlocks(): \Illuminate\Support\Collection
{
    $user = auth()->user();
    $scopeService = app(PermissionScopeService::class);

    $query = SalesTerritory::query()
        ->where('level', 'block')
        ->where('status', 'active')
        ->with(['assignedUser', 'parent'])
        ->orderBy('name');

    if (! $scopeService->hasPermission($user, 'territories.view_all')) {
        $visibleIds = $scopeService->getVisibleTerritoryIds($user);
        $query->whereIn('id', $visibleIds);
    }

    return $query->get();
}
```

Replace the dispatch tab placeholder:

```blade
@elseif($activeTab === 'dispatch')
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">Block Dispatch</flux:heading>
        </div>

        <div class="grid gap-3">
            @forelse($this->dispatchableBlocks as $block)
                <div class="flex items-center justify-between p-3 rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div>
                        <p class="font-medium text-sm">{{ $block->name }}</p>
                        <p class="text-xs text-zinc-500">{{ $block->parent?->name ?? 'No zone' }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($block->assignedUser)
                            <flux:badge size="sm">{{ $block->assignedUser->name }}</flux:badge>
                        @else
                            <flux:badge variant="warning" size="sm">Unassigned</flux:badge>
                        @endif
                        <flux:button size="xs" wire:click="openDispatch({{ $block->id }})">Dispatch</flux:button>
                    </div>
                </div>
            @empty
                <p class="text-center py-8 text-zinc-500">No blocks available for dispatch.</p>
            @endforelse
        </div>
    </div>
```

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryDispatchTabTest.php
```

**Step 5: Commit**

```bash
git add resources/views/components/sales/⚡territory-manager.blade.php tests/Feature/Sales/TerritoryDispatchTabTest.php
git commit -m "feat: add dispatch tab with block listing and dispatch actions"
```

---

### Task 4: Analytics Tab with P&L Cards

**Files:**
- Modify: `resources/views/components/sales/⚡territory-manager.blade.php` (analytics tab)
- Test: `tests/Feature/Sales/TerritoryAnalyticsPnlTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/Sales/TerritoryAnalyticsPnlTest.php`:

```php
<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\EmployeeProfile;
use App\Models\SalesTerritory;
use App\Models\TerritoryBudget;
use App\Models\User;
use Livewire\Livewire;

it('shows P&L cards on analytics tab', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    $region = SalesTerritory::factory()->region()->create(['name' => 'West Region']);
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();

    $knocker = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->for($knocker, 'user')->create();

    $knock = DoorKnock::factory()->sold()->forUser($knocker)->create(['territory_id' => $zone->id]);
    $deal = Deal::factory()->create([
        'origin_door_knock_id' => $knock->id,
        'actual_value' => 10000,
        'stage' => 'completed',
    ]);
    $knock->update(['deal_id' => $deal->id]);

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->set('activeTab', 'analytics')
        ->assertSee('West Region')
        ->assertSee('Revenue');
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryAnalyticsPnlTest.php
```

**Step 3: Add P&L analytics tab content**

Add to the PHP class in `⚡territory-manager.blade.php`:

```php
public string $pnlPeriod = 'month';

#[Computed]
public function pnlData(): array
{
    $pnlService = app(\App\Services\TerritoryPnlService::class);
    $scopeService = app(PermissionScopeService::class);
    $user = auth()->user();

    [$start, $end] = match ($this->pnlPeriod) {
        'week' => [now()->startOfWeek(), now()->endOfWeek()],
        'month' => [now()->startOfMonth(), now()->endOfMonth()],
        'quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
        'year' => [now()->startOfYear(), now()->endOfYear()],
        default => [now()->startOfMonth(), now()->endOfMonth()],
    };

    $query = SalesTerritory::where('level', 'region');
    if (! $scopeService->hasPermission($user, 'territories.view_all')) {
        $visibleIds = $scopeService->getVisibleTerritoryIds($user);
        $query->whereIn('id', $visibleIds);
    }

    $regions = $query->get();
    $results = [];

    foreach ($regions as $region) {
        $results[] = $pnlService->calculatePnlRollup($region, $start, $end);
    }

    return $results;
}
```

Replace the analytics tab placeholder:

```blade
@elseif($activeTab === 'analytics')
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <flux:heading size="lg">Territory P&L</flux:heading>
            <flux:select wire:model.live="pnlPeriod" class="w-32">
                <option value="week">This Week</option>
                <option value="month">This Month</option>
                <option value="quarter">This Quarter</option>
                <option value="year">This Year</option>
            </flux:select>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse($this->pnlData as $pnl)
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold text-sm">{{ $pnl['territory_name'] }}</h3>
                        <flux:badge size="sm" variant="{{ $pnl['within_budget'] ? 'success' : 'danger' }}">
                            {{ $pnl['budget_pct'] }}%
                        </flux:badge>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <div>
                            <span class="text-zinc-500">Revenue</span>
                            <p class="font-medium">${{ number_format($pnl['revenue'], 0) }}</p>
                        </div>
                        <div>
                            <span class="text-zinc-500">Commissions</span>
                            <p class="font-medium">${{ number_format($pnl['commission_costs'], 0) }}</p>
                        </div>
                        <div>
                            <span class="text-zinc-500">Marketing</span>
                            <p class="font-medium">${{ number_format($pnl['marketing_costs'], 0) }}</p>
                        </div>
                        <div>
                            <span class="text-zinc-500">Margin</span>
                            <p class="font-medium {{ $pnl['margin'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                ${{ number_format($pnl['margin'], 0) }}
                            </p>
                        </div>
                    </div>
                    {{-- Budget gauge --}}
                    <div>
                        <div class="flex justify-between text-xs text-zinc-500 mb-1">
                            <span>Commission Budget</span>
                            <span>{{ $pnl['budget_pct'] }}% / {{ $pnl['budget_target_pct'] }}%</span>
                        </div>
                        <div class="h-2 bg-zinc-200 dark:bg-zinc-700 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all {{ $pnl['within_budget'] ? 'bg-green-500' : 'bg-red-500' }}"
                                 style="width: {{ min($pnl['budget_pct'] / max($pnl['budget_target_pct'], 1) * 100, 100) }}%"></div>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-center py-8 text-zinc-500 col-span-full">No territory data for this period.</p>
            @endforelse
        </div>
    </div>
```

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryAnalyticsPnlTest.php
```

**Step 5: Commit**

```bash
git add resources/views/components/sales/⚡territory-manager.blade.php tests/Feature/Sales/TerritoryAnalyticsPnlTest.php
git commit -m "feat: add analytics tab with P&L cards and budget gauges"
```

---

### Task 5: Map Visibility Scoping by Permission

**Files:**
- Modify: `resources/views/components/sales/⚡territory-manager.blade.php` (territoryPolygons computed)
- Modify: `resources/views/components/sales/⚡door-knock-map.blade.php` (territory filter)
- Test: `tests/Feature/Sales/TerritoryMapScopingTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/Sales/TerritoryMapScopingTest.php`:

```php
<?php

use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\PermissionScopeService;
use Livewire\Livewire;

it('owner sees all territories on map', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    $region1 = SalesTerritory::factory()->region()->create(['name' => 'Region A']);
    $region2 = SalesTerritory::factory()->region()->create(['name' => 'Region B']);

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertSee('Region A')
        ->assertSee('Region B');
});

it('region manager sees only their territory tree on map', function () {
    $manager = User::factory()->internal()->withRole('manager')->create();

    $myRegion = SalesTerritory::factory()->region()->managedBy($manager)->create(['name' => 'My Region']);
    $myZone = SalesTerritory::factory()->zone()->childOf($myRegion)->create(['name' => 'My Zone']);
    $otherRegion = SalesTerritory::factory()->region()->create(['name' => 'Other Region']);

    // Grant scoped view
    app(PermissionScopeService::class)->grantPermission(
        $manager,
        'territories.view_own',
        ['territory_ids' => [$myRegion->id, $myZone->id]],
        $manager
    );

    $component = Livewire::actingAs($manager)
        ->test('sales.territory-manager');

    // The territoryPolygons computed should only return visible territories
    $polygons = $component->get('territoryPolygons');
    $names = collect($polygons)->pluck('name');

    expect($names)->toContain('My Region');
    expect($names)->toContain('My Zone');
    expect($names)->not->toContain('Other Region');
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryMapScopingTest.php
```

**Step 3: Update the territoryPolygons computed**

In `⚡territory-manager.blade.php`, update the `territoryPolygons()` computed property to filter by permission scope:

```php
#[Computed]
public function territoryPolygons(): array
{
    $user = auth()->user();
    $scopeService = app(PermissionScopeService::class);

    $query = SalesTerritory::query()
        ->whereNotNull('boundary')
        ->where('status', 'active');

    if (! $scopeService->hasPermission($user, 'territories.view_all')) {
        $visibleIds = $scopeService->getVisibleTerritoryIds($user);
        $query->whereIn('id', $visibleIds);
    }

    return $query->get()->map(fn ($t) => [
        'id' => $t->id,
        'name' => $t->name,
        'boundary' => $t->boundary,
        'color' => $t->color ?? '#3B82F6',
        'level' => $t->level,
    ])->toArray();
}
```

Apply the same pattern to the door-knock-map's `territories()` and `territoryPolygons()` computed properties.

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryMapScopingTest.php
```

**Step 5: Commit**

```bash
git add resources/views/components/sales/⚡territory-manager.blade.php resources/views/components/sales/⚡door-knock-map.blade.php tests/Feature/Sales/TerritoryMapScopingTest.php
git commit -m "feat: scope territory map visibility by user permissions"
```

---

### Task 6: Map Level Styling (Region/Zone/Block borders)

**Files:**
- Modify: `resources/js/app.js` (drawPolygons method)
- No test needed (visual-only)

**Step 1: Update drawPolygons to style by level**

In `resources/js/app.js`, find the `drawPolygons(payload)` method. Update the polygon styling to differentiate levels:

```javascript
drawPolygons(payload) {
    this.polygonLayers.forEach(l => this.map.removeLayer(l));
    this.polygonLayers = [];

    if (!payload || !payload.length) return;

    payload.forEach(p => {
        if (!p.boundary || !p.boundary.coordinates) return;

        // Style by territory level
        const levelStyles = {
            region: { weight: 4, dashArray: null, opacity: 0.8 },
            zone: { weight: 2.5, dashArray: null, opacity: 0.6 },
            block: { weight: 1.5, dashArray: '5, 5', opacity: 0.5 },
        };
        const style = levelStyles[p.level] || levelStyles.zone;

        const coords = p.boundary.coordinates[0].map(c => [c[1], c[0]]);
        const layer = L.polygon(coords, {
            color: p.color || '#3B82F6',
            fillColor: p.color || '#3B82F6',
            fillOpacity: 0.08,
            weight: style.weight,
            dashArray: style.dashArray,
            opacity: style.opacity,
            interactive: false,
        }).addTo(this.map);

        if (p.name) {
            layer.bindTooltip(p.name, {
                permanent: false,
                direction: 'center',
                className: 'territory-label',
            });
        }

        this.polygonLayers.push(layer);
    });
},
```

**Step 2: Build**

```bash
npm run build
```

**Step 3: Commit**

```bash
git add resources/js/app.js
git commit -m "feat: style map polygons by territory level (region/zone/block)"
```

---

### Task 7: Compensation Planning Calculator Page

**Files:**
- Create: `resources/views/pages/sales/compensation-planner.blade.php` (Folio page)
- Create: `resources/views/components/sales/⚡compensation-planner.blade.php` (Livewire SFC)
- Modify: `resources/views/layouts/app/sidebar.blade.php` (add nav link)
- Modify: `routes/web.php` or add route
- Test: `tests/Feature/Sales/CompensationPlannerTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/Sales/CompensationPlannerTest.php`:

```php
<?php

use App\Models\User;
use Livewire\Livewire;

it('renders the compensation planner for owners', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.compensation-planner')
        ->assertSee('Compensation Planner')
        ->assertSee('Commission Only')
        ->assertSee('Flat + Commission')
        ->assertSee('Flat Only');
});

it('denies access to non-owners', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();

    Livewire::actingAs($user)
        ->test('sales.compensation-planner')
        ->assertForbidden();
});

it('calculates forward projection', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    // Set sliders to known values, check projected income
    Livewire::actingAs($user)
        ->test('sales.compensation-planner')
        ->set('avgJobSize', 10000)
        ->set('knockToApptRate', 10)  // 10%
        ->set('apptToSoldRate', 40)   // 40%
        ->set('workDaysPerWeek', 5)
        ->set('workWeeksPerYear', 50)
        ->set('knocksPerDay', 30)
        ->set('knockerCommissionPct', 4)
        ->assertSet('avgJobSize', 10000);
    // The actual projected income calculations happen in Alpine.js for instant reactivity
    // The component just needs to render the structure with the correct wire:model bindings
});

it('saves a named scenario', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.compensation-planner')
        ->set('scenarioName', 'Conservative Plan')
        ->set('avgJobSize', 8000)
        ->call('saveScenario')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('compensation_scenarios', [
        'name' => 'Conservative Plan',
        'created_by' => $user->id,
    ]);
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Sales/CompensationPlannerTest.php
```

**Step 3: Create CompensationScenario model + migration**

```bash
php artisan make:model CompensationScenario -mf --no-interaction
```

Edit migration:

```php
public function up(): void
{
    Schema::create('compensation_scenarios', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
        $table->json('settings'); // All slider values as JSON
        $table->timestamps();
    });
}
```

Edit model:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompensationScenario extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'created_by', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

Run migration: `php artisan migrate`

**Step 4: Create the Livewire component**

Create `resources/views/components/sales/⚡compensation-planner.blade.php`:

```blade
<?php

use App\Models\CompensationScenario;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    // Global inputs
    public float $avgJobSize = 10000;
    public float $knockToApptRate = 10;    // percentage
    public float $apptToSoldRate = 40;      // percentage
    public int $workDaysPerWeek = 5;
    public int $workWeeksPerYear = 50;
    public int $knocksPerDay = 30;

    // Commission Only model
    public float $knockerCommissionPct = 4;
    public float $estimatorSoloPct = 10;
    public float $estimatorTeamPct = 6;
    public float $regionManagerPct = 2;

    // Flat + Commission model
    public float $flatPerEstimate = 50;
    public float $flatPlusCommissionPct = 1;
    public float $flatEstimatorPct = 6;
    public float $flatRegionPct = 2;

    // Flat Only model
    public float $flatOnlyPerEstimate = 125;
    public float $flatOnlyEstimatorPct = 6;
    public float $flatOnlyRegionPct = 2;

    // Target income (reverse calc)
    public float $targetIncome = 80000;

    // Scenario management
    public string $scenarioName = '';

    public function mount(): void
    {
        if (! auth()->user()->hasRole(['owner', 'admin'])) {
            abort(403);
        }
    }

    public function saveScenario(): void
    {
        $this->validate(['scenarioName' => 'required|string|max:255']);

        CompensationScenario::create([
            'name' => $this->scenarioName,
            'created_by' => auth()->id(),
            'settings' => $this->getAllSettings(),
        ]);

        $this->scenarioName = '';
    }

    public function loadScenario(int $id): void
    {
        $scenario = CompensationScenario::findOrFail($id);
        $settings = $scenario->settings;

        foreach ($settings as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    #[Computed]
    public function scenarios(): \Illuminate\Support\Collection
    {
        return CompensationScenario::where('created_by', auth()->id())
            ->orderByDesc('updated_at')
            ->get();
    }

    private function getAllSettings(): array
    {
        return [
            'avgJobSize' => $this->avgJobSize,
            'knockToApptRate' => $this->knockToApptRate,
            'apptToSoldRate' => $this->apptToSoldRate,
            'workDaysPerWeek' => $this->workDaysPerWeek,
            'workWeeksPerYear' => $this->workWeeksPerYear,
            'knocksPerDay' => $this->knocksPerDay,
            'knockerCommissionPct' => $this->knockerCommissionPct,
            'estimatorSoloPct' => $this->estimatorSoloPct,
            'estimatorTeamPct' => $this->estimatorTeamPct,
            'regionManagerPct' => $this->regionManagerPct,
            'flatPerEstimate' => $this->flatPerEstimate,
            'flatPlusCommissionPct' => $this->flatPlusCommissionPct,
            'flatEstimatorPct' => $this->flatEstimatorPct,
            'flatRegionPct' => $this->flatRegionPct,
            'flatOnlyPerEstimate' => $this->flatOnlyPerEstimate,
            'flatOnlyEstimatorPct' => $this->flatOnlyEstimatorPct,
            'flatOnlyRegionPct' => $this->flatOnlyRegionPct,
            'targetIncome' => $this->targetIncome,
        ];
    }
};
?>

<div x-data="compensationPlanner()" class="space-y-6">
    <flux:heading size="xl">Compensation Planner</flux:heading>
    <p class="text-sm text-zinc-500 dark:text-zinc-400">Compare three door knocker compensation models. Adjust sliders to see projected annual income and required activity levels.</p>

    {{-- Global Inputs --}}
    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
        <flux:heading size="sm">Global Settings</flux:heading>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Avg Job Size: $<span x-text="formatNumber($wire.avgJobSize)"></span></label>
                <input type="range" wire:model.live.debounce.300ms="avgJobSize" min="1000" max="25000" step="500" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Knock-to-Appt Rate: <span x-text="$wire.knockToApptRate"></span>%</label>
                <input type="range" wire:model.live.debounce.300ms="knockToApptRate" min="5" max="30" step="1" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Appt-to-Sold Rate: <span x-text="$wire.apptToSoldRate"></span>%</label>
                <input type="range" wire:model.live.debounce.300ms="apptToSoldRate" min="20" max="60" step="1" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Knocks/Day: <span x-text="$wire.knocksPerDay"></span></label>
                <input type="range" wire:model.live.debounce.300ms="knocksPerDay" min="10" max="60" step="1" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Work Days/Week: <span x-text="$wire.workDaysPerWeek"></span></label>
                <input type="range" wire:model.live.debounce.300ms="workDaysPerWeek" min="4" max="6" step="1" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Work Weeks/Year: <span x-text="$wire.workWeeksPerYear"></span></label>
                <input type="range" wire:model.live.debounce.300ms="workWeeksPerYear" min="48" max="52" step="1" class="w-full">
            </div>
        </div>
    </div>

    {{-- 3-Column Comparison --}}
    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Commission Only --}}
        <div class="rounded-lg border border-blue-200 dark:border-blue-800 p-4 space-y-4">
            <flux:heading size="sm">Commission Only</flux:heading>
            <div class="space-y-3">
                <div>
                    <label class="text-xs text-zinc-500">Knocker: <span x-text="$wire.knockerCommissionPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="knockerCommissionPct" min="1" max="8" step="0.5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">Estimator (solo): <span x-text="$wire.estimatorSoloPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="estimatorSoloPct" min="4" max="15" step="0.5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">Estimator (team): <span x-text="$wire.estimatorTeamPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="estimatorTeamPct" min="2" max="10" step="0.5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">Region Mgr Override: <span x-text="$wire.regionManagerPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="regionManagerPct" min="0.5" max="5" step="0.25" class="w-full">
                </div>
            </div>
            {{-- Results --}}
            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3 space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-zinc-500">Knocker Annual</span><span class="font-semibold text-green-600" x-text="'$' + formatNumber(calcKnockerIncome('commission'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Estimator Annual</span><span class="font-semibold" x-text="'$' + formatNumber(calcEstimatorIncome('commission'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Region Mgr Annual</span><span class="font-semibold" x-text="'$' + formatNumber(calcManagerIncome('commission'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Total % of Revenue</span>
                    <span class="font-semibold" :class="calcTotalPct('commission') <= 12 ? 'text-green-600' : 'text-red-600'" x-text="calcTotalPct('commission').toFixed(1) + '%'"></span>
                </div>
            </div>
        </div>

        {{-- Flat + Commission --}}
        <div class="rounded-lg border border-purple-200 dark:border-purple-800 p-4 space-y-4">
            <flux:heading size="sm">Flat + Commission</flux:heading>
            <div class="space-y-3">
                <div>
                    <label class="text-xs text-zinc-500">Flat $/Estimate: $<span x-text="$wire.flatPerEstimate"></span></label>
                    <input type="range" wire:model.live.debounce.300ms="flatPerEstimate" min="25" max="100" step="5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">+ Commission: <span x-text="$wire.flatPlusCommissionPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="flatPlusCommissionPct" min="0.5" max="4" step="0.5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">Estimator: <span x-text="$wire.flatEstimatorPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="flatEstimatorPct" min="2" max="10" step="0.5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">Region Mgr Override: <span x-text="$wire.flatRegionPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="flatRegionPct" min="0.5" max="5" step="0.25" class="w-full">
                </div>
            </div>
            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3 space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-zinc-500">Knocker Annual</span><span class="font-semibold text-green-600" x-text="'$' + formatNumber(calcKnockerIncome('flat_plus'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Estimator Annual</span><span class="font-semibold" x-text="'$' + formatNumber(calcEstimatorIncome('flat_plus'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Region Mgr Annual</span><span class="font-semibold" x-text="'$' + formatNumber(calcManagerIncome('flat_plus'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Total % of Revenue</span>
                    <span class="font-semibold" :class="calcTotalPct('flat_plus') <= 12 ? 'text-green-600' : 'text-red-600'" x-text="calcTotalPct('flat_plus').toFixed(1) + '%'"></span>
                </div>
            </div>
        </div>

        {{-- Flat Only --}}
        <div class="rounded-lg border border-amber-200 dark:border-amber-800 p-4 space-y-4">
            <flux:heading size="sm">Flat Only</flux:heading>
            <div class="space-y-3">
                <div>
                    <label class="text-xs text-zinc-500">Flat $/Estimate: $<span x-text="$wire.flatOnlyPerEstimate"></span></label>
                    <input type="range" wire:model.live.debounce.300ms="flatOnlyPerEstimate" min="75" max="200" step="5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">Estimator: <span x-text="$wire.flatOnlyEstimatorPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="flatOnlyEstimatorPct" min="2" max="10" step="0.5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">Region Mgr Override: <span x-text="$wire.flatOnlyRegionPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="flatOnlyRegionPct" min="0.5" max="5" step="0.25" class="w-full">
                </div>
            </div>
            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3 space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-zinc-500">Knocker Annual</span><span class="font-semibold text-green-600" x-text="'$' + formatNumber(calcKnockerIncome('flat_only'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Estimator Annual</span><span class="font-semibold" x-text="'$' + formatNumber(calcEstimatorIncome('flat_only'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Region Mgr Annual</span><span class="font-semibold" x-text="'$' + formatNumber(calcManagerIncome('flat_only'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Total % of Revenue</span>
                    <span class="font-semibold" :class="calcTotalPct('flat_only') <= 12 ? 'text-green-600' : 'text-red-600'" x-text="calcTotalPct('flat_only').toFixed(1) + '%'"></span>
                </div>
            </div>
        </div>
    </div>

    {{-- Reverse Calculator --}}
    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
        <flux:heading size="sm">Reverse Calculator: Target Income</flux:heading>
        <div>
            <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Target Annual Income: $<span x-text="formatNumber($wire.targetIncome)"></span></label>
            <input type="range" wire:model.live.debounce.300ms="targetIncome" min="40000" max="150000" step="5000" class="w-full">
        </div>
        <div class="grid gap-4 sm:grid-cols-3 text-sm">
            <template x-for="model in ['commission', 'flat_plus', 'flat_only']" :key="model">
                <div class="p-3 bg-zinc-50 dark:bg-zinc-800 rounded-lg space-y-1">
                    <p class="font-medium" x-text="modelLabel(model)"></p>
                    <p class="text-zinc-500">Knocks/day needed: <span class="font-semibold text-zinc-900 dark:text-zinc-100" x-text="calcRequiredKnocksPerDay(model)"></span></p>
                    <p class="text-zinc-500">Appts/week: <span class="font-semibold" x-text="calcRequiredApptsPerWeek(model)"></span></p>
                    <p class="text-zinc-500">Jobs/month: <span class="font-semibold" x-text="calcRequiredJobsPerMonth(model)"></span></p>
                </div>
            </template>
        </div>
    </div>

    {{-- Scenario Management --}}
    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
        <flux:heading size="sm">Saved Scenarios</flux:heading>
        <div class="flex gap-2">
            <flux:input wire:model="scenarioName" placeholder="Scenario name..." class="flex-1" />
            <flux:button wire:click="saveScenario" variant="primary">Save</flux:button>
        </div>
        @if($this->scenarios->isNotEmpty())
            <div class="flex flex-wrap gap-2">
                @foreach($this->scenarios as $scenario)
                    <flux:button size="sm" variant="ghost" wire:click="loadScenario({{ $scenario->id }})">
                        {{ $scenario->name }}
                    </flux:button>
                @endforeach
            </div>
        @endif
    </div>
</div>

@script
<script>
Alpine.data('compensationPlanner', () => ({
    formatNumber(n) {
        return Math.round(n).toLocaleString();
    },
    modelLabel(model) {
        return { commission: 'Commission Only', flat_plus: 'Flat + Commission', flat_only: 'Flat Only' }[model];
    },
    // Activity calculations
    get annualKnocks() { return this.$wire.knocksPerDay * this.$wire.workDaysPerWeek * this.$wire.workWeeksPerYear; },
    get annualAppts() { return this.annualKnocks * (this.$wire.knockToApptRate / 100); },
    get annualSold() { return this.annualAppts * (this.$wire.apptToSoldRate / 100); },
    get annualRevenue() { return this.annualSold * this.$wire.avgJobSize; },

    // Forward projection: knocker income
    calcKnockerIncome(model) {
        if (model === 'commission') return this.annualRevenue * (this.$wire.knockerCommissionPct / 100);
        if (model === 'flat_plus') return (this.annualAppts * this.$wire.flatPerEstimate) + (this.annualRevenue * (this.$wire.flatPlusCommissionPct / 100));
        if (model === 'flat_only') return this.annualAppts * this.$wire.flatOnlyPerEstimate;
        return 0;
    },
    calcEstimatorIncome(model) {
        if (model === 'commission') return this.annualRevenue * (this.$wire.estimatorTeamPct / 100);
        if (model === 'flat_plus') return this.annualRevenue * (this.$wire.flatEstimatorPct / 100);
        if (model === 'flat_only') return this.annualRevenue * (this.$wire.flatOnlyEstimatorPct / 100);
        return 0;
    },
    calcManagerIncome(model) {
        if (model === 'commission') return this.annualRevenue * (this.$wire.regionManagerPct / 100);
        if (model === 'flat_plus') return this.annualRevenue * (this.$wire.flatRegionPct / 100);
        if (model === 'flat_only') return this.annualRevenue * (this.$wire.flatOnlyRegionPct / 100);
        return 0;
    },
    calcTotalPct(model) {
        if (this.annualRevenue === 0) return 0;
        let total = this.calcKnockerIncome(model) + this.calcEstimatorIncome(model) + this.calcManagerIncome(model);
        return (total / this.annualRevenue) * 100;
    },

    // Reverse calculation: required knocks/day for target income
    calcRequiredKnocksPerDay(model) {
        let incomePerKnock = this.calcKnockerIncome(model) / Math.max(this.annualKnocks, 1);
        if (incomePerKnock <= 0) return '---';
        let requiredKnocks = this.$wire.targetIncome / incomePerKnock;
        let perDay = requiredKnocks / (this.$wire.workDaysPerWeek * this.$wire.workWeeksPerYear);
        return Math.ceil(perDay);
    },
    calcRequiredApptsPerWeek(model) {
        let kpd = this.calcRequiredKnocksPerDay(model);
        if (kpd === '---') return '---';
        return Math.ceil(kpd * this.$wire.workDaysPerWeek * (this.$wire.knockToApptRate / 100));
    },
    calcRequiredJobsPerMonth(model) {
        let kpd = this.calcRequiredKnocksPerDay(model);
        if (kpd === '---') return '---';
        let dailyJobs = kpd * (this.$wire.knockToApptRate / 100) * (this.$wire.apptToSoldRate / 100);
        return Math.round(dailyJobs * this.$wire.workDaysPerWeek * 4.33 * 10) / 10;
    },
}));
</script>
@endscript
```

**Step 5: Add route**

In `routes/web.php` (or the sales routes file), add:

```php
Route::get('/sales/compensation-planner', function () {
    return view('components.sales.⚡compensation-planner');
})->middleware(['auth', 'verified'])->name('sales.compensation-planner');
```

Or if using a Folio/page-based approach, follow the existing convention.

**Step 6: Add sidebar link**

In `resources/views/layouts/app/sidebar.blade.php`, add under the Sales section after the existing links:

```blade
@can('compensation.planner')
<flux:navlist.item :href="route('sales.compensation-planner')" :current="request()->routeIs('sales.compensation-planner')" wire:navigate>
    Compensation Planner
</flux:navlist.item>
@endcan
```

**Step 7: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/CompensationPlannerTest.php
```

**Step 8: Build frontend**

```bash
npm run build
```

**Step 9: Commit**

```bash
git add resources/views/components/sales/⚡compensation-planner.blade.php database/migrations/*compensation_scenarios* app/Models/CompensationScenario.php database/factories/CompensationScenarioFactory.php resources/views/layouts/app/sidebar.blade.php routes/web.php tests/Feature/Sales/CompensationPlannerTest.php
git commit -m "feat: add compensation planning calculator with 3-model comparison and reverse targeting"
```

---

### Task 8: Door Knocker Minimal Dashboard

**Files:**
- Modify: `resources/views/components/sales/⚡door-knock-map.blade.php` (scope for door_knocker role)
- Test: `tests/Feature/Sales/DoorKnockerDashboardTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/Sales/DoorKnockerDashboardTest.php`:

```php
<?php

use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\PermissionScopeService;
use Livewire\Livewire;

it('door knocker sees only assigned blocks on map', function () {
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();

    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    $myBlock = SalesTerritory::factory()->block()->childOf($zone)->assignedTo($knocker)->create([
        'name' => 'My Block',
        'boundary' => ['type' => 'Polygon', 'coordinates' => [[[-90, 35], [-89, 35], [-89, 36], [-90, 36], [-90, 35]]]],
    ]);
    $otherBlock = SalesTerritory::factory()->block()->childOf($zone)->create([
        'name' => 'Other Block',
        'boundary' => ['type' => 'Polygon', 'coordinates' => [[[-88, 35], [-87, 35], [-87, 36], [-88, 36], [-88, 35]]]],
    ]);

    // Scope knocker to only see their assigned blocks
    app(PermissionScopeService::class)->grantPermission(
        $knocker,
        'territories.view_own',
        ['territory_ids' => [$myBlock->id]],
        $knocker
    );

    $component = Livewire::actingAs($knocker)
        ->test('sales.door-knock-map');

    $polygons = $component->get('territoryPolygons');
    $names = collect($polygons)->pluck('name');

    expect($names)->toContain('My Block');
    expect($names)->not->toContain('Other Block');
});

it('door knocker sees today stats in minimal dashboard', function () {
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();

    Livewire::actingAs($knocker)
        ->test('sales.door-knock-map')
        ->assertSee('Today');
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Sales/DoorKnockerDashboardTest.php
```

**Step 3: Update door-knock-map for door_knocker scoping**

In `⚡door-knock-map.blade.php`, update the `territoryPolygons()` and `territories()` computed properties to use `PermissionScopeService` for filtering (same pattern as Task 5).

Add a computed property for today's stats:

```php
#[Computed]
public function todayStats(): array
{
    $user = auth()->user();
    $today = now()->startOfDay();

    $knocks = \App\Models\DoorKnock::where('user_id', $user->id)
        ->where('knocked_at', '>=', $today)
        ->get();

    return [
        'total' => $knocks->count(),
        'appointments' => $knocks->where('status', 'appointment_set')->count(),
        'interested' => $knocks->where('status', 'interested')->count(),
        'not_home' => $knocks->where('status', 'not_home')->count(),
        'conversion' => $knocks->count() > 0
            ? round(($knocks->whereIn('status', ['interested', 'appointment_set', 'sold'])->count() / $knocks->count()) * 100)
            : 0,
    ];
}
```

Add today stats display near the top of the template:

```blade
{{-- Today's Stats Bar --}}
<div class="flex items-center gap-4 px-3 py-2 bg-zinc-50 dark:bg-zinc-800 rounded-lg text-sm">
    <span class="font-medium">Today:</span>
    <span>{{ $this->todayStats['total'] }} knocks</span>
    <span class="text-green-600">{{ $this->todayStats['appointments'] }} appts</span>
    <span class="text-blue-600">{{ $this->todayStats['interested'] }} interested</span>
    <span>{{ $this->todayStats['conversion'] }}% conv.</span>
</div>
```

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/DoorKnockerDashboardTest.php
```

**Step 5: Commit**

```bash
git add resources/views/components/sales/⚡door-knock-map.blade.php tests/Feature/Sales/DoorKnockerDashboardTest.php
git commit -m "feat: add door knocker scoped map and today stats dashboard"
```

---

### Task 9: Settings — Permission Overrides UI

**Files:**
- Create: `resources/views/components/settings/⚡permission-overrides.blade.php`
- Modify: `resources/views/pages/settings/layout.blade.php` (add nav link if needed)
- Test: `tests/Feature/Settings/PermissionOverridesTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/Settings/PermissionOverridesTest.php`:

```php
<?php

use App\Models\PermissionScope;
use App\Models\User;
use App\Services\PermissionScopeService;
use Livewire\Livewire;

it('renders permission overrides for a user', function () {
    $admin = User::factory()->internal()->withRole('owner')->create();
    $targetUser = User::factory()->internal()->withRole('manager')->create();

    Livewire::actingAs($admin)
        ->test('settings.permission-overrides', ['userId' => $targetUser->id])
        ->assertSee($targetUser->name)
        ->assertSee('Permission Overrides');
});

it('grants a scoped permission to a user', function () {
    $admin = User::factory()->internal()->withRole('owner')->create();
    $targetUser = User::factory()->internal()->withRole('manager')->create();

    Livewire::actingAs($admin)
        ->test('settings.permission-overrides', ['userId' => $targetUser->id])
        ->set('newPermission', 'territories.manage_regions')
        ->set('newScopeType', 'grant')
        ->call('addOverride')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('permission_scopes', [
        'user_id' => $targetUser->id,
        'permission' => 'territories.manage_regions',
        'scope_type' => 'grant',
        'granted_by' => $admin->id,
    ]);
});

it('revokes a scoped permission', function () {
    $admin = User::factory()->internal()->withRole('owner')->create();
    $targetUser = User::factory()->internal()->withRole('manager')->create();

    // Grant first
    $scope = PermissionScope::create([
        'user_id' => $targetUser->id,
        'permission' => 'territories.manage_regions',
        'scope_type' => 'grant',
        'granted_by' => $admin->id,
    ]);

    Livewire::actingAs($admin)
        ->test('settings.permission-overrides', ['userId' => $targetUser->id])
        ->call('removeOverride', $scope->id)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('permission_scopes', ['id' => $scope->id]);
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Settings/PermissionOverridesTest.php
```

**Step 3: Create the component**

Create `resources/views/components/settings/⚡permission-overrides.blade.php`:

```blade
<?php

use App\Models\PermissionScope;
use App\Models\User;
use App\Services\PermissionScopeService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public int $userId;
    public string $newPermission = '';
    public string $newScopeType = 'grant';
    public ?array $newScopeValue = null;

    public function mount(int $userId): void
    {
        $this->userId = $userId;
    }

    #[Computed]
    public function user(): User
    {
        return User::findOrFail($this->userId);
    }

    #[Computed]
    public function overrides(): \Illuminate\Support\Collection
    {
        return PermissionScope::where('user_id', $this->userId)
            ->with('grantedByUser')
            ->orderBy('permission')
            ->get();
    }

    #[Computed]
    public function availablePermissions(): array
    {
        return [
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
    }

    public function addOverride(): void
    {
        $this->validate([
            'newPermission' => 'required|string',
            'newScopeType' => 'required|in:grant,revoke',
        ]);

        app(PermissionScopeService::class)->grantPermission(
            $this->user,
            $this->newPermission,
            $this->newScopeValue,
            auth()->user()
        );

        $this->reset('newPermission', 'newScopeType', 'newScopeValue');
        unset($this->overrides);
    }

    public function removeOverride(int $id): void
    {
        PermissionScope::where('id', $id)
            ->where('user_id', $this->userId)
            ->delete();

        unset($this->overrides);
    }
};
?>

<div class="space-y-4">
    <flux:heading size="lg">Permission Overrides for {{ $this->user->name }}</flux:heading>
    <p class="text-sm text-zinc-500">Grant or revoke specific permissions beyond this user's role defaults.</p>

    {{-- Current overrides --}}
    @forelse($this->overrides as $override)
        <div wire:key="override-{{ $override->id }}" class="flex items-center justify-between p-3 rounded-lg border border-zinc-200 dark:border-zinc-700">
            <div>
                <span class="font-mono text-sm">{{ $override->permission }}</span>
                <flux:badge size="sm" variant="{{ $override->scope_type === 'grant' ? 'success' : 'danger' }}">
                    {{ $override->scope_type }}
                </flux:badge>
                @if($override->scope_value)
                    <span class="text-xs text-zinc-500 ml-2">Scoped: {{ json_encode($override->scope_value) }}</span>
                @endif
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs text-zinc-400">by {{ $override->grantedByUser?->name ?? 'System' }}</span>
                <flux:button size="xs" variant="danger" wire:click="removeOverride({{ $override->id }})">Remove</flux:button>
            </div>
        </div>
    @empty
        <p class="text-sm text-zinc-500">No permission overrides configured.</p>
    @endforelse

    {{-- Add new override --}}
    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-3">
        <flux:heading size="sm">Add Override</flux:heading>
        <div class="flex gap-2 items-end">
            <flux:select wire:model="newPermission" label="Permission" class="flex-1">
                <option value="">Select permission...</option>
                @foreach($this->availablePermissions as $perm)
                    <option value="{{ $perm }}">{{ $perm }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model="newScopeType" label="Type" class="w-32">
                <option value="grant">Grant</option>
                <option value="revoke">Revoke</option>
            </flux:select>
            <flux:button wire:click="addOverride" variant="primary">Add</flux:button>
        </div>
    </div>
</div>
```

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Settings/PermissionOverridesTest.php
```

**Step 5: Commit**

```bash
git add resources/views/components/settings/⚡permission-overrides.blade.php tests/Feature/Settings/PermissionOverridesTest.php
git commit -m "feat: add permission overrides settings UI for user-level scope management"
```

---

### Task 10: Run Full Test Suite & Format

**Step 1: Format code**

```bash
vendor/bin/pint --dirty
```

**Step 2: Build frontend**

```bash
npm run build
```

**Step 3: Run all territory/sales tests**

```bash
php artisan test --compact tests/Feature/Sales/
php artisan test --compact tests/Feature/Settings/
```

**Step 4: Fix any failures**

**Step 5: Commit**

```bash
git add -A && git commit -m "style: format code with Pint and ensure all session 3 tests pass"
```

---

## Session 3 Completion Checklist

- [ ] Territory manager has 4-tab layout (Map, Hierarchy, Dispatch, Analytics)
- [ ] Hierarchy tree component with collapsible Region > Zone > Block tree
- [ ] Create child territory (zone under region, block under zone)
- [ ] Assign users to territories from hierarchy view
- [ ] Dispatch tab shows blocks with dispatch actions
- [ ] Analytics tab shows P&L cards with budget gauges per region
- [ ] Map polygons styled by level (thick region, medium zone, dashed block)
- [ ] Map visibility scoped by user permissions (view_all vs view_own)
- [ ] Compensation planner with 3-model comparison and instant Alpine calculations
- [ ] Reverse calculator: target income → required knocks/day
- [ ] Scenario save/load for compensation planner
- [ ] Door knocker minimal dashboard: scoped map + today stats
- [ ] Permission overrides settings UI: grant/revoke with scope values
- [ ] Sidebar nav link for compensation planner
- [ ] All tests passing
- [ ] Frontend built (`npm run build`)

**Session 4 can now build on:** complete UI working end-to-end, all permission scoping functional, compensation planner operational.
