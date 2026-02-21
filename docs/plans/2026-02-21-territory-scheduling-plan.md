# Territory-Aware Scheduling System Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add map layer toggles, smart territory-focused map loading, and a zone-manager scheduling system that ties territory block assignments into employee calendars with meet-up pins and navigation links.

**Architecture:** Extend the existing `Schedule` model with territory-specific fields (`territory_id`, `schedule_type`, `meet_address`, `meet_lat`, `meet_lng`). Add a new `⚡territory-schedule.blade.php` SFC component for the drag-drop weekly calendar. Wire territory schedules into `UnifiedCalendarService` so they appear in the planner. Replace the exclusive map filter with independent level toggles.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Alpine.js, Leaflet, Flux UI, Pest 4

**Branch:** `feature/territory-hierarchy-session4` (continue existing)

---

## Task 1: Map Layer Toggle Checkboxes

Replace the exclusive `filterLevel` string with independent `visibleLevels` array so users can toggle each hierarchy level on/off independently.

**Files:**
- Modify: `resources/views/components/sales/⚡territory-manager.blade.php`
- Modify: `tests/Feature/Sales/TerritoryManagerTest.php`

### Step 1: Update PHP properties and logic

In `⚡territory-manager.blade.php` PHP section:

1. Replace `public string $filterLevel = 'all';` with `public array $visibleLevels = ['region', 'zone', 'block'];`
2. Remove `updatedFilterLevel()` lifecycle hook
3. Add `toggleLevel(string $level)` method:
```php
public function toggleLevel(string $level): void
{
    if (in_array($level, $this->visibleLevels)) {
        $this->visibleLevels = array_values(array_diff($this->visibleLevels, [$level]));
    } else {
        $this->visibleLevels[] = $level;
    }
    unset($this->territoryPolygons);
    $this->dispatch('territory-polygons', polygons: $this->territoryPolygons);
}
```
4. Update `territoryPolygons()` computed — replace the `filterLevel` block:
```php
// Before:
if ($this->filterLevel !== 'all') {
    $query->where('level', $this->filterLevel);
}
// After:
if (count($this->visibleLevels) < 3) {
    $query->whereIn('level', $this->visibleLevels);
}
```

### Step 2: Update template filter bar

Replace the filter button `@foreach` (around line 441) with toggle checkboxes:
```blade
<div class="flex items-center gap-3 mb-3">
    <flux:text size="sm" class="text-zinc-500 mr-1">{{ __('Show:') }}</flux:text>
    @foreach(['region' => __('Regions'), 'zone' => __('Zones'), 'block' => __('Blocks')] as $value => $label)
        <label class="flex items-center gap-1.5 cursor-pointer">
            <input
                type="checkbox"
                wire:click="toggleLevel('{{ $value }}')"
                {{ in_array($value, $visibleLevels) ? 'checked' : '' }}
                class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500"
            />
            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ $label }}</span>
        </label>
    @endforeach
</div>
```

### Step 3: Update test

In `TerritoryManagerTest.php`, update the "filters territory polygons by level" test:
- Change `set('filterLevel', 'region')` → `call('toggleLevel', 'zone')` then `call('toggleLevel', 'block')` to hide those levels
- Update assertions accordingly
- Add test: "toggles individual map levels on and off"

### Step 4: Run tests, commit

```bash
php artisan test --compact tests/Feature/Sales/TerritoryManagerTest.php
vendor/bin/pint --dirty
git add -A && git commit -m "feat: replace exclusive map filter with independent level toggles"
```

---

## Task 2: Smart Map Focus on User's Assigned Territories

Make the map center on the user's assigned territories on load instead of GPS or US center.

**Files:**
- Modify: `resources/views/components/sales/⚡territory-manager.blade.php`
- Modify: `resources/js/app.js`

### Step 1: Update `mapCenter` computed to return bounds

Replace the existing `mapCenter()` computed with `mapBounds()` that returns the bounding box of the user's visible territories:

