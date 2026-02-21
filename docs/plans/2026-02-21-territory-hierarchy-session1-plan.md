# Territory Hierarchy Session 1: Roles, Permissions & Data Model

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add 3-level territory hierarchy (Region > Zone > Block), scoped user permissions, team member assignments, and a new `door_knocker` role — the foundation for the entire territory sales org system.

**Architecture:** Extend the existing `sales_territories` table with `parent_id`, `level`, and `manager_id` columns. Layer a `permission_scopes` table on top of Spatie for user-level grant/revoke overrides with visibility scoping. Add `territory_team_members` pivot for team management.

**Tech Stack:** Laravel 12, Spatie Permission, Pest 4, SQLite (tests) / MySQL (prod)

**Design Doc:** `docs/plans/2026-02-21-territory-hierarchy-design.md`

**Pre-requisites:** None — this is Session 1.

**What Session 2 needs from this:** The `level`, `parent_id`, `manager_id` columns on `sales_territories`, the `territory_team_members` pivot, the `permission_scopes` table, the `door_knocker` role, all new permissions seeded, and the `PermissionScopeService`.

---

### Task 1: Migration — Add Hierarchy Columns to sales_territories

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_hierarchy_to_sales_territories.php`
- Modify: `app/Models/SalesTerritory.php`
- Test: `tests/Feature/Sales/TerritoryHierarchyTest.php`

**Step 1: Create the migration**

```bash
php artisan make:migration add_hierarchy_to_sales_territories --no-interaction
```

Edit the migration file:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_territories', function (Blueprint $table) {
            $table->string('level')->default('zone')->after('status');
            $table->foreignId('parent_id')->nullable()->after('level')
                ->constrained('sales_territories')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->after('parent_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_territories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('level');
        });
    }
};
```

**Step 2: Run the migration**

```bash
php artisan migrate
```

**Step 3: Update SalesTerritory model**

Add to `$fillable` array in `app/Models/SalesTerritory.php`:

```php
// Add 'level', 'parent_id', 'manager_id' to existing $fillable array
```

Add new relationships and scope:

```php
public function parent(): BelongsTo
{
    return $this->belongsTo(SalesTerritory::class, 'parent_id');
}

public function children(): HasMany
{
    return $this->hasMany(SalesTerritory::class, 'parent_id');
}

public function manager(): BelongsTo
{
    return $this->belongsTo(User::class, 'manager_id');
}

public function descendants(): HasMany
{
    return $this->children()->with('descendants');
}

public function scopeRegions($query)
{
    return $query->where('level', 'region');
}

public function scopeZones($query)
{
    return $query->where('level', 'zone');
}

public function scopeBlocks($query)
{
    return $query->where('level', 'block');
}

public function scopeForLevel($query, string $level)
{
    return $query->where('level', $level);
}

/**
 * Get all descendant IDs (children + grandchildren) for permission scoping.
 */
public function getDescendantIds(): array
{
    $ids = [];
    foreach ($this->children as $child) {
        $ids[] = $child->id;
        $ids = array_merge($ids, $child->getDescendantIds());
    }
    return $ids;
}

/**
 * Get the full ancestor chain (parent, grandparent, etc).
 */
public function getAncestors(): \Illuminate\Support\Collection
{
    $ancestors = collect();
    $current = $this->parent;
    while ($current) {
        $ancestors->push($current);
        $current = $current->parent;
    }
    return $ancestors;
}
```

Add casts (in the `casts()` method if it exists, or add to `$casts`):

```php
'level' => 'string',
```

**Step 4: Update SalesTerritory factory**

In `database/factories/SalesTerritoryFactory.php`, add new states:

```php
public function region(): static
{
    return $this->state(fn () => ['level' => 'region']);
}

public function zone(): static
{
    return $this->state(fn () => ['level' => 'zone']);
}

public function block(): static
{
    return $this->state(fn () => ['level' => 'block']);
}

public function childOf(SalesTerritory $parent): static
{
    return $this->state(fn () => ['parent_id' => $parent->id]);
}

public function managedBy(User $user): static
{
    return $this->state(fn () => ['manager_id' => $user->id]);
}
```

