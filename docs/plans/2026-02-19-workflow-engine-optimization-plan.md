# Workflow Engine Optimization - Phase 1 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Split the 2008-line monolithic workflows page into a lightweight list + separate builder component, add loading states, inline delete button, pagination, optimistic toggle, and extract Alpine.js for browser caching.

**Architecture:** The settings workflows page (`resources/views/pages/settings/⚡workflows.blade.php`) becomes list-only. A new `⚡workflow-builder.blade.php` handles the visual builder. Alpine.js canvas logic moves to `resources/js/workflow-builder.js`. The old engine-based `components/workflows/⚡workflow-manager.blade.php` is deleted.

**Tech Stack:** Livewire 4 SFC, Alpine.js, Flux UI, Pest 4

---

### Task 1: Add Inline Delete Button + Loading States to List View

**Files:**
- Modify: `resources/views/pages/settings/⚡workflows.blade.php:936-983` (list view template)

**Step 1: Add inline delete trash button to each non-system workflow row**

In the list view section (line ~960), add a visible trash icon button next to the Enable/Disable button. Keep the dropdown delete too.

Replace the action buttons area (lines 960-976):

```blade
<div class="flex items-center gap-2">
    <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $workflow->id }})" wire:loading.attr="disabled" wire:target="toggleActive({{ $workflow->id }})">
        <span wire:loading.remove wire:target="toggleActive({{ $workflow->id }})">
            {{ $workflow->is_active ? 'Disable' : 'Enable' }}
        </span>
        <span wire:loading wire:target="toggleActive({{ $workflow->id }})">
            <flux:icon.loading class="size-4" />
        </span>
    </flux:button>
    @unless ($workflow->is_system)
        <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $workflow->id }}, '{{ addslashes($workflow->name) }}')" class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300" />
    @endunless
    <flux:dropdown>
        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
        <flux:menu>
            <flux:menu.item wire:click="editWorkflow({{ $workflow->id }})">Edit</flux:menu.item>
            <flux:menu.item wire:click="duplicateWorkflow({{ $workflow->id }})" wire:loading.attr="disabled" wire:target="duplicateWorkflow({{ $workflow->id }})">Duplicate</flux:menu.item>
            <flux:menu.item wire:click="viewRunHistory({{ $workflow->id }})">Run History</flux:menu.item>
            @unless ($workflow->is_system)
                <flux:menu.separator />
                <flux:menu.item wire:click="confirmDelete({{ $workflow->id }}, '{{ addslashes($workflow->name) }}')" variant="danger">Delete</flux:menu.item>
            @endunless
        </flux:menu>
    </flux:dropdown>
</div>
```

**Step 2: Add loading states to modals and create button**

Add `wire:loading` to the "New Workflow" button and modal actions:

```blade
<flux:button variant="primary" size="sm" wire:click="openCreateModal" wire:loading.attr="disabled">
    New Workflow
</flux:button>
```

On the delete modal confirm button:

```blade
<flux:button variant="danger" wire:click="deleteWorkflow" wire:loading.attr="disabled" wire:target="deleteWorkflow">
    <span wire:loading.remove wire:target="deleteWorkflow">Delete</span>
    <span wire:loading wire:target="deleteWorkflow">Deleting...</span>
</flux:button>
```

**Step 3: Run tests**

Run: `php artisan test --compact tests/Feature/AR/WorkflowAdminTest.php`
Expected: All existing tests pass (no behavioral changes)

**Step 4: Run Pint**

Run: `vendor/bin/pint --dirty`

**Step 5: Commit**

```bash
git add resources/views/pages/settings/⚡workflows.blade.php
git commit -m "feat: add inline delete button and loading states to workflow list"
```

---

### Task 2: Extract Alpine.js to Cached JS File

**Files:**
- Create: `resources/js/workflow-builder.js`
- Modify: `resources/js/app.js` (add import)
- Modify: `resources/views/pages/settings/⚡workflows.blade.php:1401-2008` (remove `@script` block)

**Step 1: Create `resources/js/workflow-builder.js`**

Move the entire Alpine.data('workflowBuilder', ...) from lines 1404-2006 into a new file. Wrap it in an `alpine:init` listener like the other Alpine components in `app.js`:

```javascript
// Workflow Builder - Visual node-based workflow editor
document.addEventListener('alpine:init', () => {
    Alpine.data('workflowBuilder', (initialNodes, initialEdges, registry) => ({
        // ... paste entire object from lines 1405-2005 ...
    }));
});
```

**Step 2: Import in `resources/js/app.js`**