```php
#[Computed]
public function mapBounds(): ?array
{
    $user = auth()->user();
    $scopeService = app(PermissionScopeService::class);
    $visibleIds = $scopeService->getVisibleTerritoryIds($user);

    $territories = SalesTerritory::whereIn('id', $visibleIds)
        ->whereNotNull('boundary')
        ->where('status', 'active')
        ->get(['boundary']);

    if ($territories->isEmpty()) {
        return null;
    }

    $allLats = [];
    $allLngs = [];
    foreach ($territories as $t) {
        if (isset($t->boundary['coordinates'][0])) {
            foreach ($t->boundary['coordinates'][0] as $coord) {
                $allLngs[] = $coord[0];
                $allLats[] = $coord[1];
            }
        }
    }

    if (empty($allLats)) {
        return null;
    }

    return [
        [min($allLats), min($allLngs)], // SW corner
        [max($allLats), max($allLngs)], // NE corner
    ];
}
```

Keep the old `mapCenter` as a fallback for when there are no boundaries.

### Step 2: Update Alpine initialization in template

Change the `x-data="territoryMap({...})"` to pass bounds instead of center when available:

```blade
x-data="territoryMap({
    draw: {{ Auth::user()->can('sales_territories.manage') ? 'true' : 'false' }},
    geolocate: {{ $this->mapBounds ? 'false' : 'true' }},
    zoom: {{ $this->mapBounds ? 12 : 5 }},
    center: {{ json_encode($this->mapCenter) }},
    bounds: {{ json_encode($this->mapBounds) }},
    polygons: {{ json_encode($this->territoryPolygons) }}
})"
```

### Step 3: Update Alpine `initMap()` to use bounds

In `resources/js/app.js`, in the `initMap()` method, after creating the map and before drawing polygons, add:

```js
// Fit to user's territory bounds if provided (instead of GPS centering)
if (config.bounds) {
    this.map.fitBounds(config.bounds, { padding: [30, 30] });
}
```

This takes priority over the `config.center` + `config.zoom` which is already set via `setView()`.

### Step 4: Run tests, commit

```bash
php artisan test --compact tests/Feature/Sales/TerritoryManagerTest.php
npm run build
vendor/bin/pint --dirty
git add -A && git commit -m "feat: auto-focus map on user's assigned territories"
```

---

## Task 3: Extend Schedule Model with Territory Fields

Add territory-specific columns to the schedules table and update the model.

**Files:**
- Create: `database/migrations/XXXX_add_territory_fields_to_schedules_table.php`
- Modify: `app/Models/Schedule.php`
- Modify: `app/Models/SalesTerritory.php`
- Modify: `database/factories/ScheduleFactory.php`
- Create: `tests/Feature/Sales/TerritoryScheduleTest.php`

### Step 1: Create migration

```bash
php artisan make:migration add_territory_fields_to_schedules_table --no-interaction
```

Migration content:
```php
public function up(): void
{
    Schema::table('schedules', function (Blueprint $table) {
        $table->string('schedule_type')->default('job')->after('employee_profile_id');
        $table->foreignId('territory_id')->nullable()->after('schedule_type')
            ->constrained('sales_territories')->nullOnDelete();
        $table->string('meet_address', 500)->nullable()->after('notes');
        $table->decimal('meet_lat', 10, 7)->nullable()->after('meet_address');
        $table->decimal('meet_lng', 10, 7)->nullable()->after('meet_lat');
    });
}

public function down(): void
{
    Schema::table('schedules', function (Blueprint $table) {
        $table->dropForeign(['territory_id']);
        $table->dropColumn(['schedule_type', 'territory_id', 'meet_address', 'meet_lat', 'meet_lng']);
    });
}
```

Run: `php artisan migrate`

### Step 2: Update Schedule model

In `app/Models/Schedule.php`:

1. Add to `$fillable`: `'schedule_type'`, `'territory_id'`, `'meet_address'`, `'meet_lat'`, `'meet_lng'`
2. Add casts: `'meet_lat' => 'decimal:7'`, `'meet_lng' => 'decimal:7'`
3. Add relationship:
```php
public function territory(): BelongsTo
{
    return $this->belongsTo(SalesTerritory::class);
}
```
4. Add scopes:
```php
public function scopeTerritoryShifts(Builder $query): Builder
{
    return $query->where('schedule_type', 'territory');
}

public function scopeJobShifts(Builder $query): Builder
{
    return $query->where('schedule_type', 'job');
}
```
5. Add helper:
```php
public function isTerritoryShift(): bool
{
    return $this->schedule_type === 'territory';
}

public function getMeetUpUrl(): ?string
{
    if (! $this->meet_lat || ! $this->meet_lng) {
        return null;
    }

    return "https://www.google.com/maps/dir/?api=1&destination={$this->meet_lat},{$this->meet_lng}";
}
```

### Step 3: Add relationship to SalesTerritory

In `app/Models/SalesTerritory.php`, add:
```php
public function schedules(): HasMany
{
    return $this->hasMany(Schedule::class);
}
```

### Step 4: Update ScheduleFactory

Add factory states:
```php
public function territory(SalesTerritory $territory = null): static
{
    return $this->state(fn (array $attributes) => [
        'schedule_type' => 'territory',
        'territory_id' => $territory?->id ?? SalesTerritoryFactory::new(),
        'job_id' => null,
    ]);
}

public function withMeetUp(string $address = '123 Main St', float $lat = 39.8283, float $lng = -98.5795): static
{
    return $this->state(fn (array $attributes) => [
        'meet_address' => $address,
        'meet_lat' => $lat,
        'meet_lng' => $lng,
    ]);
}
```

### Step 5: Write tests

Create `tests/Feature/Sales/TerritoryScheduleTest.php`:
```php
use App\Models\Schedule;
use App\Models\SalesTerritory;
use App\Models\User;

it('creates a territory schedule with meet-up location', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $profile = $user->employeeProfile()->create([...factory defaults...]);
    $block = SalesTerritory::factory()->block()->create();

    $schedule = Schedule::factory()
        ->territory($block)
        ->withMeetUp('456 Oak Ave', 40.123, -95.456)
        ->create(['employee_profile_id' => $profile->id, 'created_by' => $user->id]);

    expect($schedule->schedule_type)->toBe('territory');
    expect($schedule->territory_id)->toBe($block->id);
    expect($schedule->meet_address)->toBe('456 Oak Ave');
    expect($schedule->isTerritoryShift())->toBeTrue();
    expect($schedule->getMeetUpUrl())->toContain('google.com/maps');
});

it('scopes territory shifts vs job shifts', function () {
    Schedule::factory()->count(2)->create(); // job shifts
    Schedule::factory()->count(3)->territory()->create();

    expect(Schedule::territoryShifts()->count())->toBe(3);
    expect(Schedule::jobShifts()->count())->toBe(2);
});

it('detects overlapping territory shifts', function () {
    $profile = \App\Models\EmployeeProfile::factory()->create();
    $block = SalesTerritory::factory()->block()->create();

    $shift1 = Schedule::factory()->territory($block)->create([
        'employee_profile_id' => $profile->id,
        'date' => '2026-03-01',
        'start_time' => '08:00',
        'end_time' => '16:00',
    ]);
    $shift2 = Schedule::factory()->territory($block)->create([
        'employee_profile_id' => $profile->id,
        'date' => '2026-03-01',
        'start_time' => '10:00',
        'end_time' => '18:00',
    ]);

    expect($shift1->overlaps($shift2))->toBeTrue();
});
```

### Step 6: Run tests, commit

```bash
php artisan test --compact tests/Feature/Sales/TerritoryScheduleTest.php
vendor/bin/pint --dirty
git add -A && git commit -m "feat: extend Schedule model with territory fields and meet-up location"
```

---

## Task 4: Territory Schedule Notification

Create the notification sent to employees when assigned to a territory block.

**Files:**
- Create: `app/Notifications/TerritoryScheduleAssigned.php`
- Test: `tests/Feature/Sales/TerritoryScheduleTest.php` (append)

### Step 1: Create notification

```bash
php artisan make:notification TerritoryScheduleAssigned --no-interaction
```

Follow the pattern from `app/Notifications/TaskAssigned.php`:

