# Territory Hierarchy, Scoped Permissions & Commission Pipeline Design

**Date**: 2026-02-21
**Status**: Approved

## Overview

Transform the flat territory system into a 3-level hierarchy (Region > Zone > Block) with scoped user permissions, configurable commission pipeline, P&L tracking per territory, and a compensation planning calculator. This enables building a door-to-door sales organization with region managers, zone estimators, and door knocker teams — all tracked financially against a 12% budget target.

---

## Architecture Decision

**Approach A (selected)**: Extend the existing `sales_territories` table with `parent_id` and `level` columns. All territory types share the same model, reusing existing dispatch, analytics, and map code. The hierarchy is fixed at 3 levels (not arbitrary depth).

---

## Session 1: Roles, Permissions & Data Model

### 1.1 Territory Hierarchy (Database)

**Migration: add hierarchy columns to `sales_territories`**

| Column | Type | Description |
|--------|------|-------------|
| `level` | ENUM('region', 'zone', 'block') | Territory level in hierarchy. Default 'zone' for backward compat. |
| `parent_id` | FK sales_territories, nullable | Parent territory. Regions have null parent. |
| `manager_id` | FK users, nullable | Person who manages this level (separate from assigned_user_id who works it). |

**Relationship semantics:**
- `assigned_user_id` = who **works** this territory (estimator for zones, knocker for blocks)
- `manager_id` = who **manages** this territory (region manager, zone estimator managing their blocks)
- A region's `manager_id` is the region manager. Its `assigned_user_id` is also the region manager (they own it).
- A zone's `manager_id` is the region manager. Its `assigned_user_id` is the estimator.
- A block's `manager_id` is the zone estimator. Its `assigned_user_id` is the door knocker.

**New pivot table: `territory_team_members`**

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `territory_id` | FK sales_territories | The zone or region |
| `user_id` | FK users | The team member |
| `role` | VARCHAR | 'manager', 'estimator', 'knocker' |
| `added_by` | FK users | Who added this member |
| `timestamps` | | Created/updated |

This lets a zone have a team of knockers, and a region have a team of estimators and knockers.

**Existing territories migration**: All existing rows get `level = 'zone'`, `parent_id = null`. Backward compatible.

### 1.2 New Role: door_knocker

Internal users (`type = 'internal'`) with a new Spatie role `door_knocker`. Minimal permissions:
- Can only see assigned blocks on the map
- Can record door knocks
- Can view own stats/knocks
- Cannot access CRM, estimates, or other modules

### 1.3 Scoped Permission System

**Keep Spatie for role-based permissions** (existing). Layer on a new table for user-level overrides.

**New table: `permission_scopes`**

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `user_id` | FK users | The user this scope applies to |
| `permission` | VARCHAR | Permission string (e.g., 'territories.manage') |
| `scope_type` | ENUM('grant', 'revoke') | Whether this adds or removes the permission |
| `scope_value` | JSON, nullable | Visibility scoping (e.g., `{"territory_ids": [1,2,3]}`) |
| `granted_by` | FK users | Who set this override |
| `timestamps` | | Created/updated |

**Resolution order:**
1. Check Spatie role permission (baseline)
2. Check `permission_scopes` for user overrides
3. `grant` entries ADD permissions the role doesn't have
4. `revoke` entries REMOVE permissions the role has
5. `scope_value` narrows WHAT they can see (territory IDs, etc.)
6. Result = effective permission + visibility scope

**Service**: `PermissionScopeService` with methods:
- `hasPermission(User, string $permission): bool` — checks role + scopes
- `getScope(User, string $permission): ?array` — returns scope_value for visibility filtering
- `grantPermission(User, string $permission, ?array $scope, User $grantedBy): void`
- `revokePermission(User, string $permission, User $grantedBy): void`
- `getEffectivePermissions(User): Collection` — all permissions with scopes for display

### 1.4 New Permissions to Seed