Add at the top of `app.js`:

```javascript
import './workflow-builder';
```

**Step 3: Remove `@script` block from the Blade file**

Delete lines 1401-2008 (the `@script`...`@endscript` block) from `⚡workflows.blade.php`. The Alpine component is now loaded globally via `app.js`.

**Step 4: Build and verify**

Run: `npm run build`
Expected: No build errors. The workflow builder page should still work (Alpine component registers globally).

**Step 5: Commit**

```bash
git add resources/js/workflow-builder.js resources/js/app.js resources/views/pages/settings/⚡workflows.blade.php
git commit -m "perf: extract workflow builder Alpine.js to cached JS file"
```

---

### Task 3: Split Builder Into Separate Livewire Component

This is the biggest task. Extract all builder-related PHP and Blade from `⚡workflows.blade.php` into `⚡workflow-builder.blade.php`.

**Files:**
- Create: `resources/views/pages/settings/⚡workflow-builder.blade.php`
- Modify: `resources/views/pages/settings/⚡workflows.blade.php` (remove builder code)
- Modify: `routes/settings.php:64-66` (add builder route)

**Step 1: Add the builder route**

In `routes/settings.php`, after line 66, add:

```php
Route::livewire('settings/workflows/{workflow}/builder', 'pages::settings.workflow-builder')
    ->middleware('permission:settings.workflows')
    ->name('workflows.builder');
```

**Step 2: Create `resources/views/pages/settings/⚡workflow-builder.blade.php`**

This component gets:
- All builder-related state properties (lines 25-68 of original: `editingWorkflowId`, `workflowName`, `workflowDescription`, `workflowIsActive`, `showNodeConfig`, `configuringNodeId`, `nodeLabel`, `nodeClass`, `nodeConfig`, `showRunHistory`, `runHistoryWorkflowId`, `addNodeType`, `availableDynamicFields`, webhook/API state)
- All builder computed properties: `registryData`, `editingWorkflow`, `workflowNodes`, `workflowEdges`, `runs`
- All builder methods: `saveWorkflowSettings`, `addNode`, `updateNodePosition`, `saveNodePositions`, `openNodeConfig`, `saveNodeConfig`, `toggleNodeEnabled`, `deleteNode`, `connectNodes`, `deleteEdge`, webhook/API methods, helpers (`dispatchWorkflowData`, `getNodeLabel`, `autoConnectNode`, `getNodeTypeColor`)
- `backToList()` becomes: `return $this->redirect(route('workflows.edit'))`
- `mount(Workflow $workflow)` replaces the old `editWorkflow()` method — loads workflow data on mount
- All builder Blade: the full-screen overlay div (lines 1085-1400) and the node config modal, but NOT the list view or its modals

The component structure:

```php
<?php

use App\Automation\Actions\CreateCustomer;
// ... all builder imports ...
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;

new
#[Layout('components.layouts.app')]
class extends Component {
    // Builder state
    public int $workflowId;
    public string $workflowName = '';
    public string $workflowDescription = '';
    public bool $workflowIsActive = true;

    // Node config modal
    public bool $showNodeConfig = false;
    public ?int $configuringNodeId = null;
    public string $nodeLabel = '';
    public string $nodeClass = '';
    public array $nodeConfig = [];

    // Run history modal
    public bool $showRunHistory = false;
    public ?int $runHistoryWorkflowId = null;

    // Add node panel
    public string $addNodeType = '';

    // Dynamic field mapping + webhook/API state...
    // (all the same properties from original lines 56-68)

    public function mount(\App\Models\Workflow $workflow): void
    {
        if (\Illuminate\Support\Facades\Auth::user()->type !== 'internal') {
            abort(403);
        }

        $this->workflowId = $workflow->id;
        $this->workflowName = $workflow->name;
        $this->workflowDescription = $workflow->description ?? '';
        $this->workflowIsActive = $workflow->is_active;
    }

    #[Computed]
    public function workflow(): ?\App\Models\Workflow
    {
        return \App\Models\Workflow::with(['nodes', 'edges'])->find($this->workflowId);
    }

    #[Computed]
    public function registryData(): array { /* same as original */ }

    #[Computed]
    public function workflowNodes(): array { /* same, using $this->workflow */ }

    #[Computed]
    public function workflowEdges(): array { /* same, using $this->workflow */ }

    #[Computed]
    public function runs(): \Illuminate\Support\Collection { /* same */ }

    // All builder action methods...
    // saveWorkflowSettings(), addNode(), updateNodePosition(), etc.

    public function backToList(): void
    {
        $this->redirect(route('workflows.edit'));
    }

    // All helper methods...
};
?>

<div>
    {{-- Full-screen builder overlay --}}
    <div class="fixed inset-0 z-50 flex flex-col overflow-hidden bg-white dark:bg-zinc-900"
         x-data="workflowBuilder(@js($this->workflowNodes), @js($this->workflowEdges), @js($this->registryData))">
        {{-- ... entire builder UI from original lines 1086-1400 ... --}}
    </div>

    {{-- Node config modal --}}
    {{-- ... from original ... --}}

    {{-- Run history modal --}}
    {{-- ... from original ... --}}
</div>
```