```php
class TerritoryScheduleAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Schedule $schedule
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        $territory = $this->schedule->territory;
        return [
            'schedule_id' => $this->schedule->id,
            'territory_id' => $territory?->id,
            'territory_name' => $territory?->name,
            'date' => $this->schedule->date->format('M j, Y'),
            'start_time' => $this->schedule->start_time,
            'meet_address' => $this->schedule->meet_address,
            'meet_url' => $this->schedule->getMeetUpUrl(),
            'scheduled_by' => $this->schedule->createdBy?->name,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
```

### Step 2: Test notification

Append to `TerritoryScheduleTest.php`:
```php
use Illuminate\Support\Facades\Notification;
use App\Notifications\TerritoryScheduleAssigned;

it('sends notification when territory schedule is created', function () {
    Notification::fake();
    $manager = User::factory()->internal()->withRole('owner')->create();
    $employee = User::factory()->internal()->withRole('door_knocker')->create();
    $profile = $employee->employeeProfile;
    $block = SalesTerritory::factory()->block()->create();

    $schedule = Schedule::create([
        'employee_profile_id' => $profile->id,
        'schedule_type' => 'territory',
        'territory_id' => $block->id,
        'date' => now()->addDay()->toDateString(),
        'start_time' => '08:00',
        'end_time' => '16:00',
        'meet_address' => '789 Elm St',
        'meet_lat' => 40.0,
        'meet_lng' => -95.0,
        'created_by' => $manager->id,
    ]);

    $employee->notify(new TerritoryScheduleAssigned($schedule));
    Notification::assertSentTo($employee, TerritoryScheduleAssigned::class);
});
```

### Step 3: Run tests, commit

```bash
php artisan test --compact tests/Feature/Sales/TerritoryScheduleTest.php
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add TerritoryScheduleAssigned notification"
```

---

## Task 5: Wire Territory Schedules into UnifiedCalendarService

Make territory assignments appear in employee planners.

**Files:**
- Modify: `app/Services/UnifiedCalendarService.php`
- Test: `tests/Feature/Sales/TerritoryScheduleTest.php` (append)

### Step 1: Add territory schedule source to `getEvents()`

In `UnifiedCalendarService.php`, add after the life commitment merge (line ~43):
```php
$events = $events->merge($this->getTerritoryScheduleEvents($user, $from, $to));
```

### Step 2: Add the new method

```php
protected function getTerritoryScheduleEvents(User $user, CarbonInterface $from, CarbonInterface $to): Collection
{
    if (! $user->employeeProfile) {
        return collect();
    }

    $schedules = \App\Models\Schedule::query()
        ->where('schedule_type', 'territory')
        ->where('employee_profile_id', $user->employeeProfile->id)
        ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
        ->whereNotIn('status', ['cancelled'])
        ->with('territory')
        ->get();

    return $schedules->map(function (\App\Models\Schedule $schedule) {
        $start = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->start_time);
        $end = Carbon::parse($schedule->date->format('Y-m-d') . ' ' . $schedule->end_time);
        $territory = $schedule->territory;

        return [
            'id' => "territory-schedule-{$schedule->id}",
            'title' => $territory?->name ?? 'Territory Shift',
            'start' => $start,
            'end' => $end,
            'type' => 'territory_shift',
            'source' => 'territory',
            'color' => $territory?->color ?? '#f97316',
            'subject_type' => null,
            'subject_id' => null,
            'url' => $schedule->getMeetUpUrl(),
            'meta' => [
                'schedule_id' => $schedule->id,
                'territory_id' => $territory?->id,
                'meet_address' => $schedule->meet_address,
                'meet_lat' => $schedule->meet_lat,
                'meet_lng' => $schedule->meet_lng,
                'status' => $schedule->status,
            ],
        ];
    });
}
```

### Step 3: Test calendar integration

