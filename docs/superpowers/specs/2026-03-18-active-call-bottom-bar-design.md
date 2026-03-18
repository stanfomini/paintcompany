# Active Call Bar — Bottom Collapsible Design

**Date:** 2026-03-18
**Status:** Approved

## Problem

The active call banner (`⚡active-call.blade.php`) is fixed to `top-0` on the screen. On mobile PWA, the phone's status bar / notch covers it, making the Transfer and Hang Up buttons unreachable.

## Solution

Relocate the active call bar to sit directly above the footer bar. Make it collapsible — the info row (caller + timer) is always visible, and the actions row (Transfer, Hang Up) slides upward on tap. Disappears instantly on hang up or transfer; appears instantly when a call connects.

## Layout

### Placement in `sidebar.blade.php`

Move `@livewire('communications.active-call')` from the overlays section to between `{{ $slot }}` and `<flux:footer>`, inside the existing `@auth @if(auth()->user()->isInternal())` block:

```
{{ $slot }}

@auth
    @if (auth()->user()->isInternal())
        @can('phone.make_calls')
            @livewire('communications.active-call')   ← new position
        @endcan

        <flux:footer class="!p-0">
            @livewire('layout.footer-bar')
        </flux:footer>
    @endif
@endauth
```

Remove `@livewire('communications.active-call')` from the overlays/modals block below. The `flux:footer` block currently has its own `@auth @if` wrapper — merge them into one block as shown above.

### Visual Structure

**Collapsed (default on new call: expanded = true, but user can collapse):**
```
─────────────────────────────────
[ 🟢 • John Smith   00:35    ▸ ]   ← info row (always visible)
[ Footer: 🔔  📹  📞  💬  ✉️  ]
```

**Expanded (actions above info row):**
```
─────────────────────────────────
[ ↗ Transfer       📵 Hang Up  ]   ← actions row (slides upward)
[ 🟢 • John Smith   00:35    ▾ ]   ← info row
[ Footer: 🔔  📹  📞  💬  ✉️  ]
```

## Component Design

### DOM Order

Actions row comes first in the DOM (rendered above info row visually):

```
<div>  ← outer wrapper, only rendered when $isActive
  <div x-show="expanded" x-transition>  ← actions row
    [Transfer button] [Hang Up button]
  </div>
  <div>  ← info row, always visible
    [pulse dot] [phone icon] [caller name] [timer] [chevron toggle]
  </div>
</div>
```

### Alpine.js State

```js
x-data="{ expanded: true, seconds: 0, timer: null }"
```

- Default: `expanded: true` (actions visible immediately when call connects)
- **Chevron icon**: `chevron-up` when expanded, `chevron-down` when collapsed (standard convention)
- **Transition**: Use a simple fade (`x-transition` default) on the actions row — no custom slide classes needed
- **Timer reset**: The `seconds` counter must reset to `0` when a new call connects. Move the timer Alpine state (`seconds`, `timer`) into the outer `x-data` block. Add an `x-on:livewire:call-connected.window="seconds = 0"` listener, or reset it inside the `init()` by watching for `$isActive` changes. Simplest approach: move `seconds` to outer scope and reset it in the Livewire `@js` event or via `$watch`.
- **Transfer picker collapse**: When the user collapses the bar (`expanded = false`), also close the Livewire transfer picker by dispatching `wire:click="closeTransferPicker"`. Add a `closeTransferPicker()` method to the Livewire class that sets `$this->showTransferPicker = false`.

### Positioning

Remove `fixed top-0 inset-x-0 z-50`. The component is now part of the normal document flow — no positioning classes needed. It will naturally sit between content and footer.

### Styling

Match the footer's dark theme with a green accent to distinguish it as an active call:

- **Background**: `bg-green-700 dark:bg-green-800`
- **Text**: white
- **Info row**: `px-4 py-2.5 flex items-center justify-between gap-4`
- **Actions row**: `px-4 py-2 flex items-center gap-2 border-b border-green-600`
- **Hang Up button**: `bg-red-500 hover:bg-red-600`
- **Transfer button**: `bg-white/20 hover:bg-white/30`

### Transfer Picker Direction

Change the transfer picker dropdown from `top-full mt-2` to `bottom-full mb-2` so it opens upward (away from the footer). Add `z-50` to the picker div to ensure it stacks above the footer and isn't clipped by any parent overflow context.

### Appear / Disappear

No changes needed — already instant via existing Livewire WebSocket listeners:
- **Appear**: `onCallConnected()` sets `$isActive = true`
- **Disappear**: `resetState()` sets `$isActive = false` — called by both `hangup()` and `transfer()`

## Files Changed

| File | Change |
|------|--------|
| `resources/views/layouts/app/sidebar.blade.php` | Move active-call include above `flux:footer`, remove from overlays block |
| `resources/views/components/communications/⚡active-call.blade.php` | Restyle: remove `fixed top-0`, add collapsible two-row layout, flip transfer picker direction, add `closeTransferPicker()` method |

## Out of Scope

- Desktop layout (sidebar view) — no change needed, this only affects the mobile PWA footer area
- Any changes to the Livewire PHP class — all logic already works correctly