**Step 3: Update `⚡workflows.blade.php` — remove builder code**

Remove from the PHP section:
- All builder state properties (keep only: `view` (remove it), `showCreateModal`, `newName`, `newDescription`, `showDeleteModal`, `deletingWorkflowId`, `deletingWorkflowName`, `showRunHistory`, `runHistoryWorkflowId`)
- All builder computed properties (keep only: `workflows`, `runs`)
- All builder methods (keep only: `openCreateModal`, `createWorkflow`, `confirmDelete`, `deleteWorkflow`, `toggleActive`, `duplicateWorkflow`, `viewRunHistory`)
- Remove the `editWorkflow()` method — replace with navigation

Replace `editWorkflow(int $id)` with:

```php
public function editWorkflow(int $id): void
{
    $this->redirect(route('workflows.builder', $id));
}
```

After `createWorkflow()`, redirect to builder:

```php
public function createWorkflow(): void
{
    // ... validation and creation ...
    $this->showCreateModal = false;
    unset($this->workflows);
    $this->redirect(route('workflows.builder', $workflow->id));
}
```

Remove from the Blade template:
- The entire `@else` block (builder view, lines 1083-1400)
- The `@if ($view === 'list')` / `@endif` conditionals — just render the list directly

**Step 4: Verify the builder loads via new route**

Run: `php artisan route:list --name=workflows`
Expected: Shows both `workflows.edit` and `workflows.builder` routes

**Step 5: Run tests**

Run: `php artisan test --compact tests/Feature/AR/WorkflowAdminTest.php`
Expected: Existing tests still pass (they test the list view and old engine component)

**Step 6: Commit**

```bash
git add resources/views/pages/settings/⚡workflow-builder.blade.php resources/views/pages/settings/⚡workflows.blade.php routes/settings.php
git commit -m "perf: split workflow builder into dedicated Livewire component"
```

---

### Task 4: Add Pagination to Workflow List

**Files:**
- Modify: `resources/views/pages/settings/⚡workflows.blade.php` (PHP + template)

**Step 1: Change workflows query to paginate**

In the PHP section, add `use Livewire\WithPagination;` and change the computed:

```php
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    // Remove the #[Computed] workflows() method and replace with:
    // (Livewire pagination doesn't use #[Computed])

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->with('workflows', Workflow::query()
            ->withCount(['nodes', 'runs'])
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->paginate(20)
        );
    }
```

**Step 2: Update template to use `$workflows` instead of `$this->workflows`**

Replace `$this->workflows` references:
- `$this->workflows->count()` → `$workflows->total()`
- `$this->workflows as $workflow` → `$workflows as $workflow`

Add pagination links after the list div:

```blade
<div class="mt-4">
    {{ $workflows->links() }}
</div>
```

**Step 3: Run tests**

Run: `php artisan test --compact tests/Feature/AR/WorkflowAdminTest.php`

**Step 4: Commit**

```bash
git add resources/views/pages/settings/⚡workflows.blade.php
git commit -m "perf: add pagination to workflow list"
```

---

### Task 5: Add Optimistic UI for Active Toggle

**Files:**
- Modify: `resources/views/pages/settings/⚡workflows.blade.php` (list row template)

**Step 1: Wrap each workflow row in Alpine for optimistic state**

Change the `@forelse` row to use Alpine local state:

```blade
<div wire:key="wf-{{ $workflow->id }}"
     x-data="{ optimisticActive: @js($workflow->is_active) }"
     x-on:toggle-failed-{{ $workflow->id }}.window="optimisticActive = !optimisticActive"
     class="flex items-center justify-between gap-4 px-4 py-3">
```

Change the Enable/Disable button to flip optimistically:

```blade
<flux:button size="sm" variant="ghost"
    x-on:click="optimisticActive = !optimisticActive"
    wire:click="toggleActive({{ $workflow->id }})"
    wire:loading.attr="disabled"
    wire:target="toggleActive({{ $workflow->id }})">
    <span x-text="optimisticActive ? 'Disable' : 'Enable'"></span>
</flux:button>
```