```php
it('shows territory schedules in unified calendar', function () {
    $user = User::factory()->internal()->withRole('door_knocker')->create();
    $block = SalesTerritory::factory()->block()->create(['name' => 'Oak Street Block']);

    Schedule::factory()->territory($block)->create([
        'employee_profile_id' => $user->employeeProfile->id,
        'date' => today()->addDay()->toDateString(),
        'start_time' => '08:00',
        'end_time' => '16:00',
        'meet_address' => '100 Oak St',
    ]);

    $service = app(UnifiedCalendarService::class);
    $events = $service->getEventsForDate($user, today()->addDay());

    expect($events)->toHaveCount(1);
    expect($events->first()['title'])->toBe('Oak Street Block');
    expect($events->first()['source'])->toBe('territory');
    expect($events->first()['meta']['meet_address'])->toBe('100 Oak St');
});
```

### Step 4: Run tests, commit

```bash
php artisan test --compact tests/Feature/Sales/TerritoryScheduleTest.php
vendor/bin/pint --dirty
git add -A && git commit -m "feat: wire territory schedules into UnifiedCalendarService"
```

---

## Task 6: Territory Schedule Calendar Component

Build the zone-manager weekly scheduling UI as a new Livewire SFC.

**Files:**
- Create: `resources/views/components/sales/⚡territory-schedule.blade.php`
- Modify: `resources/views/components/sales/⚡territory-manager.blade.php` (add Schedule tab)
- Test: `tests/Feature/Sales/TerritoryScheduleTest.php` (append)

### Step 1: Create the scheduling component

Create `resources/views/components/sales/⚡territory-schedule.blade.php` as a Livewire SFC.

**PHP section — properties and methods:**

