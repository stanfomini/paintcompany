# Mobile Safe Area Fix: AI Chat Panel & Area Builder Modal

**Date:** 2026-03-20
**Scope:** Two components only — AI chat panel in estimate builder, area builder flyout modal

## Problem

In PWA standalone mode on iOS devices with notch/Dynamic Island, two components extend behind the device status bar (time, battery) and home indicator:

1. **AI Chat Panel** (`⚡template-builder.blade.php` ~line 4395): Uses `fixed inset-y-0 right-0` — stretches full height behind notch and home indicator. Header and input controls are obscured.

2. **Area Builder Modal** (`⚡area-builder.blade.php` + `app.css` lines 132-139): CSS forces `height: 100dvh` on mobile. Header sits behind the notch with no top padding.

## Root Cause

The app's safe-area CSS rules (lines 80-124 in `app.css`) target Flux layout data attributes (`[data-flux-header]`, `[data-flux-sidebar]`, etc.). These two components use custom `fixed` positioning and bypass the Flux layout system, so they don't receive safe-area padding.

## Fix

### AI Chat Panel

Add safe-area padding to the inner content elements (not the outer container, which needs full-height for the background):

- **Header** (line 4402): Add `padding-top: env(safe-area-inset-top)` — pushes content below the notch
- **Input area** (line 4509): Add `padding-bottom: env(safe-area-inset-bottom)` — pushes content above the home indicator

Implementation: Inline styles on the header and input divs, matching the pattern used by the chat dock components.

### Area Builder Modal

Add safe-area rules to the existing `.area-builder-flyout` CSS in `app.css`:

- Within the `@media (display-mode: standalone)` block, add top padding to the flyout panel header and bottom safe-area to the container on mobile screens.

Implementation: CSS rules in `app.css` alongside the existing flyout mobile override.

## Files Changed

1. `resources/views/components/estimates/⚡template-builder.blade.php` — inline safe-area styles on AI chat header and input
2. `resources/css/app.css` — safe-area rules for `.area-builder-flyout` in standalone mode on mobile

## Testing

- Open PWA on iOS device with notch/Dynamic Island
- Open estimate builder, click AI editor — header should be visible below status bar, input above home indicator
- Open area builder modal — header should be visible below status bar
- Verify desktop layout is unaffected (safe-area values are 0 on desktop)
