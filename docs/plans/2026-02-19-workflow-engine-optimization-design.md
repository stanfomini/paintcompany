# Workflow Engine Optimization & Consolidation

**Date**: 2026-02-19
**Status**: In Progress
**Branch**: `feature/ar-portal-workflow-engine`

## Problem

The settings workflows page (`resources/views/pages/settings/⚡workflows.blade.php`) has performance issues:
- 2008-line monolithic component with list view, visual builder, modals, and ~600 lines of Alpine.js
- Every Livewire round-trip serializes ALL state even when viewing the list
- `registryData` computed loads on every render (iterates all trigger/action classes)
- No pagination on workflow list
- No loading states — page feels frozen during server calls
- Node dragging triggers full Alpine reactivity (`this.nodes = this.nodes` hack)
- Delete button buried in dropdown menu

Additionally, there's an old engine-based component (`components/workflows/⚡workflow-manager.blade.php`) that should be consolidated into the visual builder.

## Phases

### Phase 1: Performance + Delete Button (CURRENT)

#### 1.1 Split Component
- Extract builder view into `resources/views/pages/settings/⚡workflow-builder.blade.php`
- List view stays in `⚡workflows.blade.php` (lightweight)
- Navigate to builder via `route('workflows.builder', $id)`
- Builder component only loads registryData, nodes, edges when mounted

#### 1.2 Lazy-Load Registry
- Remove `registryData` computed from list view entirely
- Only fetch in builder component's `mount()`

#### 1.3 Loading States
- `wire:loading` on: toggle active, delete, duplicate, edit, create
- `wire:loading.attr="disabled"` on buttons
- Skeleton placeholders for list while loading

#### 1.4 Optimistic UI for Toggle
- Toggle switch flips instantly via Alpine
- Reverts on server error

#### 1.5 Delete Button
- Inline trash icon on every non-system workflow row
- Keep in `...` dropdown as well
- Confirmation modal (already exists)

#### 1.6 Extract Alpine JS
- Move `workflowBuilder` Alpine component to `resources/js/workflow-builder.js`
- Import in `app.js`
- Browser caches JS file instead of re-parsing on every Livewire morph

#### 1.7 Paginate Workflows
- Use `->paginate(20)` instead of `->get()`
- Add pagination links below list

### Phase 2: Consolidate Old Engine Component

Merge unique features from `⚡workflow-manager.blade.php` into settings builder:

- **Service category scoping** — add to workflow create/edit (per-workflow service filter)
- **User scope** — (any/specific_user/role/user_type) as workflow-level setting
- **Priority ordering** — sortable priority field on workflows
- **Debit/credit account codes** — on action node config
- **Execution logs** — port log viewer modal into builder and list view
- **Engine action types** — ensure all action types (create_commission, create_flat_rate, create_payable, update_field, send_notification, create_invoice) are registered in the Registry

Then:
- Delete `resources/views/components/workflows/⚡workflow-manager.blade.php`
- Remove `workflows.workflow-manager` route from `routes/web.php`
- Update tests to use settings page route

### Phase 3: Power Up Visual Builder (Future)

Make the visual builder THE single control center:
- Commission workflows with dynamic percentage/flat rate calculations
- Multi-step approval chains
- Conditional branching on deal amount, customer type, etc.
- Notification templates with dynamic field insertion
- Invoice generation workflows
- Crew payment workflows (deposit + final)
- Integration with external APIs (already started)
- Webhook triggers (already started)
- Test/dry-run mode for workflows

## Key Files

- `resources/views/pages/settings/⚡workflows.blade.php` — Main settings page (list + builder)
- `resources/views/components/workflows/⚡workflow-manager.blade.php` — OLD engine component (to delete in Phase 2)
- `routes/settings.php` — Settings route (`workflows.edit`)
- `routes/web.php` — Web routes (has redirect alias `workflows.index`)
- `tests/Feature/AR/WorkflowAdminTest.php` — Tests for old component
- `app/Automation/Registry.php` — Trigger/action/condition registry
- `app/Automation/Actions/` — Action classes
- `app/Automation/Triggers/` — Trigger classes

## Phase 1 Task Checklist

- [ ] Add inline delete button to workflow list rows
- [ ] Add loading states to all interactive elements
- [ ] Extract builder into separate `⚡workflow-builder.blade.php` component
- [ ] Add route for builder page
- [ ] Move registryData, node/edge logic to builder component only
- [ ] Extract Alpine.js `workflowBuilder` to `resources/js/workflow-builder.js`
- [ ] Add pagination to workflow list
- [ ] Add optimistic UI for active toggle
- [ ] Test all changes
- [ ] Run Pint