```php
<?php
use App\Models\SalesTerritory;
use App\Models\Schedule;
use App\Models\User;
use App\Notifications\TerritoryScheduleAssigned;
use App\Services\PermissionScopeService;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public string $weekStart = '';

    // Schedule form
    public bool $showScheduleModal = false;
    public ?string $selectedDate = null;
    public ?int $selectedUserId = null;
    public ?int $selectedTerritoryId = null;
    public string $startTime = '08:00';
    public string $endTime = '16:00';
    public ?string $meetAddress = null;
    public ?float $meetLat = null;
    public ?float $meetLng = null;
    public ?string $scheduleNotes = null;

    // Multi-employee
    public array $selectedUserIds = [];

    public function mount(): void
    {
        $this->weekStart = now()->startOfWeek()->format('Y-m-d');
    }

    public function prevWeek(): void
    {
        $this->weekStart = Carbon::parse($this->weekStart)->subWeek()->format('Y-m-d');
        unset($this->weekSchedules);
    }

    public function nextWeek(): void
    {
        $this->weekStart = Carbon::parse($this->weekStart)->addWeek()->format('Y-m-d');
        unset($this->weekSchedules);
    }

    #[Computed]
    public function weekDays(): array
    {
        $start = Carbon::parse($this->weekStart);
        return collect(range(0, 6))->map(fn ($i) => $start->copy()->addDays($i))->toArray();
    }

    #[Computed]
    public function managedBlocks(): \Illuminate\Support\Collection
    {
        $user = auth()->user();
        $scopeService = app(PermissionScopeService::class);
        $visibleIds = $scopeService->getVisibleTerritoryIds($user);

        return SalesTerritory::whereIn('id', $visibleIds)
            ->where('level', 'block')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'color']);
    }

    #[Computed]
    public function teamMembers(): \Illuminate\Support\Collection
    {
        $user = auth()->user();
        $scopeService = app(PermissionScopeService::class);
        $visibleIds = $scopeService->getVisibleTerritoryIds($user);

        // Get users who are team members of any visible territory
        return User::whereHas('territoryTeams', function ($q) use ($visibleIds) {
                $q->whereIn('sales_territories.id', $visibleIds);
            })
            ->where('is_active', true)
            ->with('employeeProfile')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function weekSchedules(): \Illuminate\Support\Collection
    {
        $start = Carbon::parse($this->weekStart);
        $end = $start->copy()->addDays(6);
        $teamMemberProfileIds = $this->teamMembers
            ->pluck('employeeProfile.id')
            ->filter()
            ->toArray();

        if (empty($teamMemberProfileIds)) {
            return collect();
        }

        return Schedule::territoryShifts()
            ->whereIn('employee_profile_id', $teamMemberProfileIds)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with(['territory', 'employeeProfile.user'])
            ->get();
    }

    public function openScheduleModal(?string $date = null, ?int $userId = null): void
    {
        $this->reset(['selectedTerritoryId', 'startTime', 'endTime', 'meetAddress', 'meetLat', 'meetLng', 'scheduleNotes', 'selectedUserIds']);
        $this->selectedDate = $date;
        $this->selectedUserId = $userId;
        $this->startTime = '08:00';
        $this->endTime = '16:00';
        if ($userId) {
            $this->selectedUserIds = [$userId];
        }
        $this->showScheduleModal = true;
    }

    public function setMeetUpPin(float $lat, float $lng): void
    {
        $this->meetLat = $lat;
        $this->meetLng = $lng;
    }

    public function saveSchedule(): void
    {
        $this->validate([
            'selectedDate' => 'required|date',
            'selectedTerritoryId' => 'required|exists:sales_territories,id',
            'selectedUserIds' => 'required|array|min:1',
            'selectedUserIds.*' => 'exists:users,id',
            'startTime' => 'required',
            'endTime' => 'required',
        ]);

        $creator = auth()->user();

        foreach ($this->selectedUserIds as $userId) {
            $user = User::find($userId);
            if (! $user?->employeeProfile) {
                continue;
            }

            $schedule = Schedule::create([
                'employee_profile_id' => $user->employeeProfile->id,
                'schedule_type' => 'territory',
                'territory_id' => $this->selectedTerritoryId,
                'date' => $this->selectedDate,
                'start_time' => $this->startTime,
                'end_time' => $this->endTime,
                'meet_address' => $this->meetAddress,
                'meet_lat' => $this->meetLat,
                'meet_lng' => $this->meetLng,
                'notes' => $this->scheduleNotes,
                'created_by' => $creator->id,
            ]);

            $user->notify(new TerritoryScheduleAssigned($schedule));
        }

        $this->showScheduleModal = false;
        unset($this->weekSchedules);
        session()->flash('success', __('Schedule created for :count team members.', ['count' => count($this->selectedUserIds)]));
    }

    public function deleteSchedule(int $scheduleId): void
    {
        Schedule::findOrFail($scheduleId)->delete();
        unset($this->weekSchedules);
    }

    public function copyWeekForward(): void
    {
        $currentStart = Carbon::parse($this->weekStart);
        $nextStart = $currentStart->copy()->addWeek();

        $currentSchedules = $this->weekSchedules;
        $creator = auth()->user();

        foreach ($currentSchedules as $schedule) {
            $newDate = Carbon::parse($schedule->date)->addWeek();
            $user = $schedule->employeeProfile?->user;

            $newSchedule = Schedule::create([
                'employee_profile_id' => $schedule->employee_profile_id,
                'schedule_type' => 'territory',
                'territory_id' => $schedule->territory_id,
                'date' => $newDate->toDateString(),
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'meet_address' => $schedule->meet_address,
                'meet_lat' => $schedule->meet_lat,
                'meet_lng' => $schedule->meet_lng,
                'notes' => $schedule->notes,
                'created_by' => $creator->id,
            ]);

            if ($user) {
                $user->notify(new TerritoryScheduleAssigned($newSchedule));
            }
        }

        $this->weekStart = $nextStart->format('Y-m-d');
        unset($this->weekSchedules);
        session()->flash('success', __('Week schedule copied forward.'));
    }
};
?>
```

**Template section** — weekly grid calendar:

The template renders:
- Week navigation header with prev/next arrows, date range label, "Copy Week Forward" button
- A grid: columns = days (Mon–Sun), rows = team members
- Each cell shows scheduled block badges with color + time
- Clicking an empty cell opens the schedule modal
- Clicking an existing schedule shows details with delete option
- Schedule modal with: block dropdown, start/end time, meet-up address, multi-employee checkboxes, optional mini-map for pin drop

*(Full template is ~200 lines of Blade — implement following the pattern of existing Livewire SFC components in the project. Use flux:modal, flux:select, flux:input, flux:button, flux:badge. Use Alpine x-data for the mini meet-up map with click-to-place pin.)*

### Step 2: Add Schedule tab to territory manager