```
territories.manage_regions     — Create/edit/delete regions
territories.manage_zones       — Create/edit zones within assigned region
territories.manage_blocks      — Create/edit blocks within assigned zone
territories.assign_team        — Add/remove team members to territories
territories.view_all           — See all territories at all levels
territories.view_own           — See only assigned territory tree (self + children)
territories.dispatch_blocks    — Assign/offer blocks to knockers
commission.configure           — Set commission rules for team members
commission.view_team           — See team member commission data
commission.view_all            — See all commission data across org
analytics.territory_pnl        — View territory P&L reports
compensation.planner           — Access the compensation planning calculator
```

### 1.5 Role Permission Defaults

| Role | Territory Perms | Commission | Analytics |
|------|----------------|------------|-----------|
| **owner** | All | All | All |
| **admin** | All except manage_regions (grantable via scope) | All | All |
| **manager** | manage_zones, manage_blocks, assign_team, dispatch_blocks, view_own | configure, view_team | territory_pnl |
| **estimator** | manage_blocks, dispatch_blocks, assign_team (blocks only), view_own | view_team | territory_pnl (own zone) |
| **door_knocker** | view_own | — | — |

### 1.6 Map Visibility Scoping

The territory map filters displayed territories based on the user's effective scope:
- **Owner/admin** with `territories.view_all`: sees all regions/zones/blocks
- **Region manager**: sees their region + all child zones + all child blocks
- **Zone estimator**: sees their zone + all child blocks
- **Door knocker**: sees only their assigned block(s)

The computed property `territoryPolygons` in map components filters by `PermissionScopeService::getVisibleTerritoryIds(User)`.

---

## Session 2: Commission Pipeline & Financial Tracking

### 2.1 Commission Attribution Chain

When a door knock converts to a job, commissions flow through the hierarchy:

```
Door Knocker (appointment)  →  Zone Estimator (estimate/sale)  →  Region Manager (override)
```

**Trigger points (using existing CommissionService):**

1. **Knock → appointment_set**: New `CommissionService::createFromDoorKnock($knock)`. Finds knocker's EmployeeProfile, applies `source: 'door_knock'` commission_rules. Creates pending CommissionEntry.

2. **Estimate accepted**: Existing `createFromEstimate()` handles this. Zone estimator gets commission via their existing rules.

3. **Job completed**: Existing `createFromJob()` fires. New logic walks up territory hierarchy — if job's deal originated from a door knock, finds region manager and applies `source: 'territory_override'` rules.

**New commission_rules sources:**
- `door_knock` — triggered when knock status changes to `appointment_set` or `sold`
- `territory_override` — triggered on job completion for managers up the hierarchy chain

### 2.2 Deal Attribution

**Migration: add `origin_door_knock_id` to `deals` table** (nullable FK).

When `DoorKnockService::convertToLead()` creates a deal, it sets `origin_door_knock_id`. This traces the deal back through the territory hierarchy for commission attribution.

### 2.3 Three Door Knocker Compensation Models

All configurable per employee via `EmployeeProfile.commission_rules`:

| Model | Knocker Pay | Estimator (solo) | Estimator (w/team) | Region Mgr | Approx Total |
|-------|-------------|-------------------|---------------------|------------|-------------|
| **Commission Only** | 4% of job revenue | 10% (full pipeline) | 6% + sales bonus | 2% override | ~12% |
| **Flat + Commission** | $50/delivered estimate + 1% | 6% | 6% + sales bonus | 2% override | ~9-12% |
| **Flat Only** | $100-$150/delivered estimate | 6% | 6% + sales bonus | 2% override | ~8-12% |

**Estimator solo vs team**: When an estimator does everything (knocks + estimates + sells), they get 10%. When they run a team of knockers, they get 6% plus the increased volume. The system determines this based on whether the deal has `origin_door_knock_id` pointing to a different user.

### 2.4 Territory Budget Tracking

