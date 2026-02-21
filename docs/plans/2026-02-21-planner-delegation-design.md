# Planner Delegation System — Design Doc

**Date:** 2026-02-21
**Status:** Approved

## Problem

When viewing the "Everyone" scope in the planner, users with `planner.view_all` can see all activities but cannot act on them (404 on toggle/snooze/priority). Beyond this immediate bug, there is no way for a manager to grant another user temporary or permanent access to someone's planner — needed when employees are out sick, on vacation, or leave the company.

## Solution

A configurable delegation system that allows:
- Managers to grant any user access to any other user's planner
- Individual users to share their own planner with colleagues
- Time-limited or permanent access with configurable levels (view, act, full control)
- Auditable tracking of who performed actions on behalf of whom

## Data Model

### `planner_delegations` table

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint PK | Auto-increment |
| `grantor_id` | FK → users | Who created the delegation |
| `target_user_id` | FK → users | Whose planner is being shared |
| `delegate_id` | FK → users | Who receives access |
| `access_level` | enum(view, act, full) | What they can do |
| `starts_at` | timestamp, nullable | When access begins (null = immediately) |
| `expires_at` | timestamp, nullable | When access ends (null with is_permanent = permanent) |
| `is_permanent` | boolean, default false | Quick check for no-expiry delegations |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

**Constraints:**
- Unique on `(target_user_id, delegate_id)` — one active delegation per user pair
- `delegate_id != target_user_id` — cannot delegate to self
- Self-delegation: `grantor_id == target_user_id` (user sharing own planner)
- Manager delegation: `grantor_id != target_user_id`

### Access levels

- **view** — See the target user's activities. No actions allowed.
- **act** — Complete, snooze, and reprioritize tasks. Cannot create or reassign.
- **full** — All of the above plus create tasks on behalf of target user and reassign their tasks.

### `activities` table change

Add nullable `acted_by` column (FK → users). When a delegate performs an action, this records who actually did it. Null means the activity's own user performed it.

### New permissions

| Permission | Description | Default roles |
|------------|-------------|---------------|
| `planner.delegate` | Create delegations for ANY user's planner | manager, admin, owner |
| `planner.delegate_own` | Share own planner with others | all internal roles |

Added to `RolesAndPermissionsSeeder` and seeded.

## Service: `PlannerDelegationService`

### Methods

- `createDelegation(grantor, targetUser, delegate, accessLevel, startsAt, expiresAt, isPermanent)` — Create or update delegation. Validates grantor has `planner.delegate` OR is the target user with `planner.delegate_own`.
- `revokeDelegation(delegationId)` — Remove a delegation. Grantor, target user, or anyone with `planner.delegate` can revoke.
- `getDelegatesFor(user)` — Active delegations on this user's planner.
- `getDelegationsFor(delegate)` — Planners this delegate can access.
- `canActOnBehalf(delegate, targetUser, requiredLevel)` — Boolean authorization check. `view < act < full`.
- `expireStale()` — Delete expired non-permanent delegations.

### Authorization flow in action methods

```
User clicks action on activity →
  activityQueryForAction() checks:
    1. User's own activity (creator or assignee)? → allow
    2. User has planner.view_all? → allow
    3. User has active delegation for activity owner with sufficient access_level? → allow
    4. Otherwise → 404
```

When acting via delegation, set `acted_by` on the activity to the delegate's user ID.

## UI: Planner Changes

### User selector dropdown

Appears next to the scope dropdown when the user has delegations or `planner.view_all`:

```
[Mine ▼]  [Viewing: All Users ▼]   [Active ▼]  [All Types ▼]
                     ↓
              "All Users" (default)
              "Sarah Johnson" (delegated - act)
              "Mike Painter" (delegated - view)
```

- Users with `planner.view_all`: lists all internal users
- Users with delegations only: lists only delegated users
- Selecting a user filters to their activities
- Banner: "Viewing [Name]'s planner ([access level])"

### "Manage Access" button

Button in planner header opens a flyout modal with:

**Own planner tab (any user with `planner.delegate_own`):**
- Current delegations list with revoke buttons
- "Share My Planner" form: user picker, access level, timeframe

**All users tab (managers with `planner.delegate`):**
- Target user selector
- Manage delegations for any user

### Timeframe UI

When creating a delegation:
1. Quick presets: Today, This Week, 2 Weeks, 1 Month
2. Custom date range (start date + end date pickers)
3. Permanent toggle (disables date fields)

## Scheduled Command

`planner:expire-delegations` — Runs daily via `routes/console.php`. Deletes delegations where `expires_at < now()` and `is_permanent = false`.

## Files to Create/Modify

### New files
- `database/migrations/XXXX_create_planner_delegations_table.php`
- `database/migrations/XXXX_add_acted_by_to_activities_table.php`
- `app/Models/PlannerDelegation.php` + factory
- `app/Services/PlannerDelegationService.php`
- `resources/views/components/planner/⚡delegation-manager.blade.php` (flyout modal)
- `tests/Feature/PlannerDelegationTest.php`

### Modified files
- `database/seeders/RolesAndPermissionsSeeder.php` — Add new permissions
- `resources/views/components/planner/⚡my-day.blade.php` — User selector, manage access button, updated `activityQueryForAction()`
- `app/Services/CommandCenterService.php` — Support delegation-based filtering
- `routes/console.php` — Schedule expiry command
