# Job Cost Card Detail Page - Handoff Prompt

## Copy everything below this line as your prompt:

---

## Goal

Rewrite the Job Cost Card detail page (`resources/views/components/jobs/⚡cost-card.blade.php`) so its **Projected Costs** tab (Tab 1) renders the exact same rich financial ledger as the Estimate Breakdown page (`resources/views/components/estimates/⚡cost-breakdown.blade.php`). The cost card should call `EstimateCostBreakdownService::calculateBreakdown()` live (via the job's linked estimate) instead of displaying stored `JobCostCard` column values. Tabs 2 (Actuals & Variance) and 3 (Payments) should remain unchanged — they correctly use the `JobCostCard` model.

## What Has Already Been Implemented (current state on disk)

A previous session rewrote the file. Here's what was done:

### PHP section (working correctly):
- `EstimateCostBreakdownService` is injected in `boot()`
- `#[Computed] breakdown()` calls the service live on `$this->job->estimate`
- `#[Computed] packages()` lists available packages for switcher
- `selectPackage()` method handles package switching
- `selectedPackageId` property auto-selects the active package in `mount()`
- All existing actuals/payments/settingsDrift methods are preserved

### Template Tab 1 (needs review/improvement):
The previous session copied the layout from the breakdown page. The following sections exist:
1. Package Switcher (clickable cards)
2. Warranty Reserve status banner
3. Summary Cards (Customer Price, Gross Profit, Net Profit, Target Delta)
4. Financial Ledger (debit/credit style with Revenue, COGS, Add-ons, Gross Profit, Allocations, Crew Bonus, Net Profit, Verification Row)
5. Per-Area Detail Table
6. Reconciliation drift check
7. Settings Used collapsible
8. Settings Drift Warning

### Tests (15 passing):
`tests/Feature/Jobs/JobCostCardDetailTest.php` was rewritten with a `createJobWithEstimateData()` helper that creates proper estimate + area + surface data for the breakdown service.

## What Needs Review / What May Be Wrong

The user was not satisfied with the results. Possible issues to investigate:

1. **Visual comparison**: Open both pages side-by-side in the browser:
   - `/estimates/{estimate}/breakdown` (the gold standard)
   - `/jobs/{job}/cost-card` (what we're fixing)
   - Compare them pixel-by-pixel. Are there layout differences, missing sections, styling mismatches?

2. **Data accuracy**: Does the cost card show the same numbers as the breakdown page for the same estimate? The cost card calls `calculateBreakdown($this->job->estimate)` — verify the numbers match.

3. **Edge cases**: Does it handle:
   - Jobs where the estimate has no areas (just line items)?
   - Jobs where no estimate is linked at all?
   - Jobs with packages vs without packages?
   - The "No Cost Card" callout was removed from the projected tab (since it now uses live breakdown data, not the card). Was this the right call?

4. **Missing features from breakdown page**:
   - Does the Internal Only Warning banner appear? (The breakdown page has one)
   - Are Export CSV / Export PDF / Print buttons needed on the cost card?
   - Is the Settings Comparison tab (from the breakdown page) needed here?

5. **Styling consistency**: The cost card was changed from `max-w-4xl` to `max-w-6xl`. Is this correct for the job pages? Check what other job page components use.

## Key Files

| File | Purpose |
|------|---------|
| `resources/views/components/jobs/⚡cost-card.blade.php` | **The file being rewritten** (1052 lines currently) |
| `resources/views/components/estimates/⚡cost-breakdown.blade.php` | **The gold standard** to match (902 lines) |
| `app/Services/EstimateCostBreakdownService.php` | Service that calculates live breakdown (no changes needed) |
| `app/Models/JobCostCard.php` | Model for stored card data (used in Tabs 2 & 3) |
| `tests/Feature/Jobs/JobCostCardDetailTest.php` | Tests (15 passing, may need updates) |
| `tests/Feature/BusinessPlan/JobCostCardUpgradeTest.php` | Related tests (10 passing) |
| `tests/Feature/BusinessPlan/JobCostCardTest.php` | Related tests (37 passing) |

## How to Verify

1. Run tests: `php artisan test --compact tests/Feature/Jobs/JobCostCardDetailTest.php`
2. Run related: `php artisan test --compact tests/Feature/BusinessPlan/JobCostCardUpgradeTest.php`
3. Run breakdown tests: `php artisan test --compact tests/Feature/Livewire/Estimates/CostBreakdownTest.php`
4. Open both pages in browser and compare visually
5. Check that Tabs 2 & 3 still work correctly

## Architecture Reminders

- **PBP Formula**: COGS / (margin% / 100) = customer price. 50% margin means COGS is 50% of price.
- **Per-area margins**: Each area has its own scope_type (interior/exterior) with separate settings.
- **Breakdown service**: `calculateBreakdown()` returns a deeply nested array. See the service for the full structure.
- **Livewire SFC**: All components use `⚡` prefix single-file-component pattern (PHP at top, template below).
- **The `$card` variable**: Still needed for Tab 2 (actuals/variance) and Tab 3 (payments). Only Tab 1 switched to live breakdown data.