In `⚡territory-manager.blade.php`, add a new tab button after Dispatch:
```blade
@can('territories.manage_zones')
<button wire:click="$set('activeTab', 'schedule')"
    class="px-3 py-2 text-sm font-medium border-b-2 transition-colors {{ $activeTab === 'schedule' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400' }}">
    Schedule
</button>
@endcan
```

Add the tab content:
```blade
@elseif($activeTab === 'schedule')
    <livewire:sales.territory-schedule />
```

### Step 3: Write tests

```php
it('renders territory schedule component for zone managers', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-schedule')
        ->assertSuccessful()
        ->assertSee('Mon')
        ->assertSee('Sun');
});

it('creates territory schedule for a team member', function () {
    Notification::fake();
    $manager = User::factory()->internal()->withRole('owner')->create();
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    $block = SalesTerritory::factory()->block()->childOf($zone)->create();

    // Add knocker as team member
    $block->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $manager->id]);

    Livewire::actingAs($manager)
        ->test('sales.territory-schedule')
        ->call('openScheduleModal', now()->addDay()->format('Y-m-d'), $knocker->id)
        ->set('selectedTerritoryId', $block->id)
        ->set('startTime', '08:00')
        ->set('endTime', '16:00')
        ->set('meetAddress', '123 Test St')
        ->set('selectedUserIds', [$knocker->id])
        ->call('saveSchedule')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('schedules', [
        'schedule_type' => 'territory',
        'territory_id' => $block->id,
        'employee_profile_id' => $knocker->employeeProfile->id,
        'meet_address' => '123 Test St',
    ]);

    Notification::assertSentTo($knocker, TerritoryScheduleAssigned::class);
});

it('copies week schedule forward', function () {
    Notification::fake();
    $manager = User::factory()->internal()->withRole('owner')->create();
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();
    $block = SalesTerritory::factory()->block()->create();
    $block->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $manager->id]);

    // Create a schedule for this week
    Schedule::factory()->territory($block)->create([
        'employee_profile_id' => $knocker->employeeProfile->id,
        'date' => now()->startOfWeek()->addDay()->toDateString(), // Tuesday
        'start_time' => '08:00',
        'end_time' => '16:00',
        'created_by' => $manager->id,
    ]);

    Livewire::actingAs($manager)
        ->test('sales.territory-schedule')
        ->call('copyWeekForward');

    // Should now have 2 schedules: original + copied
    expect(Schedule::territoryShifts()->count())->toBe(2);
});
```

### Step 4: Run tests, build, commit

```bash
php artisan test --compact tests/Feature/Sales/TerritoryScheduleTest.php
npm run build
vendor/bin/pint --dirty
git add -A && git commit -m "feat: add territory scheduling calendar component with meet-up pins"
```

---

## Task 7: Planner Calendar Territory Event Rendering

Enhance the planner to show territory assignments with navigation links.

**Files:**
- Modify: `resources/views/components/planner/⚡my-day.blade.php` (template section)

### Step 1: Update event rendering in month/week/day views

In the month grid event rendering (around line 857), enhance territory events:

After the existing event div in the `@foreach ($dayEvents->take(3) as $event)` loop, add territory-specific rendering:
```blade
@if ($event['source'] === 'territory' && ($event['meta']['meet_address'] ?? null))
    <div
        class="text-xs px-1 py-0.5 rounded truncate cursor-pointer"
        style="background-color: {{ $event['color'] }}20; color: {{ $event['color'] }}; border-left: 2px solid {{ $event['color'] }};"
        title="{{ $event['title'] }} @ {{ $event['meta']['meet_address'] }} ({{ $event['start_time'] }})"
        @if ($event['url']) onclick="window.open('{{ $event['url'] }}', '_blank')" @endif
    >
        {{ $event['start_time'] }} {{ Str::limit($event['title'], 12) }} 📍
    </div>
@else
    {{-- existing event rendering --}}
@endif
```