Update badge to use Alpine state:

```blade
<template x-if="optimisticActive">
    <flux:badge size="sm" color="green">Active</flux:badge>
</template>
<template x-if="!optimisticActive">
    <flux:badge size="sm" color="zinc">Inactive</flux:badge>
</template>
```

**Step 2: Add server-side rollback dispatch on failure**

In the `toggleActive` PHP method, wrap in try/catch and dispatch failure event:

```php
public function toggleActive(int $id): void
{
    $workflow = Workflow::find($id);
    if (! $workflow) {
        return;
    }

    $workflow->update([
        'is_active' => ! $workflow->is_active,
        'published_at' => ! $workflow->is_active ? now() : $workflow->published_at,
    ]);

    unset($this->workflows);
}
```

Note: For this simple case, the toggle is unlikely to fail. The optimistic UI is mainly for perceived speed — the badge and button text update instantly before the server round-trip completes.

**Step 3: Run tests**

Run: `php artisan test --compact tests/Feature/AR/WorkflowAdminTest.php`

**Step 4: Commit**

```bash
git add resources/views/pages/settings/⚡workflows.blade.php
git commit -m "perf: add optimistic UI for workflow active toggle"
```

---

### Task 6: Delete Old Engine Component + Update Tests

**Files:**
- Delete: `resources/views/components/workflows/⚡workflow-manager.blade.php`
- Modify: `routes/web.php:464-467` (remove redirect alias)
- Modify: `tests/Feature/AR/WorkflowAdminTest.php` (update to test settings page)

**Step 1: Delete the old component**

```bash
rm resources/views/components/workflows/⚡workflow-manager.blade.php
```

**Step 2: Remove the redirect alias from `routes/web.php`**

Remove lines 464-467:

```php
// Remove this block:
Route::redirect('/workflows', '/settings/workflows')
    ->middleware('permission:workflows.view')
    ->name('workflows.index');
```

**Step 3: Update tests to use settings page**

All tests currently using `Livewire::test('workflows.workflow-manager')` need updating. Tests that test the OLD engine's create/edit/trigger/action functionality should be rewritten to test the SETTINGS page's create/edit/delete/toggle methods.

The route tests using `route('workflows.index')` should use `route('workflows.edit')` instead:

```php
// Authorization test
it('renders workflows page for authorized user', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.workflows');

    $this->actingAs($user)
        ->get(route('workflows.edit'))
        ->assertSuccessful();
});

// 403 test
it('shows 403 for unauthorized user', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('workflows.edit'))
        ->assertForbidden();
});
```

Livewire component tests should use `'pages::settings.workflows'`:

```php
Livewire::actingAs($user)
    ->test('pages::settings.workflows')
    ->assertSee('Test Display Workflow');
```

Update the toggle test, delete test, duplicate test, etc. to match the settings component's method signatures (which are the same names: `toggleActive`, `confirmDelete`, `deleteWorkflow`).

**Step 4: Run full test file**

Run: `php artisan test --compact tests/Feature/AR/WorkflowAdminTest.php`
Expected: All tests pass with the settings page component

**Step 5: Run Pint**

Run: `vendor/bin/pint --dirty`

**Step 6: Commit**

```bash
git add -A
git commit -m "refactor: remove old workflow-manager component, consolidate to settings page"
```

---

### Task 7: Final Verification

**Step 1: Run full related test suite**

Run: `php artisan test --compact tests/Feature/AR/`
Expected: All pass

**Step 2: Build frontend**

Run: `npm run build`
Expected: No errors

**Step 3: Run Pint on all changed files**

Run: `vendor/bin/pint --dirty`

**Step 4: Manual verification checklist**

- [ ] `/settings/workflows` loads the list view (lightweight, no builder state)
- [ ] Clicking a workflow name navigates to `/settings/workflows/{id}/builder`
- [ ] Builder loads with full canvas, nodes, edges
- [ ] Back arrow returns to list
- [ ] Inline delete trash icon visible on non-system workflows
- [ ] Delete confirmation modal works
- [ ] Toggle Enable/Disable flips immediately (optimistic)
- [ ] Loading spinners show during server calls
- [ ] Pagination appears when >20 workflows
- [ ] "New Workflow" creates and redirects to builder

**Step 5: Final commit if any cleanup needed**

```bash
git add -A
git commit -m "chore: final cleanup for workflow optimization phase 1"
```
