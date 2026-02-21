# Planner Delegation System Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Allow managers to grant configurable planner access (view/act/full) to other users, with time-limited or permanent delegations, audit trail, and in-planner management UI.

**Architecture:** A `planner_delegations` table stores explicit delegation records between users. A `PlannerDelegationService` centralizes authorization logic. The planner UI gains a user-selector dropdown and a delegation management flyout. Actions record `acted_by` for audit.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Spatie Permissions, Pest tests, Flux UI components, Tailwind v4

**Design Doc:** `docs/plans/2026-02-21-planner-delegation-design.md`

---

## Task 1: Migration — `planner_delegations` Table

**Files:**
- Create: `database/migrations/2026_02_21_XXXXXX_create_planner_delegations_table.php`

**Step 1: Create migration**

```bash
php artisan make:migration create_planner_delegations_table --no-interaction
```

**Step 2: Write the migration**

```php
Schema::create('planner_delegations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('grantor_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('delegate_id')->constrained('users')->cascadeOnDelete();
    $table->enum('access_level', ['view', 'act', 'full'])->default('view');
    $table->timestamp('starts_at')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->boolean('is_permanent')->default(false);
    $table->timestamps();

    $table->unique(['target_user_id', 'delegate_id']);
    $table->index('delegate_id');
    $table->index('expires_at');
});
```

**Step 3: Run migration**

```bash
php artisan migrate
```

**Step 4: Commit**

```bash
git add database/migrations/*planner_delegations*
git commit -m "feat: create planner_delegations migration"
```

---

## Task 2: Migration — Add `acted_by` to `activities` Table

**Files:**
- Create: `database/migrations/2026_02_21_XXXXXX_add_acted_by_to_activities_table.php`

**Step 1: Create migration**

```bash
php artisan make:migration add_acted_by_to_activities_table --no-interaction
```

**Step 2: Write the migration**

```php
Schema::table('activities', function (Blueprint $table) {
    $table->foreignId('acted_by')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
});
```

**Step 3: Run migration**

```bash
php artisan migrate
```

**Step 4: Update Activity model fillable**

In `app/Models/Activity.php`, add `'acted_by'` to the `$fillable` array (after `'assigned_to'`).

**Step 5: Add `actedBy` relationship to Activity model**

```php
public function actedBy(): BelongsTo
{
    return $this->belongsTo(User::class, 'acted_by');
}
```

**Step 6: Commit**

```bash
git add database/migrations/*acted_by* app/Models/Activity.php
git commit -m "feat: add acted_by column to activities for delegation audit"
```

---

## Task 3: Permissions — Seed `planner.delegate` and `planner.delegate_own`

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`

**Step 1: Add new permissions to the permissions array**

In `RolesAndPermissionsSeeder.php`, find the `// Planner / Activities` section (line 61-64) and add:

```php
// Planner / Activities
'planner.view',
'planner.view_all',
'planner.manage',
'planner.delegate',
'planner.delegate_own',
```

**Step 2: Assign `planner.delegate` to manager, admin roles**

