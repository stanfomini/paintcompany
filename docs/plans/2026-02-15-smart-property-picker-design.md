# Smart Property Picker in Customer Onboarding

**Date:** 2026-02-15
**Status:** Approved

## Problem

When an internal user creates a customer with a property and sends an invite, the customer goes through onboarding and sees a blank address form in Step 2. They re-enter the same address they already have on file. While the system prevents duplicate properties (address+city check), the UX is poor -- the customer doesn't know their property already exists.

## Solution

Replace the blank address form in Step 2 with a smart property picker:

- **Has existing properties**: Show selectable cards for each property, plus an "Add a New Property" card
- **No existing properties**: Show the current blank form (no change)

## Changes

**Single file:** `resources/views/components/customer/⚡onboarding.blade.php`

### New Properties/State

- `existingProperties` (Computed) - queries `$customer->properties`
- `selectedPropertyId` (public, nullable int) - ID of selected existing property
- `showNewPropertyForm` (public, bool) - toggles the address form when "Add New" is clicked

### Step 2 Logic

1. Check `$this->existingProperties->count()`
2. If > 0: render property cards grid + "Add New" card
3. Selecting a property card sets `$selectedPropertyId` and calls `nextStep()`
4. Clicking "Add New" sets `$showNewPropertyForm = true`, reveals address form
5. If 0 properties: show current form as-is

### Card Design

Each property card shows:
- Address (bold)
- City, State ZIP
- Property type badge (residential/commercial)
- Blue border when selected (matches Step 4 estimate preference cards)

"Add New Property" card:
- Plus icon
- "Add a New Property" text
- Dashed border

### complete() Updates

- If `$selectedPropertyId` is set: use that property for the Deal
- If `$showNewPropertyForm` and address fields filled: create new property (existing logic)
- Existing duplicate-prevention check stays as safety net

### Edge Cases

- Pre-filled address from `mount()`: still populates form fields, but cards shown first
- `isNewProject` flow: also shows property picker (returning customers pick or add)
- Agent-sourced flow: unchanged (skips Step 2 entirely)