**Step 5: Write the tests**

Create `tests/Feature/Sales/TerritoryHierarchyTest.php`:

```php
<?php

use App\Models\SalesTerritory;
use App\Models\User;

it('creates a region territory', function () {
    $region = SalesTerritory::factory()->region()->create(['name' => 'East Memphis']);

    expect($region->level)->toBe('region');
    expect($region->parent_id)->toBeNull();
});

it('creates a zone under a region', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();

    expect($zone->level)->toBe('zone');
    expect($zone->parent_id)->toBe($region->id);
    expect($zone->parent->id)->toBe($region->id);
    expect($region->children)->toHaveCount(1);
});

it('creates a block under a zone', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    $block = SalesTerritory::factory()->block()->childOf($zone)->create();

    expect($block->level)->toBe('block');
    expect($block->parent_id)->toBe($zone->id);
    expect($zone->children)->toHaveCount(1);
});

it('gets all descendant IDs from a region', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zone1 = SalesTerritory::factory()->zone()->childOf($region)->create();
    $zone2 = SalesTerritory::factory()->zone()->childOf($region)->create();
    $block1 = SalesTerritory::factory()->block()->childOf($zone1)->create();
    $block2 = SalesTerritory::factory()->block()->childOf($zone1)->create();

    $region->load('children.children');
    $ids = $region->getDescendantIds();

    expect($ids)->toContain($zone1->id, $zone2->id, $block1->id, $block2->id);
    expect($ids)->toHaveCount(4);
});

it('gets ancestor chain from a block', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    $block = SalesTerritory::factory()->block()->childOf($zone)->create();

    $ancestors = $block->getAncestors();

    expect($ancestors)->toHaveCount(2);
    expect($ancestors->first()->id)->toBe($zone->id);
    expect($ancestors->last()->id)->toBe($region->id);
});

it('assigns a manager to a territory', function () {
    $manager = User::factory()->internal()->create();
    $region = SalesTerritory::factory()->region()->managedBy($manager)->create();

    expect($region->manager->id)->toBe($manager->id);
});

it('scopes by level', function () {
    SalesTerritory::factory()->region()->create();
    SalesTerritory::factory()->zone()->count(2)->create();
    SalesTerritory::factory()->block()->count(3)->create();

    expect(SalesTerritory::regions()->count())->toBe(1);
    expect(SalesTerritory::zones()->count())->toBe(2);
    expect(SalesTerritory::blocks()->count())->toBe(3);
});
```