- **Owner** (line 247-248): Already gets all permissions via `Permission::all()` — no change needed.
- **Admin** (find the `// Planner` line in admin's syncPermissions, ~line 266): Change to:
  ```
  'planner.view', 'planner.view_all', 'planner.manage', 'planner.delegate', 'planner.delegate_own',
  ```
- **Manager** (~line 351): Change to:
  ```
  'planner.view', 'planner.view_all', 'planner.manage', 'planner.delegate', 'planner.delegate_own',
  ```

**Step 3: Assign `planner.delegate_own` to all internal roles that have `planner.view`**

- **Estimator** (~line 423): Change to:
  ```
  'planner.view', 'planner.delegate_own',
  ```
- **HR Manager** (~line 468): Change to:
  ```
  'planner.view', 'planner.manage', 'planner.delegate_own',
  ```
- **Door-to-Door** (~line 503): Change to:
  ```
  'planner.view', 'planner.delegate_own',
  ```

**Step 4: Run the seeder**

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
```

**Step 5: Commit**

```bash
git add database/seeders/RolesAndPermissionsSeeder.php
git commit -m "feat: add planner.delegate and planner.delegate_own permissions"
```

---

## Task 4: Model — `PlannerDelegation`

**Files:**
- Create: `app/Models/PlannerDelegation.php`
- Create: `database/factories/PlannerDelegationFactory.php`

**Step 1: Create model with factory**

```bash
php artisan make:model PlannerDelegation -f --no-interaction
```

**Step 2: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlannerDelegation extends Model
{
    use HasFactory;

    public const ACCESS_LEVELS = [
        'view' => 'View Only',
        'act' => 'Act (Complete, Snooze, Prioritize)',
        'full' => 'Full Control',
    ];

    // Ordered by privilege level for >= comparisons
    public const ACCESS_HIERARCHY = ['view' => 0, 'act' => 1, 'full' => 2];

    protected $fillable = [
        'grantor_id',
        'target_user_id',
        'delegate_id',
        'access_level',
        'starts_at',
        'expires_at',
        'is_permanent',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_permanent' => 'boolean',
        ];
    }

    // ============ Relationships ============

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantor_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    // ============ Scopes ============

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('is_permanent', true)
                ->orWhere('expires_at', '>', now());
        })->where(function ($q) {
            $q->whereNull('starts_at')
                ->orWhere('starts_at', '<=', now());
        });
    }

    public function scopeForDelegate($query, User $user)
    {
        return $query->where('delegate_id', $user->id);
    }

    public function scopeForTarget($query, User $user)
    {
        return $query->where('target_user_id', $user->id);
    }

    // ============ Helpers ============

    public function isActive(): bool
    {
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->is_permanent) {
            return true;
        }

        return $this->expires_at && $this->expires_at->isFuture();
    }

    public function meetsAccessLevel(string $requiredLevel): bool
    {
        $hierarchy = self::ACCESS_HIERARCHY;

        return ($hierarchy[$this->access_level] ?? 0) >= ($hierarchy[$requiredLevel] ?? 0);
    }
}
```

**Step 3: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\PlannerDelegation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlannerDelegationFactory extends Factory
{
    protected $model = PlannerDelegation::class;

    public function definition(): array
    {
        return [
            'grantor_id' => User::factory()->internal(),
            'target_user_id' => User::factory()->internal(),
            'delegate_id' => User::factory()->internal(),
            'access_level' => 'view',
            'is_permanent' => true,
        ];
    }

    public function viewAccess(): static
    {
        return $this->state(['access_level' => 'view']);
    }

    public function actAccess(): static
    {
        return $this->state(['access_level' => 'act']);
    }

    public function fullAccess(): static
    {
        return $this->state(['access_level' => 'full']);
    }

    public function permanent(): static
    {
        return $this->state(['is_permanent' => true, 'expires_at' => null]);
    }

    public function temporary(?\Carbon\Carbon $until = null): static
    {
        return $this->state([
            'is_permanent' => false,
            'expires_at' => $until ?? now()->addWeek(),
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'is_permanent' => false,
            'expires_at' => now()->subDay(),
        ]);
    }
}
```

**Step 4: Commit**

```bash
git add app/Models/PlannerDelegation.php database/factories/PlannerDelegationFactory.php
git commit -m "feat: add PlannerDelegation model and factory"
```

---

## Task 5: Service — `PlannerDelegationService`

**Files:**
- Create: `app/Services/PlannerDelegationService.php`

**Step 1: Write the failing tests first**

Create `tests/Feature/PlannerDelegationTest.php`:

```bash
php artisan make:test PlannerDelegationTest --pest --no-interaction
```

Write these test cases in the file:

```php
<?php

use App\Models\Activity;
use App\Models\PlannerDelegation;
use App\Models\User;
use App\Services\PlannerDelegationService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('PlannerDelegationService', function () {
    it('creates a delegation when grantor has planner.delegate', function () {
        $manager = User::factory()->internal()->withRole('manager')->create();
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        $service = app(PlannerDelegationService::class);
        $delegation = $service->createDelegation(
            grantor: $manager,
            targetUser: $target,
            delegate: $delegate,
            accessLevel: 'act',
            isPermanent: true,
        );

        expect($delegation)->toBeInstanceOf(PlannerDelegation::class)
            ->and($delegation->access_level)->toBe('act')
            ->and($delegation->is_permanent)->toBeTrue();
    });

    it('allows user to delegate own planner with planner.delegate_own', function () {
        $user = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        $service = app(PlannerDelegationService::class);
        $delegation = $service->createDelegation(
            grantor: $user,
            targetUser: $user,
            delegate: $delegate,
            accessLevel: 'view',
            isPermanent: true,
        );

        expect($delegation->target_user_id)->toBe($user->id)
            ->and($delegation->delegate_id)->toBe($delegate->id);
    });

    it('rejects delegation when grantor lacks permission', function () {
        $user = User::factory()->internal()->withRole('door_to_door')->create();
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        $service = app(PlannerDelegationService::class);

        // door_to_door has planner.delegate_own but not planner.delegate
        // so they cannot delegate someone else's planner
        $service->createDelegation(
            grantor: $user,
            targetUser: $target,
            delegate: $delegate,
            accessLevel: 'view',
            isPermanent: true,
        );
    })->throws(\Illuminate\Auth\Access\AuthorizationException::class);

    it('rejects self-delegation (delegate == target)', function () {
        $manager = User::factory()->internal()->withRole('manager')->create();
        $user = User::factory()->internal()->withRole('estimator')->create();

        $service = app(PlannerDelegationService::class);

        $service->createDelegation(
            grantor: $manager,
            targetUser: $user,
            delegate: $user,
            accessLevel: 'view',
            isPermanent: true,
        );
    })->throws(\InvalidArgumentException::class);

    it('updates existing delegation instead of creating duplicate', function () {
        $manager = User::factory()->internal()->withRole('manager')->create();
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        $service = app(PlannerDelegationService::class);

        $first = $service->createDelegation(
            grantor: $manager,
            targetUser: $target,
            delegate: $delegate,
            accessLevel: 'view',
            isPermanent: true,
        );

        $second = $service->createDelegation(
            grantor: $manager,
            targetUser: $target,
            delegate: $delegate,
            accessLevel: 'full',
            isPermanent: true,
        );

        expect($first->id)->toBe($second->id)
            ->and($second->access_level)->toBe('full')
            ->and(PlannerDelegation::count())->toBe(1);
    });

    it('revokes a delegation', function () {
        $manager = User::factory()->internal()->withRole('manager')->create();
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        $service = app(PlannerDelegationService::class);
        $delegation = $service->createDelegation(
            grantor: $manager,
            targetUser: $target,
            delegate: $delegate,
            accessLevel: 'view',
            isPermanent: true,
        );

        $service->revokeDelegation($delegation->id, $manager);

        expect(PlannerDelegation::count())->toBe(0);
    });

    it('checks canActOnBehalf correctly', function () {
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        PlannerDelegation::factory()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
            'delegate_id' => $delegate->id,
            'access_level' => 'act',
            'is_permanent' => true,
        ]);

        $service = app(PlannerDelegationService::class);

        expect($service->canActOnBehalf($delegate, $target, 'view'))->toBeTrue()
            ->and($service->canActOnBehalf($delegate, $target, 'act'))->toBeTrue()
            ->and($service->canActOnBehalf($delegate, $target, 'full'))->toBeFalse();
    });

    it('returns false for expired delegations', function () {
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        PlannerDelegation::factory()->expired()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
            'delegate_id' => $delegate->id,
            'access_level' => 'full',
        ]);

        $service = app(PlannerDelegationService::class);

        expect($service->canActOnBehalf($delegate, $target, 'view'))->toBeFalse();
    });

    it('expires stale delegations', function () {
        $target = User::factory()->internal()->create();

        // Expired delegation
        PlannerDelegation::factory()->expired()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
        ]);

        // Active permanent delegation
        PlannerDelegation::factory()->permanent()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
        ]);

        $service = app(PlannerDelegationService::class);
        $deleted = $service->expireStale();

        expect($deleted)->toBe(1)
            ->and(PlannerDelegation::count())->toBe(1);
    });

    it('returns delegated users for delegate', function () {
        $delegate = User::factory()->internal()->withRole('estimator')->create();
        $target1 = User::factory()->internal()->create();
        $target2 = User::factory()->internal()->create();

        PlannerDelegation::factory()->permanent()->create([
            'grantor_id' => $target1->id,
            'target_user_id' => $target1->id,
            'delegate_id' => $delegate->id,
            'access_level' => 'act',
        ]);

        PlannerDelegation::factory()->permanent()->create([
            'grantor_id' => $target2->id,
            'target_user_id' => $target2->id,
            'delegate_id' => $delegate->id,
            'access_level' => 'view',
        ]);

        $service = app(PlannerDelegationService::class);
        $delegations = $service->getDelegationsFor($delegate);

        expect($delegations)->toHaveCount(2);
    });
});
```

**Step 2: Run tests — they should all fail (service doesn't exist yet)**

```bash
php artisan test --compact tests/Feature/PlannerDelegationTest.php
```

**Step 3: Write the service**

Create `app/Services/PlannerDelegationService.php`:

```php
<?php

