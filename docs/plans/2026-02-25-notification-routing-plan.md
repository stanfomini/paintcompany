# Notification Routing System — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Route internal notifications to the assigned user + any roles with the matching `notify.*` permission, instead of blasting all internal users.

**Architecture:** A `NotificationRouter` service resolves recipients by merging (1) the entity's assigned user with (2) internal users whose role has the matching `notify.*` Spatie permission. A migration seeds the permissions and grants them all to the `owner` role. Dispatch sites swap hardcoded loops for `NotificationRouter::resolve()`.

**Tech Stack:** Laravel 12, Spatie Permissions, Pest 4

**Design Doc:** `docs/plans/2026-02-25-notification-routing-design.md`

---

### Task 1: NotificationRouter Service

**Files:**
- Create: `app/Services/NotificationRouter.php`
- Test: `tests/Feature/Notifications/NotificationRoutingTest.php`

**Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Notifications/NotificationRoutingTest.php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\User;
use App\Services\NotificationRouter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
});

describe('NotificationRouter', function () {
    it('returns assigned user for estimate notification', function () {
        $assignee = User::factory()->internal()->create();
        $customer = Customer::factory()->assignedTo($assignee)->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $assignee->id,
        ]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        $router = app(NotificationRouter::class);
        $recipients = $router->resolve('notify.estimate_accepted', $estimate);

        expect($recipients->pluck('id')->all())->toContain($assignee->id);
    });

    it('returns role subscribers who have the notify permission', function () {
        $permission = Permission::firstOrCreate(['name' => 'notify.estimate_accepted', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $owner = User::factory()->internal()->create();
        $owner->assignRole($role);

        $otherUser = User::factory()->internal()->create();

        $estimate = Estimate::factory()->create();

        $router = app(NotificationRouter::class);
        $recipients = $router->resolve('notify.estimate_accepted', $estimate);

        expect($recipients->pluck('id')->all())->toContain($owner->id);
        expect($recipients->pluck('id')->all())->not->toContain($otherUser->id);
    });

    it('deduplicates when assigned user also has role permission', function () {
        $permission = Permission::firstOrCreate(['name' => 'notify.estimate_accepted', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $assignee = User::factory()->internal()->create();
        $assignee->assignRole($role);
        $customer = Customer::factory()->assignedTo($assignee)->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $assignee->id,
        ]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        $router = app(NotificationRouter::class);
        $recipients = $router->resolve('notify.estimate_accepted', $estimate);

        // Assigned user appears only once despite also having the role permission
        expect($recipients->where('id', $assignee->id))->toHaveCount(1);
    });

    it('only returns internal users', function () {
        $permission = Permission::firstOrCreate(['name' => 'notify.estimate_accepted', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'test_role', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $agent = User::factory()->agent()->create();
        $agent->assignRole($role);

        $estimate = Estimate::factory()->create();

        $router = app(NotificationRouter::class);
        $recipients = $router->resolve('notify.estimate_accepted', $estimate);

        expect($recipients->pluck('id')->all())->not->toContain($agent->id);
    });

    it('resolves assigned user from deal for job notifications', function () {
        $assignee = User::factory()->internal()->create();
        $deal = Deal::factory()->create(['assigned_to' => $assignee->id]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
        ]);

        $router = app(NotificationRouter::class);
        $recipients = $router->resolve('notify.job_created', $job);

        expect($recipients->pluck('id')->all())->toContain($assignee->id);
    });

    it('falls back to customer assignedTo when deal has no assignee', function () {
        $assignee = User::factory()->internal()->create();
        $customer = Customer::factory()->assignedTo($assignee)->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => null,
        ]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        $router = app(NotificationRouter::class);
        $recipients = $router->resolve('notify.estimate_accepted', $estimate);

        expect($recipients->pluck('id')->all())->toContain($assignee->id);
    });

    it('returns empty collection when no assigned user and no role subscribers', function () {
        $estimate = Estimate::factory()->create();

        $router = app(NotificationRouter::class);
        $recipients = $router->resolve('notify.estimate_accepted', $estimate);

        expect($recipients)->toBeEmpty();
    });
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=NotificationRouting`
Expected: FAIL — class NotificationRouter does not exist

**Step 3: Write the NotificationRouter service**

```php
<?php
// app/Services/NotificationRouter.php

namespace App\Services;

use App\Models\Estimate;
use App\Models\Job;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class NotificationRouter
{
    /**
     * Resolve the recipients for a notification permission + entity.
     *
     * @return Collection<int, User>
     */
    public function resolve(string $permission, Model $entity): Collection
    {
        $recipients = collect();

        // 1. Find the assigned user from the entity
        $assignee = $this->resolveAssignedUser($entity);
        if ($assignee && $assignee->isInternal()) {
            $recipients->push($assignee);
        }

        // 2. Find all internal users whose role has this permission
        $roleSubscribers = User::query()
            ->where('type', 'internal')
            ->permission($permission)
            ->get();

        $recipients = $recipients->merge($roleSubscribers);

        // 3. Deduplicate and filter
        return $recipients->unique('id')->filter(fn (User $u) => $u->isInternal())->values();
    }

    /**
     * Resolve the assigned user for an entity by walking the relationship chain.
     */
    private function resolveAssignedUser(Model $entity): ?User
    {
        // Estimate → deal.assignedTo ?? deal.customer.assignedTo
        if ($entity instanceof Estimate) {
            $entity->loadMissing('deal.customer.assignedTo');
            $deal = $entity->deal;

            return $deal?->assignedTo ?? $deal?->customer?->assignedTo;
        }

        // Job → deal.assignedTo ?? deal.customer.assignedTo
        if ($entity instanceof Job) {
            $entity->loadMissing('deal.customer.assignedTo');
            $deal = $entity->deal;

            return $deal?->assignedTo ?? $deal?->customer?->assignedTo;
        }

        // Generic: check for assignedTo relationship
        if (method_exists($entity, 'assignedTo')) {
            return $entity->assignedTo;
        }

        return null;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=NotificationRouting`
Expected: PASS (7 tests)

**Step 5: Format and commit**

```bash
vendor/bin/pint --dirty
git add app/Services/NotificationRouter.php tests/Feature/Notifications/NotificationRoutingTest.php
git commit -m "feat: add NotificationRouter service for role-based notification routing"
```

---

### Task 2: Seed Notification Permissions

**Files:**
- Create: `database/migrations/2026_02_25_000001_seed_notification_permissions.php`
- Modify: `resources/views/pages/settings/⚡role-detail.blade.php:83-138` — add Notifications group

**Step 1: Create the migration**

```bash
php artisan make:migration seed_notification_permissions --no-interaction
```

Then write the migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'notify.estimate_accepted',
            'notify.estimate_rejected',
            'notify.proposal_viewed',
            'notify.job_created',
            'notify.job_stage_advanced',
            'notify.customer_confirmed_date',
            'notify.customer_confirmed_colors',
            'notify.customer_proposed_date',
            'notify.new_account_created',
            'notify.new_support_chat',
            'notify.new_messenger_lead',
            'notify.customer_portal_login',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Owner gets all notification permissions by default
        $ownerRole = Role::where('name', 'owner')->where('guard_name', 'web')->first();
        if ($ownerRole) {
            $ownerRole->givePermissionTo($permissions);
        }

        // Admin gets all notification permissions by default
        $adminRole = Role::where('name', 'admin')->where('guard_name', 'web')->first();
        if ($adminRole) {
            $adminRole->givePermissionTo($permissions);
        }

        // Estimator gets estimate-related notifications
        $estimatorRole = Role::where('name', 'estimator')->where('guard_name', 'web')->first();
        if ($estimatorRole) {
            $estimatorRole->givePermissionTo([
                'notify.estimate_accepted',
                'notify.estimate_rejected',
                'notify.proposal_viewed',
                'notify.job_created',
            ]);
        }

        // Manager gets job/scheduling notifications
        $managerRole = Role::where('name', 'manager')->where('guard_name', 'web')->first();
        if ($managerRole) {
            $managerRole->givePermissionTo([
                'notify.estimate_accepted',
                'notify.job_created',
                'notify.job_stage_advanced',
                'notify.customer_confirmed_date',
                'notify.customer_confirmed_colors',
                'notify.customer_proposed_date',
            ]);
        }
    }

    public function down(): void
    {
        $permissions = Permission::where('name', 'like', 'notify.%')->get();
        foreach ($permissions as $permission) {
            $permission->delete();
        }
    }
};
```

**Step 2: Add Notifications group to role-detail page**

In `resources/views/pages/settings/⚡role-detail.blade.php`, inside the `permissionGroups()` computed property, add before the `'Settings'` key:

```php
'Notifications' => [
    'notify.estimate_accepted', 'notify.estimate_rejected', 'notify.proposal_viewed',
    'notify.job_created', 'notify.job_stage_advanced',
    'notify.customer_confirmed_date', 'notify.customer_confirmed_colors', 'notify.customer_proposed_date',
    'notify.new_account_created', 'notify.new_support_chat', 'notify.new_messenger_lead',
    'notify.customer_portal_login',
],
```

**Step 3: Run the migration**

```bash
php artisan migrate
```

**Step 4: Format and commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/*seed_notification_permissions* resources/views/pages/settings/⚡role-detail.blade.php
git commit -m "feat: seed notification permissions and add to role settings UI"
```

---

### Task 3: Fix EstimateAccepted Routing

**Files:**
- Modify: `app/Models/EstimateSignature.php:62-66`
- Test: `tests/Feature/Notifications/NotificationRoutingTest.php` (add integration test)

**Step 1: Write the integration test**

Add to `tests/Feature/Notifications/NotificationRoutingTest.php`:

```php
describe('EstimateAccepted dispatch integration', function () {
    it('sends EstimateAccepted to assigned user and role subscribers only', function () {
        Notification::fake();

        $permission = Permission::firstOrCreate(['name' => 'notify.estimate_accepted', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $owner = User::factory()->internal()->create();
        $owner->assignRole($role);

        $assignee = User::factory()->internal()->create();
        $bystander = User::factory()->internal()->create();

        $customer = Customer::factory()->assignedTo($assignee)->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $assignee->id,
        ]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id, 'status' => 'sent']);

        EstimateSignature::createFromRequest($estimate, 'John Doe', 'john@test.com', 'sig', 'typed');

        Notification::assertSentTo($assignee, EstimateAccepted::class);
        Notification::assertSentTo($owner, EstimateAccepted::class);
        Notification::assertNotSentTo($bystander, EstimateAccepted::class);
    });
});
```

Add the necessary imports at the top of the file:
```php
use App\Models\EstimateSignature;
use App\Notifications\EstimateAccepted;
use Illuminate\Support\Facades\Notification;
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="EstimateAccepted dispatch integration"`
Expected: FAIL — bystander also gets the notification (current behavior sends to all internal)

**Step 3: Update EstimateSignature dispatch**

In `app/Models/EstimateSignature.php`, replace lines 62-66:

```php
// Before:
// Notify all internal users about the accepted estimate
$internalUsers = User::where('type', 'internal')->get();
foreach ($internalUsers as $user) {
    $user->notify(new EstimateAccepted($signature->estimate));
}

// After:
// Notify assigned user + role subscribers
$recipients = app(\App\Services\NotificationRouter::class)
    ->resolve('notify.estimate_accepted', $signature->estimate);
foreach ($recipients as $user) {
    $user->notify(new EstimateAccepted($signature->estimate));
}
```

**Step 4: Run tests**

Run: `php artisan test --compact --filter="NotificationRouting|EstimateAccepted"`
Expected: PASS

**Step 5: Format and commit**

```bash
vendor/bin/pint --dirty
git add app/Models/EstimateSignature.php tests/Feature/Notifications/NotificationRoutingTest.php
git commit -m "fix: route EstimateAccepted to assigned user + role subscribers"
```

---

### Task 4: Fix EstimateRejected Routing

**Files:**
- Modify: `resources/views/components/estimates/⚡show.blade.php:226-230`

**Step 1: Update the dispatch code**

In `resources/views/components/estimates/⚡show.blade.php`, replace the rejection notification block (around line 226-230):

```php
// Before:
// Notify all internal users
$internalUsers = User::where('type', 'internal')->get();
foreach ($internalUsers as $user) {
    $user->notify(new EstimateRejected($this->estimate, $reason));
}

// After:
// Notify assigned user + role subscribers
$recipients = app(\App\Services\NotificationRouter::class)
    ->resolve('notify.estimate_rejected', $this->estimate);
foreach ($recipients as $user) {
    $user->notify(new \App\Notifications\EstimateRejected($this->estimate, $reason));
}
```

**Step 2: Run existing rejection tests**

Run: `php artisan test --compact --filter="EstimateRejected"`
Expected: PASS

**Step 3: Format and commit**

```bash
vendor/bin/pint --dirty
git add resources/views/components/estimates/⚡show.blade.php
git commit -m "fix: route EstimateRejected to assigned user + role subscribers"
```

---

### Task 5: Fix JobCreated Routing

**Files:**
- Modify: `app/Services/JobService.php:91-95`

**Step 1: Update the dispatch code**

In `app/Services/JobService.php`, replace lines 91-95:

```php
// Before:
// Notify internal users
$internalUsers = User::where('type', 'internal')->get();
foreach ($internalUsers as $user) {
    $user->notify(new JobCreated($job));
}

// After:
// Notify assigned user + role subscribers
$recipients = app(NotificationRouter::class)->resolve('notify.job_created', $job);
foreach ($recipients as $user) {
    $user->notify(new JobCreated($job));
}
```

Add the import at the top of `JobService.php`:
```php
use App\Services\NotificationRouter;
```

**Step 2: Run existing job tests**

Run: `php artisan test --compact --filter="JobCreated|JobService"`
Expected: PASS

**Step 3: Format and commit**

```bash
vendor/bin/pint --dirty
git add app/Services/JobService.php
git commit -m "fix: route JobCreated to assigned user + role subscribers"
```

---

### Task 6: Fix NewAccountCreated Routing

**Files:**
- Modify: `app/Observers/UserObserver.php`

**Step 1: Update UserObserver**

Replace the entire `created` method:

```php
// Before:
public function created(User $user): void
{
    if ($user->type === 'internal') {
        return;
    }

    $admin = User::find(1);

    if ($admin && $admin->isInternal()) {
        $admin->notify(new NewAccountCreated($user));
    }
}

// After:
public function created(User $user): void
{
    if ($user->type === 'internal') {
        return;
    }

    // Notify all internal users whose role has the notify.new_account_created permission
    $recipients = User::query()
        ->where('type', 'internal')
        ->permission('notify.new_account_created')
        ->get();

    // Fallback: if no permissions are set up yet, notify user ID 1
    if ($recipients->isEmpty()) {
        $admin = User::find(1);
        if ($admin && $admin->isInternal()) {
            $recipients = collect([$admin]);
        }
    }

    foreach ($recipients as $recipient) {
        $recipient->notify(new NewAccountCreated($user));
    }
}
```

Note: NewAccountCreated doesn't have an "entity" with an assigned user — it's purely role-based. We don't use NotificationRouter here because there's no entity to resolve an assignee from.

**Step 2: Run existing tests**

Run: `php artisan test --compact --filter="NewAccountCreated"`
Expected: PASS

**Step 3: Format and commit**

```bash
vendor/bin/pint --dirty
git add app/Observers/UserObserver.php
git commit -m "fix: route NewAccountCreated to role subscribers instead of hardcoded user 1"
```

---

### Task 7: Final Integration Tests & Verification

**Step 1: Run all notification tests**

```bash
php artisan test --compact --filter="Notification"
```

**Step 2: Run the full affected test suites**

```bash
php artisan test --compact --filter="NotificationRouting|WelcomeEmail|GuestChat|EstimateAccepted|EstimateRejected|JobCreated|NewAccountCreated"
```

**Step 3: Format everything**

```bash
vendor/bin/pint --dirty
```

**Step 4: Final commit if needed**

If any files changed from pint formatting.
