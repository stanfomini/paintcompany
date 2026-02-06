# Permissions Implementation Plan

## Overview

Complete the permissions system migration from `isInternal()` checks to granular Spatie Permission-based authorization. Enforce all 46 defined permissions, clean up legacy patterns, improve the roles UI, and write comprehensive tests.

**Current State**: ~50% of 46 permissions are enforced (route middleware covers view-level). Delete, send, manage, and edit actions lack enforcement in Livewire component methods.

---

## Phase 1: Enforce Missing Permissions in Livewire Components

### 1A. Estimate Actions (`resources/views/components/estimates/⚡show.blade.php`)

| Permission | Action Method | Implementation |
|---|---|---|
| `estimates.send` | `sendProposal()`, `markAsSent()` | Add `abort_unless(auth()->user()->can('estimates.send'), 403)` before action |
| `estimates.delete` | No delete action exists yet | Add `deleteEstimate()` method with permission check if needed |
| `estimates.send` | Send button in Blade | Wrap with `@can('estimates.send')` |

### 1B. People Actions

| Permission | Component | Implementation |
|---|---|---|
| `people.create` | Customer/Agent/Crew create modals | Check `can('people.create')` in create methods |
| `people.edit` | Customer/Agent/Crew show pages | Check `can('people.edit')` in update methods |
| `people.delete` | Customer/Agent/Crew show pages | Check `can('people.delete')` in delete methods |

Components to modify:
- `resources/views/components/customers/⚡show.blade.php`
- `resources/views/components/agents/⚡show.blade.php`
- `resources/views/components/crews/⚡show.blade.php`

### 1C. Properties & Deals Delete

| Permission | Component | Implementation |
|---|---|---|
| `properties.delete` | `⚡show.blade.php` | Add `can('properties.delete')` check + hide button |
| `deals.delete` | `⚡show.blade.php` | Add `can('deals.delete')` check + hide button |

### 1D. Jobs Edit

| Permission | Component | Implementation |
|---|---|---|
| `jobs.edit` | `resources/views/components/jobs/⚡show.blade.php` | Check `can('jobs.edit')` before edit actions |
| `jobs.edit` | `resources/views/components/jobs/⚡kanban.blade.php` | Hide edit controls with `@can` |

### 1E. Financial Admin

| Permission | Component | Implementation |
|---|---|---|
| `financials.manage_pricing` | `resources/views/pages/settings/⚡company.blade.php` | Check `can('financials.manage_pricing')` for pricing fields |

### 1F. Agent Listings Manage

| Permission | Component | Implementation |
|---|---|---|
| `agent_listings.manage` | `resources/views/components/agent-listings/⚡index.blade.php` | Check `can('agent_listings.manage')` for create/edit/delete |

### 1G. Roadmap Actions

| Permission | Component | Implementation |
|---|---|---|
| `edit roadmap` | `resources/views/components/roadmap/⚡index.blade.php` | Check in edit methods, hide edit buttons |
| `manage roadmap` | Same | Check in manage/delete methods |

### 1H. KPI Actions

| Permission | Component | Implementation |
|---|---|---|
| `edit kpi targets` | `resources/views/components/kpis/⚡sales-plan.blade.php` | Check before saving targets |
| `enter kpi actuals` | Same | Check before entering actuals |
| `manage kpis` | Same | Check before admin operations |
| Same 3 | `resources/views/components/kpis/⚡production-plan.blade.php` | Same pattern |

### 1I. Team Management

| Permission | Component | Implementation |
|---|---|---|
| `manage users` | User management areas | Check before user admin actions |
| `manage invitations` | Invitations component | Check `can('manage invitations')` in boot/actions |

---

## Phase 2: Clean Up Legacy `isInternal()` Patterns

### Remove Redundant Dual Checks (authorization-level)

| File | Line | Current | Change |
|---|---|---|---|
| `⚡sales-plan.blade.php` | 34 | `!$user->isInternal()` | `!$user->can('view kpis')` |
| `⚡production-plan.blade.php` | 33 | `!$user->isInternal()` | `!$user->can('view kpis')` |
| `⚡properties/index.blade.php` | 65 | `!$user->isInternal()` | Remove (route middleware handles) |
| `⚡roles.blade.php` | 32 | `!$user->isInternal() \|\| !$user->can(...)` | `!$user->can('manage roles')` only |
| `⚡thread-list.blade.php` | 16 | `$user->isInternal() && !$user->can(...)` | `!$user->can('chat.access')` |
| `⚡thread-view.blade.php` | 16 | Same | Same |
| `⚡create-thread.blade.php` | 71 | `!$user->isInternal()` | `!$user->can('chat.access')` |
| `⚡new-lead-alert.blade.php` | 14 | `!auth()->user()->isInternal()` | Permission-based check |
| `sidebar.blade.php` | 171 | `auth()->user()->isInternal()` | Permission-based or keep (display-only) |

### Keep These `isInternal()` Checks (display logic, NOT authorization)

