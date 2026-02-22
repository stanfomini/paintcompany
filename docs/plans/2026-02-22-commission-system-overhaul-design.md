# Commission System Overhaul Design

**Date**: 2026-02-22
**Status**: Approved
**Goal**: Full commission overhaul — company default rules, per-employee overrides, attribution chain visibility, and working tiered brackets.

## Problem Statement

The commission backend (CommissionService, commission_entries, commission_rules, commission_tiers) is mostly built but the UI is disconnected:

1. **Employee commission rule editor is too bare-bones** — missing `door_knock` and `territory_override` sources, tiered brackets have no editor
2. **No connection between territory roles and commission rules** — assigning someone as "knocker" in a territory is just a label with no commission implications
3. **Compensation Planner is a calculator, not a config tool** — sliders don't set anything real
4. **Lead source → commission chain is invisible** — no way to trace who gets paid and why on a deal/job
5. **Admin commissions page is just a log viewer** — no insight into rules, attribution, or the chain

## Design

### 1. `/settings/commissions` — Company Default Commission Rules

New dedicated settings page. Defines baseline commission structure that all employees inherit unless overridden.

**Layout**: Role-based cards, each containing one or more rules.

**Roles**:
- **Door Knocker**: Rules for `door_knock` (appointment set) and `job` (completed from knock) sources
- **Estimator**: Rules for `estimate` (accepted) source
- **Territory Manager (Override)**: Rules for `territory_override` source (fired when walking hierarchy)
- **Tiered Brackets**: Graduated rate tiers by period (monthly/quarterly/yearly)

**Data model**: Store in `CompanySetting` as JSON column `commission_defaults`:

```php
[
  'knocker' => [
    ['source' => 'door_knock', 'type' => 'flat', 'amount' => 25, 'trigger_on' => 'appointment_set', 'auto_trigger' => true],
    ['source' => 'job', 'type' => 'revenue_percentage', 'rate' => 0.04, 'auto_trigger' => true],
  ],
  'estimator' => [
    ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.06, 'auto_trigger' => true],
  ],
  'manager_override' => [
    ['source' => 'territory_override', 'type' => 'revenue_percentage', 'rate' => 0.02, 'auto_trigger' => true],
  ],
  'tiers' => [
    'period' => 'monthly',
    'brackets' => [
      ['min' => 0, 'max' => 5000, 'rate' => 0.08],
      ['min' => 5001, 'max' => 15000, 'rate' => 0.10],
      ['min' => 15001, 'max' => null, 'rate' => 0.12],
    ]
  ]
]
```

Each role card has: Source selector, Type (flat/revenue %/profit %/tiered), Rate/Amount inputs, Auto-trigger toggle, Add/Remove rule buttons.

Tiered brackets section: Period selector (monthly/quarterly/yearly), editable rows with min/max/rate, add/remove bracket buttons.

### 2. Employee Commission Tab — Inherited + Override View

When viewing an employee's Commission tab:

**Sections (top to bottom)**:

1. **Territory Role** — pulled from `territory_team_members` pivot. Shows: "Door Knocker in North Region > Zone A > Block 3"
2. **Company Defaults (inherited)** — read-only cards showing rules from `CompanySetting::commission_defaults` for their territory role. Locked icon, grayed styling.
3. **Personal Overrides** — editable rules on `employee_profiles.commission_rules`. "Add Override" button. These replace matching defaults by source.
4. **Effective Rules** — merged view of what actually applies. Shows each rule with "(default)" or "(override)" badge.
5. **Recent Commission Entries** — last 10 entries from `commission_entries`, same as today but with attribution context.

**Override resolution logic**:
- If employee has an override for the same `source`, it replaces the company default for that source
- If employee adds a rule for a source with no default, it's purely additional
- `CommissionService` resolves rules: check employee overrides first → fall back to company defaults for their role

**Validation update**: Allow all sources in the rule editor: `lead`, `estimate`, `job`, `door_knock`, `territory_override`

### 3. Attribution Chain on Deals/Jobs

New "Attribution & Commissions" section on Deal detail page and Job detail page.

**Shows**:
- Lead Source badge (Door Knock, Referral, Website, etc.)
- If door knock: Knocker name, knock date/address, territory path (Block > Zone > Region)
- Commission Chain: list of all `commission_entries` linked to this deal/job, grouped by person, showing role, source, amount, status
- Total Commissions: sum + percentage of revenue