For the week view (around line 893), add meet-up address subtitle:
```blade
@if ($event['source'] === 'territory' && ($event['meta']['meet_address'] ?? null))
    <div class="truncate text-zinc-500 text-[10px]">
        📍 <a href="{{ $event['url'] }}" target="_blank" class="underline">{{ Str::limit($event['meta']['meet_address'], 25) }}</a>
    </div>
@endif
```

### Step 2: Run tests, commit

```bash
php artisan test --compact tests/Feature/PlannerTest.php
vendor/bin/pint --dirty
git add -A && git commit -m "feat: render territory assignments with navigation links in planner"
```

---

## Task 8: Final Integration Test & Cleanup

End-to-end verification that the full flow works.

**Files:**
- Test: `tests/Feature/Sales/TerritoryScheduleTest.php` (append)

### Step 1: Full flow integration test

```php
it('full flow: zone manager schedules block, employee sees it in calendar', function () {
    Notification::fake();

    // Setup hierarchy
    $manager = User::factory()->internal()->withRole('owner')->create();
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    $block = SalesTerritory::factory()->block()->childOf($zone)->create(['name' => 'Elm Street Block']);
    $block->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $manager->id]);

    // Manager creates schedule
    Livewire::actingAs($manager)
        ->test('sales.territory-schedule')
        ->call('openScheduleModal', now()->addDay()->format('Y-m-d'), $knocker->id)
        ->set('selectedTerritoryId', $block->id)
        ->set('startTime', '07:30')
        ->set('endTime', '15:30')
        ->set('meetAddress', '456 Elm St, Springfield')
        ->set('meetLat', 39.7817)
        ->set('meetLng', -89.6501)
        ->set('selectedUserIds', [$knocker->id])
        ->call('saveSchedule')
        ->assertHasNoErrors();

    // Verify schedule in DB
    $schedule = Schedule::where('territory_id', $block->id)->first();
    expect($schedule)->not->toBeNull();
    expect($schedule->meet_address)->toBe('456 Elm St, Springfield');

    // Verify it shows in knocker's calendar
    $service = app(UnifiedCalendarService::class);
    $events = $service->getEventsForDate($knocker, now()->addDay());

    $territoryEvent = $events->firstWhere('source', 'territory');
    expect($territoryEvent)->not->toBeNull();
    expect($territoryEvent['title'])->toBe('Elm Street Block');
    expect($territoryEvent['url'])->toContain('google.com/maps');
    expect($territoryEvent['meta']['meet_address'])->toBe('456 Elm St, Springfield');
});
```

### Step 2: Run full test suite, format

```bash
php artisan test --compact tests/Feature/Sales/TerritoryScheduleTest.php
php artisan test --compact tests/Feature/Sales/TerritoryManagerTest.php
php artisan test --compact tests/Feature/Sales/
npm run build
vendor/bin/pint --dirty
```

### Step 3: Commit

```bash
git add -A && git commit -m "test: add full-flow integration test for territory scheduling"
```

---

## Verification Checklist

```bash
# All territory tests
php artisan test --compact tests/Feature/Sales/

# Planner tests
php artisan test --compact tests/Feature/PlannerTest.php

# Build frontend
npm run build

# Format
vendor/bin/pint --dirty
```

## Summary of All Changes

| File | Action | Purpose |
|------|--------|---------|
| `⚡territory-manager.blade.php` | Modify | Layer toggles, smart bounds, Schedule tab |
| `resources/js/app.js` | Modify | Bounds fitting, layer toggle events |
| `app/Models/Schedule.php` | Modify | Territory fields, scopes, helpers |
| `app/Models/SalesTerritory.php` | Modify | Add schedules relationship |
| `database/factories/ScheduleFactory.php` | Modify | Territory + meetup factory states |
| `migration: add_territory_fields...` | Create | New columns on schedules table |
| `⚡territory-schedule.blade.php` | Create | Weekly scheduling calendar SFC |
| `TerritoryScheduleAssigned.php` | Create | Employee notification |
| `UnifiedCalendarService.php` | Modify | Territory schedule events source |
| `⚡my-day.blade.php` | Modify | Territory event rendering with nav |
| `TerritoryScheduleTest.php` | Create | All scheduling tests |
| `TerritoryManagerTest.php` | Modify | Update toggle tests |