| File | Line | Reason |
|---|---|---|
| `⚡thread-view.blade.php` | 245 | Shows "internal" badge on messages |
| `⚡message-composer.blade.php` | 69, 138, 172 | Controls internal-only message features |
| `agent/chat/⚡thread-view.blade.php` | 236 | Shows "internal" badge |
| `⚡notes-manager.blade.php` | 62 | Returns whether user is internal for display |
| `settings/layout.blade.php` | 10 | Shows settings nav for internal users (but could use permission) |

---

## Phase 3: Roles Management UI Improvements

### 3A. Settings Layout Navigation (Already Good)
The `settings/layout.blade.php` already uses `@can()` directives properly for Company, Packages, Allocations, Roles. Only improvement: replace `type === 'internal'` on line 10 with a more permission-based approach.

### 3B. Permission Matrix Enhancements

1. **Group Toggle** - Add "Select All / Deselect All" per group row
   - New method: `toggleGroupPermissions(string $roleName, string $groupName)`
   - Blade: Add checkbox in group header row

2. **Role Cloning** - When creating custom role, option to clone from existing
   - Add `cloneFromRole` property to modal
   - New method: `createRole()` copies permissions from selected role

3. **Permission Descriptions** - Tooltips on permission names
   - Add `permissionDescriptions()` computed property
   - Render as `<flux:tooltip>` on each permission cell

4. **Responsive Matrix** - Better mobile experience
   - On small screens, switch to card-based layout per role
   - Use `@media` or Tailwind responsive classes

5. **Search/Filter** - Filter permissions in matrix
   - Add `search` property
   - Filter `permissionGroups` by search term

### 3C. Component Structure (keep as single SFC)
All changes stay in `resources/views/pages/settings/⚡roles.blade.php`.

---

## Phase 4: Comprehensive Permission Tests

### Test Files to Create

Each follows the pattern from `PeoplePermissionTest.php`:
- `beforeEach` runs `RolesAndPermissionsSeeder`
- 3 describe blocks: Authentication, Without Permission, With Permission
- Tests both route access AND Livewire action authorization

```
tests/Feature/Permissions/
├── PeoplePermissionTest.php         (existing - 10 tests)
├── PropertiesPermissionTest.php     (new - ~10 tests)
├── DealsPermissionTest.php          (new - ~10 tests)
├── EstimatesPermissionTest.php      (new - ~14 tests: view/create/edit/delete/send)
├── FinancialsPermissionTest.php     (new - ~8 tests)
├── JobsPermissionTest.php           (new - ~6 tests)
├── PlannerPermissionTest.php        (new - ~8 tests)
├── CommunicationPermissionTest.php  (new - ~8 tests)
├── SettingsPermissionTest.php       (new - ~10 tests)
├── RoadmapPermissionTest.php        (new - ~8 tests)
└── KpiPermissionTest.php            (new - ~10 tests)
```

### Test Pattern

```php
<?php
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    (new RolesAndPermissionsSeeder)->run();
});

describe('Route Authentication', function () {
    it('redirects unauthenticated users to login', function () {
        $this->get(route('route.name'))->assertRedirect(route('login'));
    });
});

describe('Without Permission', function () {
    it('forbids internal user without permission', function () {
        $user = User::factory()->internal()->create();
        $this->actingAs($user)->get(route('route.name'))->assertForbidden();
    });
});

describe('With Permission', function () {
    it('allows user with specific permission', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('feature.view');
        $this->actingAs($user)->get(route('route.name'))->assertSuccessful();
    });

    it('allows full access user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($user)->get(route('route.name'))->assertSuccessful();
    });
});
```

---

## Implementation Order

1. **Phase 1** (Task #2) - Enforce permissions in components - **highest priority**
2. **Phase 2** (Task #3) - Clean up legacy patterns - **can run in parallel with Phase 1**
3. **Phase 4** (Task #5) - Write tests - **after Phase 1+2 complete**
4. **Phase 3** (Task #4) - UI improvements - **after Phase 1 complete, parallel with tests**

---

## Files Modified Summary

### High-Touch Files (multiple changes):
- `resources/views/components/estimates/⚡show.blade.php` - send/delete permissions
- `resources/views/components/kpis/⚡sales-plan.blade.php` - KPI permissions + cleanup
- `resources/views/components/kpis/⚡production-plan.blade.php` - KPI permissions + cleanup
- `resources/views/pages/settings/⚡roles.blade.php` - UI improvements + cleanup
- `resources/views/components/roadmap/⚡index.blade.php` - roadmap permissions

### Medium-Touch Files (1-2 changes):
- `resources/views/components/jobs/⚡show.blade.php` - jobs.edit
- `resources/views/components/jobs/⚡kanban.blade.php` - jobs.edit
- `resources/views/components/agent-listings/⚡index.blade.php` - manage
- `resources/views/components/chat/⚡thread-list.blade.php` - cleanup
- `resources/views/components/chat/⚡thread-view.blade.php` - cleanup
- `resources/views/components/chat/⚡create-thread.blade.php` - cleanup
- `resources/views/components/properties/⚡index.blade.php` - cleanup
- `resources/views/components/notifications/⚡new-lead-alert.blade.php` - cleanup
- `resources/views/layouts/app/sidebar.blade.php` - cleanup
- `resources/views/pages/settings/layout.blade.php` - cleanup

### New Files:
- 10 test files in `tests/Feature/Permissions/`
