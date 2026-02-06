# Public Proposal Page Overhaul Plan

## Problem Summary

The public customer proposal page (`/proposal/{token}`) has several issues:
1. **Package selection (Good/Better/Best) is buried** - shows below areas, line items, and options sections
2. **Math is wrong** - the "Pricing Summary" section at the bottom calculates independently from the selected package, showing raw `areas_subtotal` and `items_subtotal` instead of the package's total
3. **All data shows regardless of package** - areas, line items, and options sections display even when using the package template system (which has its own breakdown)
4. **No in-app notifications** - `EstimateAccepted` only sends via `mail`, not `database`
5. **No deal chat created** on acceptance

## Plan

### Task 1: Restructure Proposal Page Flow (estimate-proposal.blade.php)

**Goal**: Package selection first, then only show relevant breakdown from the selected package.

**Current page order**:
1. Status Banner
2. Estimate Header
3. Customer Information
4. Description
5. Areas Section (expandable)
6. Line Items Section
7. **Packages Section** (Good/Better/Best) ← buried here
8. Options Section
9. Photos
10. Notes
11. **Pricing Summary** ← wrong math, duplicates package totals
12. Terms
13. Prepared By
14. Accept Button

**New page order**:
1. Status Banner
2. Estimate Header (simplified)
3. Customer Information
4. Description
5. Photos (moved up - visual context before pricing)
6. **Packages Section** (Good/Better/Best) ← moved to TOP of pricing flow
7. Areas Section (collapsible detail, shows room-by-room breakdown from the estimate)
8. Notes
9. Terms
10. Prepared By
11. *(No separate Pricing Summary - the package component handles totals/deposit/signing)*

**Key changes in the PHP component**:
- Remove `getGrandTotal()`, `getSelectedOptionsTotal()` - no longer needed since packages handle totals
- Remove `getMarginFactor()`, `getAreaDisplayTotal()`, `getSurfaceDisplayTotal()` if only used for the removed pricing summary
- Keep the `openSignatureModal` and `submitSignature` methods
- Remove the separate "Line Items" and "Options" sections when packages exist (they're included IN the packages)
- The `Pricing Summary` card is removed entirely when packages are present - the ProposalPackages component already displays the total, deposit, and balance due

**What stays**: Areas section remains as an expandable "what's included" reference, but below the package selection. We keep `getAreaDisplayTotal()` and `getSurfaceDisplayTotal()` for the area expansion view.

### Task 2: Fix Package Template Pricing Display

In `proposal-packages-templates.blade.php`, the painting total correctly shows `areas_subtotal`. The template total calculation looks correct. No math changes needed here - the template system's pricing is correct.

The main issue is that the **parent page** also tries to show its own `Pricing Summary` which double-counts or shows different numbers. Removing that (Task 1) fixes the confusion.

### Task 3: Add Database Notification Channel

**File**: `app/Notifications/EstimateAccepted.php`

Change `via()` to return `['mail', 'database']`. The `toArray()` method already exists and returns the right data for database storage.

**Migration needed**: Create the `notifications` table:
```bash
php artisan make:notifications-table && php artisan migrate
```

### Task 4: Create Deal Chat Thread on Acceptance

**File**: `app/Models/EstimateSignature.php` (in the `booted()` created callback)

After the existing notification logic, add:
```php
// Create a deal chat thread for the accepted estimate
$deal = $signature->estimate->deal;
if ($deal) {
    $thread = $deal->getOrCreateChatThread();

    // Add internal users as participants
    $internalUsers = User::where('type', 'internal')->get();
    foreach ($internalUsers as $user) {
        if (!$thread->hasParticipant($user)) {
            $thread->users()->attach($user->id, [
                'joined_at' => now(),
            ]);
        }
    }

    // Post an initial message about the acceptance
    $thread->messages()->create([
        'user_id' => null, // system message
        'body' => "Estimate #{$signature->estimate->estimate_number} has been accepted by {$signature->signer_name}.",
        'type' => 'system',
    ]);

    // Move deal to 'scheduled' stage
    $deal->moveTo('scheduled');
}
```

### Task 5: Update Tests

- Update `EstimateProposalTest.php` to verify new page structure
- Add test for database notification being sent on acceptance
- Add test for deal chat thread creation on acceptance
- Add test for deal stage advancement on acceptance

## Files to Modify

1. `resources/views/components/public/⚡estimate-proposal.blade.php` - Restructure page flow
2. `app/Notifications/EstimateAccepted.php` - Add `database` channel
3. `app/Models/EstimateSignature.php` - Add chat thread creation on acceptance
4. `tests/Feature/Livewire/Public/EstimateProposalTest.php` - Update tests
5. New migration for `notifications` table

## Files NOT Modified (already correct)

- `resources/views/components/public/⚡proposal-packages.blade.php` - Package component is correct
- `resources/views/partials/public/proposal-packages-templates.blade.php` - Template math is correct
- `resources/views/partials/public/proposal-packages-legacy.blade.php` - Legacy math is correct
- `resources/views/partials/public/signature-modal.blade.php` - Signature modal is correct