**New model: `TerritoryBudget`**

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `territory_id` | FK sales_territories | Any level territory |
| `period_type` | ENUM('monthly', 'quarterly', 'yearly') | Budget period |
| `period_start` | DATE | Start of budget period |
| `marketing_budget` | DECIMAL(12,2) | Allocated marketing spend |
| `commission_budget_pct` | DECIMAL(5,2) | Target commission % (default 12) |
| `notes` | TEXT, nullable | Budget notes |
| `timestamps` | | Created/updated |

### 2.5 Territory P&L Calculation

**Per territory (any level, rolls up from children):**

```
Revenue:   SUM of completed jobs attributed to territory (by deal origin or address geocode)
Costs:     Knocker commissions + estimator commissions + manager overrides
           + knocker hourly/flat pay + marketing budget allocation
Margin:    Revenue - Costs
Budget %:  Costs / Revenue (target: <= 12%)
```

**Service**: `TerritoryPnlService` with methods:
- `calculatePnl(SalesTerritory, DateRange): TerritoryPnlResult`
- `calculatePnlRollup(SalesTerritory, DateRange): TerritoryPnlResult` (includes children)
- `getBudgetAlert(SalesTerritory, DateRange): ?BudgetAlert` (warns when trending over target)

Region P&L = sum of zone P&Ls. Zone P&L = sum of block P&Ls plus zone-level costs.

### 2.6 Compensation Planning Calculator

**Purpose**: Owner-only tool to find the sweet spot for commission rates. Works in BOTH directions:
- **Forward**: Set commission rates → see projected annual income per role
- **Reverse**: Set target income ($60k-$120k) → see required activity (knocks/day, leads/day)

**Inputs (all adjustable sliders):**
- Average job size ($1,000 - $25,000)
- Knock-to-appointment conversion rate (5% - 30%)
- Appointment-to-sold conversion rate (20% - 60%)
- Work days per week (4 or 5)
- Work weeks per year (48-52)
- Target annual income per role ($60,000 - $120,000)
- Commission rates for each model (%, $, or both)
- All three knocker pay models side by side

**Outputs (live-updating):**
- Per-role projected annual income at current slider positions
- Required knocks/day to hit target income (reverse calculation)
- Required leads/day, estimates/week, jobs/month
- Total commission % of revenue gauge (red/yellow/green against 12% target)
- Three-column comparison of all pay models

**Scenarios**: Save named slider configurations as JSON snapshots. Load and compare scenarios. "Apply to Employee" button sets the selected commission rules on an employee's profile.

---

## Session 3: UI/UX Design

### 3.1 Territory Management Page (Enhanced `/sales/territories`)

**4-tab layout, permission-scoped:**

**Tab 1: Map View** (existing, enhanced)
- Territories colored/bordered by level: regions = thick border, zones = medium, blocks = thin dashed
- Permission-scoped visibility (users only see their territory tree)
- Region switcher dropdown for owner/admin
- Click territory → details panel (assigned person, team, stats)
- Draw/edit boundaries at any level (with correct permission)

**Tab 2: Hierarchy** (new)
- Collapsible tree view: Region → Zones → Blocks
- Each row shows: name, assigned user, team size, recent stats
- Inline actions: assign user, add team member, create child territory
- Drag to rearrange (move block between zones, etc.)
- Quick-add: "+ Zone" under region, "+ Block" under zone
- Team panel: click a zone → see/manage knocker team

**Tab 3: Dispatch** (existing, enhanced)
- Zone managers see their blocks only
- Two modes:
  - **Direct assign**: Drag knocker to block for a date. Knocker sees it on their map.
  - **Offer mode**: Create timed offers, knockers accept/decline (existing queue system)
- Weekly calendar: who's assigned where
- Batch dispatch: assign multiple blocks at once

**Tab 4: Analytics** (existing, enhanced with P&L)
- Conversion funnel: Knocks → Appointments → Estimates → Sold (filterable by level)
- P&L card per territory: revenue, costs, margin, budget %
- Drill-down: click region → zone breakdown → block breakdown
- Comparison table: all territories at same level side-by-side
- Time range picker: daily, weekly, monthly, custom
- Budget alerts: territories trending over 12%