**Data source**: Query `commission_entries` where `source_type` = Deal/Estimate/Job for this deal's chain. Cross-reference with door knock → territory hierarchy.

### 4. CommissionService Changes

**New method**: `getEffectiveRulesForEmployee(EmployeeProfile $employee, ?string $roleOverride = null): array`
1. Determine employee's territory role from `territory_team_members.role` (or use `$roleOverride`)
2. Get company defaults for that role from `CompanySetting::commission_defaults`
3. Get employee overrides from `employee_profiles.commission_rules`
4. Merge: overrides replace defaults where source matches, additional overrides are appended
5. Return effective rules array

**Updated methods**:
- `createFromDeal`, `createFromEstimate`, `createFromJob`, `createFromDoorKnock` — use `getEffectiveRulesForEmployee` instead of directly reading `employee_profiles.commission_rules`
- `createTerritoryOverrideCommissions` — resolve override rate from company defaults for `manager_override` role, allowing per-employee overrides

**New method**: `getCommissionChainForDeal(Deal $deal): array`
- Returns the full attribution chain: knocker, estimator, managers, with their commission entries
- Used by the Deal/Job attribution UI

### 5. Compensation Planner Update

Keep as projection/calculator but wire it to read from actual `CompanySetting::commission_defaults`. Sliders start at real values. Still saves scenarios separately — it's a "what if" tool, not a config tool.

### 6. Backend Connection Flow

```
CompanySetting.commission_defaults  ←── /settings/commissions UI
        │
        ├── role: 'knocker' rules
        ├── role: 'estimator' rules
        ├── role: 'manager_override' rules
        └── role: 'tiers' brackets
                │
                ▼
    CommissionService::getEffectiveRules($employee)
        │ 1. What role? (territory_team_members.role)
        │ 2. Get company defaults for that role
        │ 3. Merge with employee_profiles.commission_rules (overrides win)
        │ 4. Return effective rules
                │
                ▼
    Lifecycle triggers:
        Deal assigned     → createFromDeal()     → knocker rules
        Estimate accepted → createFromEstimate() → estimator rules
        Job completed     → createFromJob()       → assignee rules
                          → createTerritoryOverrideCommissions() → manager rules
        Door knock        → createFromDoorKnock() → knocker rules
```

### 7. Edge Cases

**Knocker outside their assigned territory**: DoorKnockService auto-resolves territory via geo-matching (ray casting on polygon boundaries). The knock gets tagged with the territory it's INSIDE, not the knocker's assigned territory. Override chain follows the geographic territory's managers. Knocker still gets personal commission regardless.

**Knock outside ALL territories**: `territory_id` = null. No territory override commissions fire. Knocker still gets personal commission.

**Non-door-knock lead sources**: Only door knock chain uses territory hierarchy for overrides. Referrals, website, Google Ads etc. use deal assignee + estimator commissions only. Architecture is extensible: adding `territory_id` to deals would enable territory attribution for all sources.

| Lead Source | Who Gets Commission | Territory Override? |
|---|---|---|
| Door Knock | Knocker + Estimator + Territory hierarchy managers | Yes (via knock geo) |
| Referral | Referrer (agent) + Estimator + Deal assignee | No |
| Website/Google Ads | Estimator + Deal assignee | No |
| Repeat customer | Estimator + Deal assignee | No |

### 8. Files to Create/Modify

**New files**:
- `resources/views/pages/settings/⚡commissions.blade.php` — Company defaults page
- Migration: add `commission_defaults` JSON column to `company_settings`

**Modified files**:
- `app/Models/CompanySetting.php` — add `commission_defaults` to fillable/casts, add getter methods
- `app/Services/CommissionService.php` — add `getEffectiveRulesForEmployee`, `getCommissionChainForDeal`, update lifecycle methods
- `resources/views/components/employees/⚡show.blade.php` — overhaul Commission tab with inherited + override view
- `resources/views/components/deals/⚡show.blade.php` (or equivalent) — add Attribution & Commissions section
- `resources/views/components/sales/⚡compensation-planner.blade.php` — wire to real defaults
- `resources/views/components/payroll/⚡commissions.blade.php` — enhance with attribution context
- `routes/web.php` — add `/settings/commissions` route

**Tests**:
- Commission defaults resolution tests
- Effective rules merging tests (defaults + overrides)
- Attribution chain query tests
- Settings page Livewire component tests
- Employee override UI tests