**Step 6: Run the tests**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryHierarchyTest.php
```

Expected: All 7 tests pass.

**Step 7: Commit**

```bash
git add database/migrations/*add_hierarchy* app/Models/SalesTerritory.php database/factories/SalesTerritoryFactory.php tests/Feature/Sales/TerritoryHierarchyTest.php
git commit -m "feat: add territory hierarchy (region/zone/block) to sales_territories"
```

---

### Task 2: Migration — Create territory_team_members Pivot Table

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_territory_team_members_table.php`
- Modify: `app/Models/SalesTerritory.php` (add relationship)
- Modify: `app/Models/User.php` (add relationship)
- Test: `tests/Feature/Sales/TerritoryHierarchyTest.php` (add tests)

**Step 1: Create the migration**

```bash
php artisan make:migration create_territory_team_members_table --no-interaction
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
        Schema::create('territory_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('sales_territories')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // 'manager', 'estimator', 'knocker'
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['territory_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territory_team_members');
    }
};
```

**Step 2: Run the migration**

```bash
php artisan migrate
```

**Step 3: Add relationship to SalesTerritory model**

```php
public function teamMembers(): BelongsToMany
{
    return $this->belongsToMany(User::class, 'territory_team_members')
        ->withPivot('role', 'added_by')
        ->withTimestamps();
}

public function knockers(): BelongsToMany
{
    return $this->teamMembers()->wherePivot('role', 'knocker');
}

public function estimators(): BelongsToMany
{
    return $this->teamMembers()->wherePivot('role', 'estimator');
}
```

**Step 4: Add relationship to User model**

In `app/Models/User.php`:

```php
public function territoryTeams(): BelongsToMany
{
    return $this->belongsToMany(SalesTerritory::class, 'territory_team_members')
        ->withPivot('role', 'added_by')
        ->withTimestamps();
}
```

**Step 5: Add tests**

Append to `tests/Feature/Sales/TerritoryHierarchyTest.php`:

```php
it('adds team members to a territory', function () {
    $zone = SalesTerritory::factory()->zone()->create();
    $knocker1 = User::factory()->internal()->create();
    $knocker2 = User::factory()->internal()->create();
    $addedBy = User::factory()->internal()->create();

    $zone->teamMembers()->attach($knocker1, ['role' => 'knocker', 'added_by' => $addedBy->id]);
    $zone->teamMembers()->attach($knocker2, ['role' => 'knocker', 'added_by' => $addedBy->id]);

    expect($zone->teamMembers)->toHaveCount(2);
    expect($zone->knockers)->toHaveCount(2);
});

it('prevents duplicate team member assignment', function () {
    $zone = SalesTerritory::factory()->zone()->create();
    $user = User::factory()->internal()->create();

    $zone->teamMembers()->attach($user, ['role' => 'knocker']);

    // Attempting to attach again should fail
    expect(fn () => $zone->teamMembers()->attach($user, ['role' => 'knocker']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('gets user territory teams', function () {
    $user = User::factory()->internal()->create();
    $zone1 = SalesTerritory::factory()->zone()->create();
    $zone2 = SalesTerritory::factory()->zone()->create();

    $zone1->teamMembers()->attach($user, ['role' => 'knocker']);
    $zone2->teamMembers()->attach($user, ['role' => 'estimator']);

    expect($user->territoryTeams)->toHaveCount(2);
});
```

**Step 6: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryHierarchyTest.php
```

Expected: All 10 tests pass.

**Step 7: Commit**

```bash
git add database/migrations/*territory_team_members* app/Models/SalesTerritory.php app/Models/User.php tests/Feature/Sales/TerritoryHierarchyTest.php
git commit -m "feat: add territory_team_members pivot table for team assignments"
```

---

### Task 3: Migration — Create permission_scopes Table

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_create_permission_scopes_table.php`
- Create: `app/Models/PermissionScope.php`
- Test: `tests/Feature/Sales/PermissionScopeTest.php`

**Step 1: Create the migration**

```bash
php artisan make:migration create_permission_scopes_table --no-interaction
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
        Schema::create('permission_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission');
            $table->string('scope_type'); // 'grant' or 'revoke'
            $table->json('scope_value')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'permission', 'scope_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_scopes');
    }
};
```

**Step 2: Run the migration**

```bash
php artisan migrate
```

**Step 3: Create PermissionScope model**

```bash
php artisan make:model PermissionScope --no-interaction
```

Edit `app/Models/PermissionScope.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PermissionScope extends Model
{
    protected $fillable = [
        'user_id',
        'permission',
        'scope_type',
        'scope_value',
        'granted_by',
    ];

    protected function casts(): array
    {
        return [
            'scope_value' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function isGrant(): bool
    {
        return $this->scope_type === 'grant';
    }

    public function isRevoke(): bool
    {
        return $this->scope_type === 'revoke';
    }
}
```

**Step 4: Add relationship to User model**

In `app/Models/User.php`:

```php
public function permissionScopes(): HasMany
{
    return $this->hasMany(PermissionScope::class);
}
```

**Step 5: Write tests**

Create `tests/Feature/Sales/PermissionScopeTest.php`:

```php
<?php

use App\Models\PermissionScope;
use App\Models\User;

it('creates a grant permission scope', function () {
    $user = User::factory()->internal()->create();
    $admin = User::factory()->internal()->create();

    $scope = PermissionScope::create([
        'user_id' => $user->id,
        'permission' => 'territories.manage_regions',
        'scope_type' => 'grant',
        'scope_value' => null,
        'granted_by' => $admin->id,
    ]);

    expect($scope->isGrant())->toBeTrue();
    expect($scope->isRevoke())->toBeFalse();
    expect($scope->user->id)->toBe($user->id);
    expect($scope->grantedBy->id)->toBe($admin->id);
});

it('creates a revoke permission scope', function () {
    $user = User::factory()->internal()->create();

    $scope = PermissionScope::create([
        'user_id' => $user->id,
        'permission' => 'door_knocks.view_all',
        'scope_type' => 'revoke',
        'granted_by' => null,
    ]);

    expect($scope->isRevoke())->toBeTrue();
});

it('creates a scope with territory visibility restriction', function () {
    $user = User::factory()->internal()->create();

    $scope = PermissionScope::create([
        'user_id' => $user->id,
        'permission' => 'territories.view_own',
        'scope_type' => 'grant',
        'scope_value' => ['territory_ids' => [1, 5, 12]],
    ]);

    expect($scope->scope_value)->toBe(['territory_ids' => [1, 5, 12]]);
});

it('gets all permission scopes for a user', function () {
    $user = User::factory()->internal()->create();

    PermissionScope::create(['user_id' => $user->id, 'permission' => 'perm.a', 'scope_type' => 'grant']);
    PermissionScope::create(['user_id' => $user->id, 'permission' => 'perm.b', 'scope_type' => 'revoke']);

    expect($user->permissionScopes)->toHaveCount(2);
});
```

**Step 6: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/PermissionScopeTest.php
```

Expected: All 4 tests pass.

**Step 7: Commit**

```bash
git add database/migrations/*permission_scopes* app/Models/PermissionScope.php tests/Feature/Sales/PermissionScopeTest.php app/Models/User.php
git commit -m "feat: add permission_scopes table for user-level permission overrides"
```

---

### Task 4: PermissionScopeService

**Files:**
- Create: `app/Services/PermissionScopeService.php`
- Test: `tests/Feature/Sales/PermissionScopeTest.php` (extend)

**Step 1: Write the failing tests first**

Append to `tests/Feature/Sales/PermissionScopeTest.php`:

```php
use App\Services\PermissionScopeService;

it('grants a user an additional permission via scope', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();
    $admin = User::factory()->internal()->withRole('owner')->create();
    $service = app(PermissionScopeService::class);

    // Estimator doesn't have this permission by default
    expect($user->hasPermissionTo('territories.manage_regions'))->toBeFalse();

    $service->grantPermission($user, 'territories.manage_regions', null, $admin);

    expect($service->hasPermission($user, 'territories.manage_regions'))->toBeTrue();
});

it('revokes a role permission via scope', function () {
    $user = User::factory()->internal()->withRole('manager')->create();
    $admin = User::factory()->internal()->withRole('owner')->create();
    $service = app(PermissionScopeService::class);

    // Manager has this by default via role
    expect($user->hasPermissionTo('sales_territories.view'))->toBeTrue();

    $service->revokePermission($user, 'sales_territories.view', $admin);

    expect($service->hasPermission($user, 'sales_territories.view'))->toBeFalse();
});

it('returns scope value for visibility filtering', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();
    $admin = User::factory()->internal()->create();
    $service = app(PermissionScopeService::class);

    $service->grantPermission($user, 'territories.view_own', ['territory_ids' => [1, 2, 3]], $admin);

    $scope = $service->getScope($user, 'territories.view_own');
    expect($scope)->toBe(['territory_ids' => [1, 2, 3]]);
});

it('returns null scope when no scoping exists', function () {
    $user = User::factory()->internal()->withRole('manager')->create();
    $service = app(PermissionScopeService::class);

    $scope = $service->getScope($user, 'sales_territories.view');
    expect($scope)->toBeNull();
});

it('gets all effective permissions for a user', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();
    $admin = User::factory()->internal()->create();
    $service = app(PermissionScopeService::class);

    $service->grantPermission($user, 'territories.manage_regions', null, $admin);

    $permissions = $service->getEffectivePermissions($user);

    // Should include role permissions + granted scope
    expect($permissions->pluck('permission'))->toContain('territories.manage_regions');
});

it('removes a previously granted scope', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();
    $admin = User::factory()->internal()->create();
    $service = app(PermissionScopeService::class);

    $service->grantPermission($user, 'territories.manage_regions', null, $admin);
    expect($service->hasPermission($user, 'territories.manage_regions'))->toBeTrue();

    $service->removeScope($user, 'territories.manage_regions');
    expect($service->hasPermission($user, 'territories.manage_regions'))->toBeFalse();
});

it('gets visible territory IDs for a scoped user', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();
    $region = \App\Models\SalesTerritory::factory()->region()->create();
    $zone = \App\Models\SalesTerritory::factory()->zone()->childOf($region)->assignedTo($user)->create();
    $block1 = \App\Models\SalesTerritory::factory()->block()->childOf($zone)->create();
    $block2 = \App\Models\SalesTerritory::factory()->block()->childOf($zone)->create();

    $service = app(PermissionScopeService::class);
    $ids = $service->getVisibleTerritoryIds($user);

    expect($ids)->toContain($zone->id, $block1->id, $block2->id);
    expect($ids)->not->toContain($region->id);
});
```

**Step 2: Run tests to see them fail**

```bash
php artisan test --compact tests/Feature/Sales/PermissionScopeTest.php
```

Expected: Failures for missing `PermissionScopeService`.

**Step 3: Create the service**

Create `app/Services/PermissionScopeService.php`:

```php
<?php

namespace App\Services;

use App\Models\PermissionScope;
use App\Models\SalesTerritory;
use App\Models\User;
use Illuminate\Support\Collection;

class PermissionScopeService
{
    /**
     * Check if user has a permission (role + scope overrides).
     */
    public function hasPermission(User $user, string $permission): bool
    {
        // Check for explicit revoke first
        $revoked = PermissionScope::where('user_id', $user->id)
            ->where('permission', $permission)
            ->where('scope_type', 'revoke')
            ->exists();

        if ($revoked) {
            return false;
        }

        // Check role permission
        if ($user->hasPermissionTo($permission)) {
            return true;
        }

        // Check for explicit grant
        return PermissionScope::where('user_id', $user->id)
            ->where('permission', $permission)
            ->where('scope_type', 'grant')
            ->exists();
    }

    /**
     * Get the scope value for visibility filtering.
     */
    public function getScope(User $user, string $permission): ?array
    {
        $scope = PermissionScope::where('user_id', $user->id)
            ->where('permission', $permission)
            ->whereNotNull('scope_value')
            ->first();

        return $scope?->scope_value;
    }

    /**
     * Grant a permission to a user (with optional visibility scope).
     */
    public function grantPermission(User $user, string $permission, ?array $scopeValue, User $grantedBy): PermissionScope
    {
        return PermissionScope::updateOrCreate(
            ['user_id' => $user->id, 'permission' => $permission, 'scope_type' => 'grant'],
            ['scope_value' => $scopeValue, 'granted_by' => $grantedBy->id]
        );
    }

    /**
     * Revoke a permission from a user.
     */
    public function revokePermission(User $user, string $permission, User $grantedBy): PermissionScope
    {
        return PermissionScope::updateOrCreate(
            ['user_id' => $user->id, 'permission' => $permission, 'scope_type' => 'revoke'],
            ['granted_by' => $grantedBy->id]
        );
    }

    /**
     * Remove a scope override entirely (returns to role default).
     */
    public function removeScope(User $user, string $permission): void
    {
        PermissionScope::where('user_id', $user->id)
            ->where('permission', $permission)
            ->delete();
    }

    /**
     * Get all effective permissions for display (role + scopes).
     */
    public function getEffectivePermissions(User $user): Collection
    {
        $rolePermissions = $user->getAllPermissions()->map(fn ($p) => [
            'permission' => $p->name,
            'source' => 'role',
            'scope_value' => null,
        ]);

        $scopeGrants = PermissionScope::where('user_id', $user->id)
            ->where('scope_type', 'grant')
            ->get()
            ->map(fn ($s) => [
                'permission' => $s->permission,
                'source' => 'scope_grant',
                'scope_value' => $s->scope_value,
            ]);

        $revokedPermissions = PermissionScope::where('user_id', $user->id)
            ->where('scope_type', 'revoke')
            ->pluck('permission')
            ->toArray();

        return $rolePermissions
            ->reject(fn ($p) => in_array($p['permission'], $revokedPermissions))
            ->merge($scopeGrants)
            ->unique('permission')
            ->values();
    }

    /**
     * Get territory IDs this user can see (for map filtering).
     *
     * Logic:
     * - If user has 'territories.view_all' → return all territory IDs
     * - If user has scope_value with territory_ids → return those
     * - Otherwise → return territories assigned to user + their descendants
     */
    public function getVisibleTerritoryIds(User $user): array
    {
        // Owner/admin sees everything
        if ($this->hasPermission($user, 'territories.view_all')) {
            return SalesTerritory::active()->pluck('id')->toArray();
        }

        // Check for explicit territory_ids scope
        $scope = $this->getScope($user, 'territories.view_own');
        if ($scope && isset($scope['territory_ids'])) {
            return $scope['territory_ids'];
        }

        // Default: assigned territories + their descendants
        $assigned = SalesTerritory::active()
            ->where(function ($q) use ($user) {
                $q->where('assigned_user_id', $user->id)
                    ->orWhere('manager_id', $user->id);
            })
            ->with('children.children')
            ->get();

        $ids = [];
        foreach ($assigned as $territory) {
            $ids[] = $territory->id;
            $ids = array_merge($ids, $territory->getDescendantIds());
        }

        // Also include territories where user is a team member
        $teamTerritoryIds = $user->territoryTeams()->pluck('sales_territories.id')->toArray();
        $ids = array_merge($ids, $teamTerritoryIds);

        return array_unique($ids);
    }
}
```

**Step 4: Run all tests**

```bash
php artisan test --compact tests/Feature/Sales/PermissionScopeTest.php
```

Expected: All 11 tests pass.

**Step 5: Commit**

```bash
git add app/Services/PermissionScopeService.php tests/Feature/Sales/PermissionScopeTest.php
git commit -m "feat: add PermissionScopeService for user-level permission overrides"
```

---

### Task 5: Seed New Permissions & door_knocker Role

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Test: `tests/Feature/Sales/TerritoryHierarchyTest.php` (extend)

**Step 1: Add new permissions to the seeder**

In `database/seeders/RolesAndPermissionsSeeder.php`, add to the permissions array (find the territory section around line 191):

```php
// Territory hierarchy permissions
'territories.manage_regions',
'territories.manage_zones',
'territories.manage_blocks',
'territories.assign_team',
'territories.view_all',
'territories.view_own',
'territories.dispatch_blocks',