namespace App\Services;

use App\Models\PlannerDelegation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class PlannerDelegationService
{
    public function createDelegation(
        User $grantor,
        User $targetUser,
        User $delegate,
        string $accessLevel,
        bool $isPermanent = false,
        ?Carbon $startsAt = null,
        ?Carbon $expiresAt = null,
    ): PlannerDelegation {
        // Cannot delegate to self
        if ($targetUser->id === $delegate->id) {
            throw new \InvalidArgumentException('Cannot delegate planner access to the same user.');
        }

        // Authorization: grantor must have planner.delegate OR be delegating own planner with planner.delegate_own
        $isOwnPlanner = $grantor->id === $targetUser->id;

        if ($isOwnPlanner) {
            if (! $grantor->can('planner.delegate_own')) {
                throw new AuthorizationException('You do not have permission to share your planner.');
            }
        } else {
            if (! $grantor->can('planner.delegate')) {
                throw new AuthorizationException('You do not have permission to delegate planner access for other users.');
            }
        }

        return PlannerDelegation::updateOrCreate(
            [
                'target_user_id' => $targetUser->id,
                'delegate_id' => $delegate->id,
            ],
            [
                'grantor_id' => $grantor->id,
                'access_level' => $accessLevel,
                'starts_at' => $startsAt,
                'expires_at' => $isPermanent ? null : $expiresAt,
                'is_permanent' => $isPermanent,
            ],
        );
    }

    public function revokeDelegation(int $delegationId, User $revoker): void
    {
        $delegation = PlannerDelegation::findOrFail($delegationId);

        // Can revoke if: revoker is the grantor, the target user, or has planner.delegate
        $canRevoke = $revoker->id === $delegation->grantor_id
            || $revoker->id === $delegation->target_user_id
            || $revoker->can('planner.delegate');

        if (! $canRevoke) {
            throw new AuthorizationException('You do not have permission to revoke this delegation.');
        }

        $delegation->delete();
    }

    /**
     * Get active delegations where $user is the delegate (whose planners can they see?).
     */
    public function getDelegationsFor(User $delegate): Collection
    {
        return PlannerDelegation::query()
            ->forDelegate($delegate)
            ->active()
            ->with('targetUser')
            ->get();
    }

    /**
     * Get active delegations on a target user's planner (who has access?).
     */
    public function getDelegatesFor(User $targetUser): Collection
    {
        return PlannerDelegation::query()
            ->forTarget($targetUser)
            ->active()
            ->with(['delegate', 'grantor'])
            ->get();
    }

    /**
     * Check if delegate can act on behalf of targetUser at the given access level.
     */
    public function canActOnBehalf(User $delegate, User $targetUser, string $requiredLevel = 'view'): bool
    {
        $delegation = PlannerDelegation::query()
            ->forDelegate($delegate)
            ->forTarget($targetUser)
            ->active()
            ->first();

        if (! $delegation) {
            return false;
        }

        return $delegation->meetsAccessLevel($requiredLevel);
    }

    /**
     * Delete expired non-permanent delegations. Returns count deleted.
     */
    public function expireStale(): int
    {
        return PlannerDelegation::query()
            ->where('is_permanent', false)
            ->where('expires_at', '<', now())
            ->delete();
    }
}
```

**Step 4: Run tests — they should all pass**

```bash
php artisan test --compact tests/Feature/PlannerDelegationTest.php
```

**Step 5: Commit**

```bash
git add app/Services/PlannerDelegationService.php tests/Feature/PlannerDelegationTest.php
git commit -m "feat: add PlannerDelegationService with full test coverage"
```

---

## Task 6: Scheduled Command — Expire Delegations

**Files:**
- Modify: `routes/console.php`

**Step 1: Add the scheduled command**

In `routes/console.php`, add after the existing scheduled commands:

```php
Schedule::call(function () {
    app(\App\Services\PlannerDelegationService::class)->expireStale();
})->daily()->name('planner:expire-delegations');
```

**Step 2: Commit**

```bash
git add routes/console.php
git commit -m "feat: schedule daily planner delegation expiry"
```

---

## Task 7: Integrate Delegation into Planner Actions

**Files:**
- Modify: `resources/views/components/planner/⚡my-day.blade.php`
- Modify: `app/Services/CommandCenterService.php`

**Step 1: Write the failing tests**

Add these to `tests/Feature/PlannerDelegationTest.php`:

```php
describe('Planner delegation actions', function () {
    it('allows delegate with act access to toggle complete on target users activity', function () {
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        PlannerDelegation::factory()->actAccess()->permanent()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
            'delegate_id' => $delegate->id,
        ]);

        $activity = Activity::factory()->create([
            'user_id' => $target->id,
            'assigned_to' => $target->id,
            'is_completed' => false,
        ]);

        Livewire::actingAs($delegate)
            ->test('planner.my-day')
            ->call('toggleComplete', $activity->id);

        $activity->refresh();
        expect($activity->is_completed)->toBeTrue()
            ->and($activity->acted_by)->toBe($delegate->id);
    });

    it('denies delegate with view-only access from toggling complete', function () {
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        PlannerDelegation::factory()->viewAccess()->permanent()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
            'delegate_id' => $delegate->id,
        ]);

        $activity = Activity::factory()->create([
            'user_id' => $target->id,
            'assigned_to' => $target->id,
            'is_completed' => false,
        ]);

        Livewire::actingAs($delegate)
            ->test('planner.my-day')
            ->call('toggleComplete', $activity->id);
    })->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    it('allows delegate with full access to create tasks for target user', function () {
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        PlannerDelegation::factory()->fullAccess()->permanent()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
            'delegate_id' => $delegate->id,
        ]);

        Livewire::actingAs($delegate)
            ->test('planner.my-day')
            ->set('viewingUserId', $target->id)
            ->call('openAddTask')
            ->set('taskTitle', 'Delegated Task')
            ->call('saveTask')
            ->assertHasNoErrors();

        expect(Activity::where('title', 'Delegated Task')
            ->where('user_id', $delegate->id)
            ->where('assigned_to', $target->id)
            ->exists())->toBeTrue();
    });
});
```

**Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact tests/Feature/PlannerDelegationTest.php --filter="delegation actions"
```