### 3.2 Compensation Planning Page (`/sales/compensation-planner`)

- **Access**: Owner/admin only (or `compensation.planner` scoped permission)
- **Layout**: Three-column scenario comparison (Commission Only / Flat+Commission / Flat Only)
- **Top section**: Global sliders (avg job size, conversion rates, work schedule)
- **Middle section**: Per-model sliders (commission %, flat amounts)
- **Bottom section**: Results dashboard
  - Per-role projected annual income cards
  - Required daily/weekly/monthly activity targets
  - Total budget % gauge with 12% target line
  - Reverse calculator: set target income → auto-calculate required knocks/day
- **Actions**: Save scenario, load scenario, compare scenarios, apply to employee

### 3.3 Door Knocker Dashboard (minimal view at `/sales/door-knocks`)

Door knockers see a simplified version of the door knock page:
- Map showing ONLY their assigned blocks for today (filtered by permission scope)
- Live GPS tracking with stop detection (already built)
- Today's stats: knocks completed, appointments set, conversion rate
- Simple knock list with status chips
- No access to: CRM, estimates, territory management, analytics, settings

### 3.4 Settings Integration

- **Roles page** (`/settings/roles`): Updated with new permissions in the matrix
- **User edit**: New "Permission Overrides" section showing granted/revoked scoped permissions with add/remove UI
- **Employee profile**: Shows territory assignment, commission model, and current commission rules

### 3.5 Permission-Driven UI Rules

| User sees... | Required permission |
|-------------|-------------------|
| Region switcher on map | `territories.view_all` |
| Create/edit region button | `territories.manage_regions` |
| Create/edit zone button | `territories.manage_zones` |
| Create/edit block button | `territories.manage_blocks` |
| Team management panel | `territories.assign_team` |
| Dispatch tab | `territories.dispatch_blocks` |
| Analytics P&L tab | `analytics.territory_pnl` |
| Compensation planner page | `compensation.planner` |
| Commission configuration | `commission.configure` |
| All territories on map | `territories.view_all` (else filtered to own tree) |

---

## Execution Plan: Three Sessions

### Session 1: Roles, Permissions & Data Model
- Migration: add `level`, `parent_id`, `manager_id` to `sales_territories`
- Migration: create `territory_team_members` pivot table
- Migration: create `permission_scopes` table
- New role: `door_knocker` with seeded permissions
- Seed new permissions for all existing roles
- `PermissionScopeService` with grant/revoke/check logic
- `SalesTerritory` model updates: `parent()`, `children()`, `team()`, `level` casting
- Tests: hierarchy queries, permission resolution, scope filtering

### Session 2: Commission Pipeline & Financial Tracking
- Migration: add `origin_door_knock_id` to `deals`
- Migration: create `territory_budgets` table
- New commission_rules sources: `door_knock`, `territory_override`
- `CommissionService::createFromDoorKnock()` method
- Territory hierarchy walk in `createFromJob()` for override commissions
- `TerritoryPnlService` with P&L calculation and rollup
- `TerritoryBudget` model with CRUD
- Tests: commission chain, P&L calculations, budget alerts

### Session 3: UI/UX Implementation
- Territory page: 4-tab layout with hierarchy tree, enhanced map, dispatch, analytics
- Compensation planner page: slider calculator with 3-scenario comparison and reverse calculator
- Door knocker dashboard: minimal map-only view
- Settings: permission overrides UI on user edit, updated roles matrix
- Map scoping: filter territories by user's effective permissions
- Tests: Livewire component tests, permission-based visibility tests

### Session 4: Integration Review
- End-to-end flow: create region → add zone → add blocks → assign team → knock → convert → commission → P&L
- Cross-session consistency check
- Performance testing with realistic data volumes
- Mobile usability review for door knocker experience