// Commission configuration
'commission.configure',
'commission.view_team',
'commission.view_all',

// Analytics
'analytics.territory_pnl',

// Compensation planning
'compensation.planner',
```

Add the `door_knocker` role (after existing role definitions):

```php
$doorKnocker = Role::findOrCreate('door_knocker', 'web');
$doorKnocker->syncPermissions([
    'territories.view_own',
    'door_knocks.view',
    'door_knocks.record',
    'territory_dispatch.view',
    'territory_dispatch.claim',
]);
```

Update existing roles to include new permissions:

```php
// Owner gets all new permissions
// (add to owner's syncPermissions array)
'territories.manage_regions', 'territories.manage_zones', 'territories.manage_blocks',
'territories.assign_team', 'territories.view_all', 'territories.dispatch_blocks',
'commission.configure', 'commission.view_all', 'analytics.territory_pnl', 'compensation.planner',

// Admin gets most (except manage_regions — grantable via scope)
'territories.manage_zones', 'territories.manage_blocks', 'territories.assign_team',
'territories.view_all', 'territories.dispatch_blocks',
'commission.configure', 'commission.view_all', 'analytics.territory_pnl', 'compensation.planner',

// Manager
'territories.manage_zones', 'territories.manage_blocks', 'territories.assign_team',
'territories.view_own', 'territories.dispatch_blocks',
'commission.configure', 'commission.view_team', 'analytics.territory_pnl',