**Step 3: Update `activityQueryForAction` in `⚡my-day.blade.php`**

Replace the existing `activityQueryForAction` method with a delegation-aware version. Also add a `viewingUserId` property and the required `use` import for PlannerDelegationService.

Add a new public property at the top of the class (near the other filter properties):

```php
public ?int $viewingUserId = null;
```

Replace the `activityQueryForAction` method:

```php
/**
 * Base query for action methods. Checks:
 * 1. Own activities (creator or assignee)
 * 2. planner.view_all permission
 * 3. Active delegation with sufficient access level
 */
private function activityQueryForAction(string $requiredLevel = 'act'): \Illuminate\Database\Eloquent\Builder
{
    $user = auth()->user();

    // Own activities — always allowed
    // planner.view_all — unrestricted
    if ($user->can('planner.view_all')) {
        return Activity::query();
    }

    // Check delegation access
    $delegationService = app(\App\Services\PlannerDelegationService::class);
    $delegations = $delegationService->getDelegationsFor($user);
    $delegatedUserIds = $delegations
        ->filter(fn ($d) => $d->meetsAccessLevel($requiredLevel))
        ->pluck('target_user_id')
        ->toArray();

    return Activity::query()->where(function ($q) use ($user, $delegatedUserIds) {
        // Own activities
        $q->where(function ($q2) use ($user) {
            $q2->where('user_id', $user->id)
                ->orWhere('assigned_to', $user->id);
        });

        // Delegated activities
        if (! empty($delegatedUserIds)) {
            $q->orWhere(function ($q2) use ($delegatedUserIds) {
                $q2->whereIn('user_id', $delegatedUserIds)
                    ->orWhereIn('assigned_to', $delegatedUserIds);
            });
        }
    });
}
```