// Estimator
'territories.view_own', 'territories.manage_blocks', 'territories.dispatch_blocks',
'territories.assign_team', 'commission.view_team',
```

**Step 2: Run the seeder**

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
```

**Step 3: Write tests**

Append to `tests/Feature/Sales/TerritoryHierarchyTest.php`:

```php
it('seeds the door_knocker role with correct permissions', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $role = \Spatie\Permission\Models\Role::findByName('door_knocker', 'web');
    expect($role)->not->toBeNull();

    $permissions = $role->permissions->pluck('name')->toArray();
    expect($permissions)->toContain('territories.view_own');
    expect($permissions)->toContain('door_knocks.record');
    expect($permissions)->toContain('door_knocks.view');
    expect($permissions)->not->toContain('territories.manage_regions');
    expect($permissions)->not->toContain('commission.configure');
});

it('gives owner all territory hierarchy permissions', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $role = \Spatie\Permission\Models\Role::findByName('owner', 'web');
    $permissions = $role->permissions->pluck('name')->toArray();

    expect($permissions)->toContain('territories.manage_regions');
    expect($permissions)->toContain('territories.view_all');
    expect($permissions)->toContain('compensation.planner');
    expect($permissions)->toContain('analytics.territory_pnl');
});
```

**Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryHierarchyTest.php
```

Expected: All tests pass.

**Step 5: Commit**

```bash
git add database/seeders/RolesAndPermissionsSeeder.php tests/Feature/Sales/TerritoryHierarchyTest.php
git commit -m "feat: seed territory hierarchy permissions and door_knocker role"
```

---

### Task 6: Add door_knocker to User Factory & Type Handling

**Files:**
- Modify: `database/factories/UserFactory.php` (add door_knocker state)
- Verify: `app/Models/User.php` (check if type column needs updating)
- Test: `tests/Feature/Sales/TerritoryHierarchyTest.php`

**Step 1: Check current User type handling**

The `door_knocker` is NOT a new user type — they're `type = 'internal'` with a role. Verify the User factory has a `.withRole()` state (it does from research). No User type change needed.

**Step 2: Write a convenience test**

Append to `tests/Feature/Sales/TerritoryHierarchyTest.php`:

```php
it('creates a door knocker user with correct role', function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $knocker = User::factory()->internal()->withRole('door_knocker')->create();

    expect($knocker->type)->toBe('internal');
    expect($knocker->hasRole('door_knocker'))->toBeTrue();
    expect($knocker->hasPermissionTo('door_knocks.record'))->toBeTrue();
    expect($knocker->hasPermissionTo('territories.view_own'))->toBeTrue();
    expect($knocker->hasPermissionTo('territories.manage_regions'))->toBeFalse();
});
```

**Step 3: Run tests**

```bash
php artisan test --compact tests/Feature/Sales/TerritoryHierarchyTest.php
```

Expected: All tests pass.

**Step 4: Commit**

```bash
git add tests/Feature/Sales/TerritoryHierarchyTest.php
git commit -m "test: verify door_knocker role permissions on internal user"
```

---

### Task 7: Run Full Test Suite & Format

**Step 1: Run Pint**

```bash
vendor/bin/pint --dirty
```

**Step 2: Run the full door knock + territory test suites**

```bash
php artisan test --compact tests/Feature/Sales/
```

Expected: All tests pass.

**Step 3: Run the broader test suite to check for regressions**

```bash
php artisan test --compact
```

Check for any failures. Fix if needed.

**Step 4: Final commit if any formatting changes**

```bash
git add -A && git commit -m "style: format code with Pint"
```

---

## Session 1 Completion Checklist

- [ ] `sales_territories` has `level`, `parent_id`, `manager_id` columns
- [ ] `territory_team_members` pivot table exists
- [ ] `permission_scopes` table exists with `PermissionScope` model
- [ ] `PermissionScopeService` with grant/revoke/check/getVisibleTerritoryIds
- [ ] `door_knocker` role seeded with minimal permissions
- [ ] All new territory hierarchy permissions seeded for all roles
- [ ] `SalesTerritory` model has parent/children/team relationships and level scopes
- [ ] Factory states: `.region()`, `.zone()`, `.block()`, `.childOf()`, `.managedBy()`
- [ ] All tests passing

**Session 2 can now build on:** territory hierarchy, team assignments, scoped permissions, and the door_knocker role.