**Step 4: Update `toggleComplete` to set `acted_by`**

```php
public function toggleComplete(int $activityId): void
{
    $activity = $this->activityQueryForAction('act')->findOrFail($activityId);

    $user = auth()->user();
    $isOwnActivity = $activity->user_id === $user->id || $activity->assigned_to === $user->id;

    if ($activity->is_completed) {
        $activity->markAsIncomplete();
    } else {
        $activity->markAsCompleted();
        app(PriorityScoreService::class)->updateScore($activity->fresh());
    }

    // Set acted_by if this is a delegated action
    if (! $isOwnActivity && ! $user->can('planner.view_all')) {
        $activity->update(['acted_by' => $user->id]);
    }
}
```

Apply similar `acted_by` tracking to `setPriority`, `snoozeQuick`, and `confirmSnooze`.

**Step 5: Update `saveTask` to support creating tasks for the viewed user**

In the `saveTask` method, when `viewingUserId` is set and the user has a `full` delegation for that user, set `assigned_to` to the viewed user:

```php
// In saveTask(), replace the $assignedTo logic:
$assignedTo = $this->taskAssignedTo ?: auth()->id();

// If viewing another user's planner via delegation with full access
if ($this->viewingUserId && $this->viewingUserId !== auth()->id()) {
    $delegationService = app(\App\Services\PlannerDelegationService::class);
    $targetUser = User::find($this->viewingUserId);
    if ($targetUser && $delegationService->canActOnBehalf(auth()->user(), $targetUser, 'full')) {
        $assignedTo = $this->viewingUserId;
    }
}

if ($assignedTo !== auth()->id() && ! auth()->user()->can('planner.manage')) {
    // Check delegation instead of just rejecting
    $delegationService = app(\App\Services\PlannerDelegationService::class);
    $targetUser = User::find($assignedTo);
    if (! $targetUser || ! $delegationService->canActOnBehalf(auth()->user(), $targetUser, 'full')) {
        $assignedTo = auth()->id();
    }
}
```

**Step 6: Update CommandCenterService to support user-scoped view**

In `app/Services/CommandCenterService.php`, update `getActivityStream` to accept an optional `viewingUser` parameter. In the scope switch, add a new case or modify the existing logic:

After the switch statement for scope (~line 103), add:

```php
// If viewing a specific user's planner
if ($viewingUser = $filters['viewing_user'] ?? null) {
    $query->where(function ($q) use ($viewingUser) {
        $q->where('user_id', $viewingUser->id)
            ->orWhere('assigned_to', $viewingUser->id);
    });
}
```

**Step 7: Run tests**

```bash
php artisan test --compact tests/Feature/PlannerDelegationTest.php
```

**Step 8: Run existing planner tests to verify no regressions**

```bash
php artisan test --compact tests/Feature/PlannerTest.php --filter="task completion"
```

**Step 9: Commit**

```bash
git add resources/views/components/planner/⚡my-day.blade.php app/Services/CommandCenterService.php tests/Feature/PlannerDelegationTest.php
git commit -m "feat: integrate delegation into planner actions with audit trail"
```

---

## Task 8: Planner UI — User Selector Dropdown

**Files:**
- Modify: `resources/views/components/planner/⚡my-day.blade.php`

**Step 1: Add computed property for delegated users**

Add to the PHP section of `⚡my-day.blade.php`:

```php
#[Computed]
public function delegatedUsers(): \Illuminate\Support\Collection
{
    $user = auth()->user();

    if ($user->can('planner.view_all')) {
        return User::where('type', 'internal')
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->orderBy('name')
            ->get();
    }

    $delegations = app(\App\Services\PlannerDelegationService::class)->getDelegationsFor($user);

    return $delegations->map(fn ($d) => $d->targetUser)->unique('id');
}

#[Computed]
public function viewingUser(): ?User
{
    if (! $this->viewingUserId) {
        return null;
    }

    return User::find($this->viewingUserId);
}

#[Computed]
public function viewingAccessLevel(): ?string
{
    if (! $this->viewingUserId) {
        return null;
    }

    $user = auth()->user();

    if ($user->can('planner.view_all')) {
        return 'full';
    }

    $delegation = \App\Models\PlannerDelegation::query()
        ->forDelegate($user)
        ->forTarget(User::find($this->viewingUserId))
        ->active()
        ->first();

    return $delegation?->access_level;
}
```

**Step 2: Update the activities computed to pass viewingUser filter**

In the `activities()` computed property, add the viewing user filter:

```php
// After setting up $filters, before calling getActivityStream:
if ($this->viewingUserId) {
    $filters['viewing_user'] = User::find($this->viewingUserId);
}
```

**Step 3: Add user selector dropdown to the template**

In the filters section of the template (after the scope select, ~line 772-778), add:

```blade
@if ($this->delegatedUsers->isNotEmpty())
    <flux:select wire:model.live="viewingUserId" class="w-48">
        <option value="">{{ __('My Activities') }}</option>
        @foreach ($this->delegatedUsers as $member)
            <option value="{{ $member->id }}">{{ $member->name }}</option>
        @endforeach
    </flux:select>
@endif
```

**Step 4: Add viewing banner**

After the filters div, add a banner when viewing another user:

```blade
@if ($this->viewingUser)
    <div class="flex items-center gap-2 mb-4 px-3 py-2 rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800">
        <flux:icon name="eye" class="w-4 h-4 text-blue-600" />
        <flux:text class="text-sm text-blue-700 dark:text-blue-300">
            {{ __("Viewing :name's planner", ['name' => $this->viewingUser->name]) }}
        </flux:text>
        <flux:badge size="sm" color="blue">{{ ucfirst($this->viewingAccessLevel ?? 'view') }}</flux:badge>
        <flux:button size="sm" variant="ghost" wire:click="$set('viewingUserId', null)" icon="x-mark" class="ml-auto" />
    </div>
@endif
```

**Step 5: Commit**

```bash
git add resources/views/components/planner/⚡my-day.blade.php
git commit -m "feat: add user selector dropdown and viewing banner to planner"
```

---

## Task 9: Delegation Management Flyout

**Files:**
- Create: `resources/views/components/planner/⚡delegation-manager.blade.php`
- Modify: `resources/views/components/planner/⚡my-day.blade.php` (add button + modal)

**Step 1: Create the delegation manager SFC component**

Create `resources/views/components/planner/⚡delegation-manager.blade.php`:

This is a Livewire SFC that handles:
- Listing current delegations (on the user's own planner, and any they manage)
- Creating new delegations (user picker, access level, timeframe with presets + custom + permanent)
- Revoking delegations

```php
<?php

use App\Models\PlannerDelegation;
use App\Models\User;
use App\Services\PlannerDelegationService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?int $targetUserId = null;

    // Create form
    public bool $showCreateForm = false;
    public ?int $newDelegateId = null;
    public string $newAccessLevel = 'view';
    public string $newTimeframe = 'permanent';
    public ?string $newStartsAt = null;
    public ?string $newExpiresAt = null;

    public function mount(): void
    {
        $this->targetUserId = auth()->id();
    }

    #[Computed]
    public function canManageOthers(): bool
    {
        return auth()->user()->can('planner.delegate');
    }

    #[Computed]
    public function internalUsers()
    {
        return User::where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function delegations()
    {
        $targetUser = User::find($this->targetUserId ?? auth()->id());

        if (! $targetUser) {
            return collect();
        }

        return app(PlannerDelegationService::class)->getDelegatesFor($targetUser);
    }

    public function applyPreset(string $preset): void
    {
        $this->newTimeframe = $preset;
        $this->newStartsAt = null;

        $this->newExpiresAt = match ($preset) {
            'today' => now()->endOfDay()->format('Y-m-d\TH:i'),
            'this_week' => now()->endOfWeek()->format('Y-m-d\TH:i'),
            'two_weeks' => now()->addWeeks(2)->endOfDay()->format('Y-m-d\TH:i'),
            'one_month' => now()->addMonth()->endOfDay()->format('Y-m-d\TH:i'),
            default => null,
        };
    }

    public function saveDelegation(): void
    {
        $this->validate([
            'newDelegateId' => ['required', 'exists:users,id'],
            'newAccessLevel' => ['required', 'in:view,act,full'],
        ]);

        $targetUser = User::findOrFail($this->targetUserId ?? auth()->id());
        $delegate = User::findOrFail($this->newDelegateId);
        $isPermanent = $this->newTimeframe === 'permanent';

        app(PlannerDelegationService::class)->createDelegation(
            grantor: auth()->user(),
            targetUser: $targetUser,
            delegate: $delegate,
            accessLevel: $this->newAccessLevel,
            isPermanent: $isPermanent,
            expiresAt: ! $isPermanent && $this->newExpiresAt ? \Carbon\Carbon::parse($this->newExpiresAt) : null,
        );

        $this->reset(['newDelegateId', 'newAccessLevel', 'newTimeframe', 'newStartsAt', 'newExpiresAt']);
        $this->newAccessLevel = 'view';
        $this->newTimeframe = 'permanent';
        $this->showCreateForm = false;
        unset($this->delegations);

        session()->flash('delegation-success', __('Delegation created successfully.'));
    }

    public function revoke(int $delegationId): void
    {
        app(PlannerDelegationService::class)->revokeDelegation($delegationId, auth()->user());

        unset($this->delegations);
    }
}; ?>

<div class="space-y-6">
    <div>
        <flux:heading size="lg">{{ __('Manage Planner Access') }}</flux:heading>
        <flux:text class="mt-1 text-sm text-zinc-500">{{ __('Control who can view or act on planner activities.') }}</flux:text>
    </div>

    {{-- Target user selector (managers only) --}}
    @if ($this->canManageOthers)
        <flux:field>
            <flux:label>{{ __('Managing planner for') }}</flux:label>
            <flux:select wire:model.live="targetUserId">
                <option value="{{ auth()->id() }}">{{ __('My Planner') }}</option>
                @foreach ($this->internalUsers as $user)
                    @if ($user->id !== auth()->id())
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endif
                @endforeach
            </flux:select>
        </flux:field>
    @endif

    {{-- Flash message --}}
    @if (session('delegation-success'))
        <flux:callout variant="success">{{ session('delegation-success') }}</flux:callout>
    @endif

    {{-- Current delegations --}}
    <div>
        <div class="flex items-center justify-between mb-3">
            <flux:text class="font-semibold text-sm">{{ __('Current Access') }}</flux:text>
            <flux:button size="sm" variant="primary" icon="plus" wire:click="$set('showCreateForm', true)">
                {{ __('Grant Access') }}
            </flux:button>
        </div>

        @if ($this->delegations->isEmpty())
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 text-center">
                <flux:text class="text-sm text-zinc-500">{{ __('No one has delegated access to this planner.') }}</flux:text>
            </div>
        @else
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->delegations as $delegation)
                    <div wire:key="delegation-{{ $delegation->id }}" class="flex items-center justify-between p-3">
                        <div>
                            <flux:text class="font-medium text-sm">{{ $delegation->delegate->name }}</flux:text>
                            <div class="flex items-center gap-2 mt-0.5">
                                <flux:badge size="sm" :color="match($delegation->access_level) { 'view' => 'zinc', 'act' => 'blue', 'full' => 'green' }">
                                    {{ ucfirst($delegation->access_level) }}
                                </flux:badge>
                                @if ($delegation->is_permanent)
                                    <flux:text class="text-xs text-zinc-400">{{ __('Permanent') }}</flux:text>
                                @elseif ($delegation->expires_at)
                                    <flux:text class="text-xs text-zinc-400">
                                        {{ __('Expires') }} {{ $delegation->expires_at->diffForHumans() }}
                                    </flux:text>
                                @endif
                            </div>
                            <flux:text class="text-xs text-zinc-400 mt-0.5">
                                {{ __('Granted by') }} {{ $delegation->grantor->name }}
                            </flux:text>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="revoke({{ $delegation->id }})" wire:confirm="{{ __('Revoke this access?') }}" />
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Create delegation form --}}
    @if ($showCreateForm)
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
            <flux:heading size="md">{{ __('Grant Access') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('User') }}</flux:label>
                <flux:select wire:model="newDelegateId">
                    <option value="">{{ __('Select a user...') }}</option>
                    @foreach ($this->internalUsers as $user)
                        @if ($user->id !== ($this->targetUserId ?? auth()->id()))
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endif
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Access Level') }}</flux:label>
                <flux:select wire:model="newAccessLevel">
                    @foreach (\App\Models\PlannerDelegation::ACCESS_LEVELS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Duration') }}</flux:label>
                <div class="flex flex-wrap gap-2 mb-2">
                    @foreach (['today' => 'Today', 'this_week' => 'This Week', 'two_weeks' => '2 Weeks', 'one_month' => '1 Month', 'permanent' => 'Permanent'] as $key => $label)
                        <flux:button
                            size="sm"
                            :variant="$newTimeframe === $key ? 'primary' : 'ghost'"
                            wire:click="applyPreset('{{ $key }}')"
                        >
                            {{ __($label) }}
                        </flux:button>
                    @endforeach
                </div>

                @if ($newTimeframe !== 'permanent')
                    <flux:input wire:model="newExpiresAt" type="datetime-local" class="mt-2" />
                @endif
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showCreateForm', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="saveDelegation">{{ __('Grant Access') }}</flux:button>
            </div>
        </div>
    @endif
</div>
```

**Step 2: Add the flyout trigger and modal to `⚡my-day.blade.php`**

Add a new public property:

```php
public bool $showDelegationManager = false;
```

Add the button next to "Add Task" in the header (inside the `<div class="flex items-center gap-2">`):

```blade
@can('planner.delegate_own')
    <flux:button icon="user-group" variant="ghost" wire:click="$set('showDelegationManager', true)" size="sm">
        {{ __('Access') }}
    </flux:button>
@endcan
```

Add the modal at the bottom of the template (before the closing `</div>`):

```blade
{{-- Delegation Manager Flyout --}}
<flux:modal wire:model="showDelegationManager" variant="flyout" class="max-w-md">
    <livewire:is component="planner.delegation-manager" wire:key="delegation-manager" />
</flux:modal>
```

**Step 3: Write a test for the delegation manager component**

Add to `tests/Feature/PlannerDelegationTest.php`:

```php
describe('Delegation manager UI', function () {
    it('shows delegation manager button for users with planner.delegate_own', function () {
        $user = User::factory()->internal()->withRole('estimator')->create();

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSee('Access');
    });

    it('can create a delegation via the manager component', function () {
        $user = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        Livewire::actingAs($user)
            ->test('planner.delegation-manager')
            ->set('showCreateForm', true)
            ->set('newDelegateId', $delegate->id)
            ->set('newAccessLevel', 'act')
            ->set('newTimeframe', 'permanent')
            ->call('saveDelegation')
            ->assertHasNoErrors();

        expect(PlannerDelegation::where('target_user_id', $user->id)
            ->where('delegate_id', $delegate->id)
            ->where('access_level', 'act')
            ->exists())->toBeTrue();
    });

    it('can revoke a delegation via the manager component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $delegate = User::factory()->internal()->create();

        $delegation = PlannerDelegation::factory()->permanent()->create([
            'grantor_id' => $user->id,
            'target_user_id' => $user->id,
            'delegate_id' => $delegate->id,
            'access_level' => 'view',
        ]);

        Livewire::actingAs($user)
            ->test('planner.delegation-manager')
            ->call('revoke', $delegation->id);

        expect(PlannerDelegation::count())->toBe(0);
    });
});
```

**Step 4: Run all delegation tests**

```bash
php artisan test --compact tests/Feature/PlannerDelegationTest.php
```

**Step 5: Run existing planner tests for regressions**

```bash
php artisan test --compact tests/Feature/PlannerTest.php --filter="task completion"
```

**Step 6: Run Pint**

```bash
vendor/bin/pint --dirty
```

**Step 7: Commit**

```bash
git add resources/views/components/planner/⚡delegation-manager.blade.php resources/views/components/planner/⚡my-day.blade.php tests/Feature/PlannerDelegationTest.php
git commit -m "feat: add delegation management flyout and user selector UI"
```

---

## Task 10: Final Integration & Full Test Pass

**Step 1: Run the full delegation test suite**

```bash
php artisan test --compact tests/Feature/PlannerDelegationTest.php
```

**Step 2: Run the full planner test suite**

```bash
php artisan test --compact tests/Feature/PlannerTest.php
```

**Step 3: Run Pint on all dirty files**

```bash
vendor/bin/pint --dirty
```

**Step 4: Final commit if any formatting changes**

```bash
git add -A && git commit -m "chore: pint formatting"
```

---

## Summary of All Files

### New Files
| File | Purpose |
|------|---------|
| `database/migrations/*_create_planner_delegations_table.php` | Delegations table |
| `database/migrations/*_add_acted_by_to_activities_table.php` | Audit column |
| `app/Models/PlannerDelegation.php` | Model with scopes and helpers |
| `database/factories/PlannerDelegationFactory.php` | Test factory |
| `app/Services/PlannerDelegationService.php` | Core delegation logic |
| `resources/views/components/planner/⚡delegation-manager.blade.php` | Management UI |
| `tests/Feature/PlannerDelegationTest.php` | Full test coverage |

### Modified Files
| File | Change |
|------|--------|
| `database/seeders/RolesAndPermissionsSeeder.php` | Add `planner.delegate`, `planner.delegate_own` |
| `app/Models/Activity.php` | Add `acted_by` to fillable, add `actedBy()` relationship |
| `resources/views/components/planner/⚡my-day.blade.php` | User selector, delegation button, updated action methods |
| `app/Services/CommandCenterService.php` | Support `viewing_user` filter |
| `routes/console.php` | Schedule delegation expiry |
