# Per-Surface Tier Inclusion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow each surface within an area to be independently included/excluded from Good/Better/Best package tiers, with a company/estimate setting controlling whether excluded surfaces are hidden or shown (grayed out) on proposals.

**Architecture:** Add `package_tiers` JSON column to `estimate_area_surfaces` (mirroring the existing area-level pattern). Filter surfaces by tier in all pricing calculations. Add `excluded_surfaces_display` setting to CompanySetting (company default) and Estimate (per-estimate override). Surface tier toggles appear as G/B/B+ pill buttons on each surface row in the area builder when tier pricing is enabled.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Pest 4, Tailwind v4

---

## File Map

| Action | File | Responsibility |
|--------|------|---------------|
| Create | `database/migrations/XXXX_add_package_tiers_to_estimate_area_surfaces_table.php` | Add `package_tiers` JSON column |
| Create | `database/migrations/XXXX_add_excluded_surfaces_display_settings.php` | Add `excluded_surfaces_display` to `company_settings` and `estimates` |
| Modify | `app/Models/EstimateAreaSurface.php` | Add `package_tiers` to fillable/casts, add `isIncludedInTier()` method |
| Modify | `app/Models/EstimateArea.php:395-476` | Filter surfaces by `isIncludedInTier()` in `getFullCOGS()` and `getCustomerPrice()` |
| Modify | `app/Models/CompanySetting.php:21` | Add `excluded_surfaces_display` to fillable |
| Modify | `app/Models/Estimate.php:41` | Add `excluded_surfaces_display` to fillable, add helper method |
| Modify | `database/factories/EstimateAreaSurfaceFactory.php` | Add `forTiers()` state method |
| Modify | `resources/views/components/estimates/⚡area-builder.blade.php` | Add G/B/B+ toggle buttons per surface, `toggleSurfaceTier()` method |
| Modify | `resources/views/components/estimates/⚡template-builder.blade.php` | Persist `package_tiers` in surface data through save flow |
| Modify | `resources/views/components/public/⚡estimate-proposal.blade.php` | Respect `excluded_surfaces_display` when rendering surfaces |
| Modify | `resources/views/components/public/⚡proposal-packages.blade.php` | Filter surfaces by tier in package view |
| Create | `tests/Feature/SurfaceTierInclusionTest.php` | Core model tests for surface tier filtering |
| Create | `tests/Feature/Livewire/Estimates/SurfaceTierToggleTest.php` | UI toggle tests |

---

### Task 1: Migration — Add `package_tiers` to `estimate_area_surfaces`

**Files:**
- Create: `database/migrations/2026_03_21_000001_add_package_tiers_to_estimate_area_surfaces_table.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration add_package_tiers_to_estimate_area_surfaces_table --table=estimate_area_surfaces --no-interaction
```

Then replace content with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->json('package_tiers')->nullable()->after('tier_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('estimate_area_surfaces', function (Blueprint $table) {
            $table->dropColumn('package_tiers');
        });
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/*package_tiers_to_estimate_area_surfaces*
git commit -m "feat(estimates): add package_tiers column to estimate_area_surfaces table"
```

---

### Task 2: Migration — Add `excluded_surfaces_display` to `company_settings` and `estimates`

**Files:**
- Create: `database/migrations/2026_03_21_000002_add_excluded_surfaces_display_settings.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration add_excluded_surfaces_display_settings --no-interaction
```

Then replace content with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('excluded_surfaces_display', 20)->default('hidden')->after('estimate_builder_instructions');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->string('excluded_surfaces_display', 20)->nullable()->after('paint_tiers');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('excluded_surfaces_display');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn('excluded_surfaces_display');
        });
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/*excluded_surfaces_display*
git commit -m "feat(estimates): add excluded_surfaces_display to company_settings and estimates"
```

---

### Task 3: Model — `EstimateAreaSurface` tier inclusion

**Files:**
- Modify: `app/Models/EstimateAreaSurface.php`
- Test: `tests/Feature/SurfaceTierInclusionTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/SurfaceTierInclusionTest.php`:

```php
<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;

beforeEach(function () {
    CompanySetting::factory()->create();
});

it('includes surface in all tiers when package_tiers is null', function () {
    $surface = EstimateAreaSurface::factory()->create(['package_tiers' => null]);

    expect($surface->isIncludedInTier('good'))->toBeTrue();
    expect($surface->isIncludedInTier('better'))->toBeTrue();
    expect($surface->isIncludedInTier('best'))->toBeTrue();
});

it('includes surface only in specified tiers', function () {
    $surface = EstimateAreaSurface::factory()->create([
        'package_tiers' => ['better', 'best'],
    ]);

    expect($surface->isIncludedInTier('good'))->toBeFalse();
    expect($surface->isIncludedInTier('better'))->toBeTrue();
    expect($surface->isIncludedInTier('best'))->toBeTrue();
});

it('includes surface in single tier only', function () {
    $surface = EstimateAreaSurface::factory()->create([
        'package_tiers' => ['best'],
    ]);

    expect($surface->isIncludedInTier('good'))->toBeFalse();
    expect($surface->isIncludedInTier('better'))->toBeFalse();
    expect($surface->isIncludedInTier('best'))->toBeTrue();
});

it('excludes surface from area COGS when not in tier', function () {
    $area = EstimateArea::factory()->create();

    // Walls: included in all tiers
    EstimateAreaSurface::factory()->forArea($area)->wall()->create([
        'labor_cost' => 100,
        'material_cost' => 50,
        'total' => 150,
        'is_included' => true,
        'package_tiers' => null,
    ]);

    // Crown molding: best only
    EstimateAreaSurface::factory()->forArea($area)->trim()->create([
        'labor_cost' => 40,
        'material_cost' => 20,
        'total' => 60,
        'is_included' => true,
        'package_tiers' => ['best'],
    ]);

    // Good tier: only walls
    $goodCogs = $area->getFullCOGS('good', bypassCache: true);
    // Best tier: walls + crown molding
    $bestCogs = $area->getFullCOGS('best', bypassCache: true);

    expect($bestCogs)->toBeGreaterThan($goodCogs);
});

it('preserves pricing data when toggling tier inclusion', function () {
    $surface = EstimateAreaSurface::factory()->create([
        'package_tiers' => ['best'],
        'tier_overrides' => [
            'best' => ['labor_hours' => 5.0, 'labor_cost' => 150.0, 'material_cost' => 50.0],
        ],
    ]);

    // Pricing data should still be accessible even when excluded from good
    expect($surface->getLaborHoursForTier('best'))->toBe(5.0);
    expect($surface->isIncludedInTier('good'))->toBeFalse();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/SurfaceTierInclusionTest.php
```

Expected: FAIL — `isIncludedInTier` method doesn't exist yet.

- [ ] **Step 3: Add `package_tiers` to model**

In `app/Models/EstimateAreaSurface.php`:

Add `'package_tiers'` to the `$fillable` array (after `'tier_overrides'`, line 49).

Add `'package_tiers' => 'array'` to the `casts()` method (after `'tier_overrides' => 'array'`, line 74).

Add the `isIncludedInTier` method in the "PER-TIER CONFIGURATION METHODS" section (after `hasTierOverrides()`, around line 469):

```php
/**
 * Check if this surface is included in a specific package tier.
 * Returns true if package_tiers is null (all tiers) or if tier is in the list.
 */
public function isIncludedInTier(string $tier): bool
{
    if ($this->package_tiers === null) {
        return true;
    }

    return in_array($tier, $this->package_tiers, true);
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/SurfaceTierInclusionTest.php
```

Expected: First 3 tests PASS. The COGS test may still fail (Task 4 handles area-level filtering).

- [ ] **Step 5: Commit**

```bash
git add app/Models/EstimateAreaSurface.php tests/Feature/SurfaceTierInclusionTest.php
git commit -m "feat(estimates): add isIncludedInTier to EstimateAreaSurface model"
```

---

### Task 4: Model — Filter surfaces by tier in `EstimateArea` pricing

**Files:**
- Modify: `app/Models/EstimateArea.php:395-476` (`getFullCOGS` method)
- Modify: `app/Models/EstimateArea.php:547-632` (`getCustomerPrice` method)
- Modify: `app/Models/EstimateArea.php:644-737` (`getSurfaceCustomerPrices` method)
- Test: `tests/Feature/SurfaceTierInclusionTest.php` (existing)

- [ ] **Step 1: Update `getFullCOGS` to filter surfaces by tier**

In `app/Models/EstimateArea.php`, the `getFullCOGS()` method at line 441 currently does:

```php
$surfaces = $this->surfaces()->where('is_included', true);
```

When `$tier !== null`, the foreach loop on line 447 needs to also check `isIncludedInTier`. Change the tier branch (lines 443-451) to:

```php
if ($tier !== null) {
    $laborHours = 0.0;
    $laborCost = 0.0;
    $materialCost = 0.0;
    foreach ($surfaces->get() as $surface) {
        if (! $surface->isIncludedInTier($tier)) {
            continue;
        }
        $laborHours += $surface->getLaborHoursForTier($tier);
        $laborCost += $surface->getLaborCostForTier($tier);
        $materialCost += $surface->getMaterialCostForTier($tier);
    }
}
```

Also update the `costItems` loop (around line 469) to respect surface tier inclusion:

```php
$surfaceCostItemsCost = 0.0;
foreach ($this->surfaces()->where('is_included', true)->get() as $s) {
    if ($tier !== null && ! $s->isIncludedInTier($tier)) {
        continue;
    }
    $surfaceCostItemsCost += (float) $s->costItems()->sum('total_cost');
}
```

- [ ] **Step 2: Update `getCustomerPrice` to filter surfaces by tier**

In `getCustomerPrice()` (line 570), the surfaces collection is fetched:

```php
$surfaces = $this->surfaces()->where('is_included', true)->get();
```

In the mixed pricing foreach (line 597), add tier filtering:

```php
foreach ($surfaces as $surface) {
    if ($tier !== null && ! $surface->isIncludedInTier($tier)) {
        continue;
    }
    $totalLaborHours += $surface->getLaborHoursForTier($tier);
    // ... rest of loop
}
```

And in the `$hasCustomSalePrices` check and standard path, filter similarly. The `$surfaces` variable should be filtered before use when tier is set:

After the `$surfaces = ...->get();` line, add:

```php
if ($tier !== null) {
    $surfaces = $surfaces->filter(fn ($s) => $s->isIncludedInTier($tier));
}
```

This one-line filter cleanly handles both the standard and mixed pricing paths.

- [ ] **Step 3: Update `getSurfaceCustomerPrices` to filter surfaces by tier**

In `getSurfaceCustomerPrices()` (line 647):

```php
$surfaces = $this->surfaces()->where('is_included', true)->get();
```

Add after:

```php
if ($tier !== null) {
    $surfaces = $surfaces->filter(fn ($s) => $s->isIncludedInTier($tier));
}
```

- [ ] **Step 4: Run all tests**

```bash
php artisan test --compact tests/Feature/SurfaceTierInclusionTest.php
```

Expected: All tests PASS including the COGS comparison test.

- [ ] **Step 5: Commit**

```bash
git add app/Models/EstimateArea.php
git commit -m "feat(estimates): filter surfaces by package_tiers in area pricing calculations"
```

---

### Task 5: Model — Add `excluded_surfaces_display` to CompanySetting and Estimate

**Files:**
- Modify: `app/Models/CompanySetting.php` (fillable array)
- Modify: `app/Models/Estimate.php` (fillable array + helper method)

- [ ] **Step 1: Add to CompanySetting fillable**

In `app/Models/CompanySetting.php`, add `'excluded_surfaces_display'` to the `$fillable` array (after `'estimate_builder_instructions'`, around line 70).

- [ ] **Step 2: Add to Estimate fillable and helper**

In `app/Models/Estimate.php`, add `'excluded_surfaces_display'` to the `$fillable` array (after `'paint_tiers'`, line 76).

Add helper method in the PAINT TIER METHODS section (after `hasPaintTiers()`, around line 565):

```php
/**
 * Get the effective excluded surfaces display mode.
 * Cascades: Estimate override → Company default.
 *
 * @return string 'hidden' or 'shown'
 */
public function getExcludedSurfacesDisplay(): string
{
    if ($this->excluded_surfaces_display !== null) {
        return $this->excluded_surfaces_display;
    }

    return CompanySetting::getInstance()->excluded_surfaces_display ?? 'hidden';
}
```

- [ ] **Step 3: Run existing tests to ensure no breakage**

```bash
php artisan test --compact --filter=PackageTierVisibility
```

- [ ] **Step 4: Commit**

```bash
git add app/Models/CompanySetting.php app/Models/Estimate.php
git commit -m "feat(estimates): add excluded_surfaces_display setting to CompanySetting and Estimate"
```

---

### Task 6: Factory — Add `forTiers()` state

**Files:**
- Modify: `database/factories/EstimateAreaSurfaceFactory.php`

- [ ] **Step 1: Add factory state**

Add to `EstimateAreaSurfaceFactory.php` (after `withCustomPricing`, around line 269):

```php
/**
 * Set surface to be included only in specific package tiers.
 *
 * @param  array<string>  $tiers  e.g., ['better', 'best']
 */
public function forTiers(array $tiers): static
{
    return $this->state(fn (array $attributes) => [
        'package_tiers' => $tiers,
    ]);
}
```

- [ ] **Step 2: Commit**

```bash
git add database/factories/EstimateAreaSurfaceFactory.php
git commit -m "feat(estimates): add forTiers factory state to EstimateAreaSurfaceFactory"
```

---

### Task 7: UI — Surface tier toggle buttons in area builder

**Files:**
- Modify: `resources/views/components/estimates/⚡area-builder.blade.php`

This is the most complex UI task. The area builder needs:
1. A `toggleSurfaceTier()` Livewire method
2. G/B/B+ pill buttons on each surface header row
3. Surface data to include `package_tiers` field

- [ ] **Step 1: Add `package_tiers` to the surface data structure**

In the area builder, surfaces are initialized with various fields. Find every place where a surface array is constructed and add `'package_tiers' => null` to the default structure. Key locations:

- Template surface loading (around line 326): add `'package_tiers' => $surface->package_tiers ?? null,` — wait, surfaces come from `AreaTemplateSurface` model here which won't have `package_tiers`. For new surfaces from templates, default to `null`.

- Add `'package_tiers' => null` to every surface initialization array (the ones that have `'is_required'`, `'is_default_enabled'`, etc.). There are about 6-7 places. Search for `'is_default_enabled' => true` to find them all.

- When loading from an existing `EstimateAreaSurface` (around line 1578 in the `open-area-builder-edit` handler), add: `'package_tiers' => $surface->package_tiers,`

- [ ] **Step 2: Add `toggleSurfaceTier()` method**

In the PHP section of the area builder, add after the `switchTier` method (around line 1300):

```php
public function toggleSurfaceTier(int $index, string $tier): void
{
    if (! isset($this->surfaces[$index])) {
        return;
    }

    $currentTiers = $this->surfaces[$index]['package_tiers'] ?? ['good', 'better', 'best'];

    if (in_array($tier, $currentTiers)) {
        $currentTiers = array_values(array_diff($currentTiers, [$tier]));
    } else {
        $currentTiers[] = $tier;
        sort($currentTiers);
    }

    // Must have at least one tier
    if (empty($currentTiers)) {
        $currentTiers = [$tier];
    }

    $this->surfaces[$index]['package_tiers'] = count($currentTiers) === 3 ? null : $currentTiers;
}
```

- [ ] **Step 3: Add G/B/B+ buttons to surface header**

In the surface header row (around line 1846), between the surface name badges and the delete button, add the tier toggle buttons when tier pricing is enabled:

Find the surface header `<div>` (line 1846: `<div class="p-2 bg-zinc-50 dark:bg-zinc-800/50 flex items-center justify-between">`).

Inside the right side of the header (before the delete button at line 1863), add:

```blade
@if ($useTierPricing)
    <div class="flex gap-0.5">
        @foreach (['good' => 'G', 'better' => 'B', 'best' => 'B+'] as $tier => $label)
            @php $inTier = ($surface['package_tiers'] ?? null) === null || in_array($tier, $surface['package_tiers'] ?? []); @endphp
            <button wire:click="toggleSurfaceTier({{ $index }}, '{{ $tier }}')" type="button"
                class="{{ $inTier ? 'bg-blue-100 text-blue-700 border-blue-300 dark:bg-blue-900/50 dark:text-blue-300' : 'bg-zinc-100 text-zinc-400 border-zinc-200 dark:bg-zinc-800 dark:text-zinc-500' }} w-7 h-6 text-[10px] font-bold rounded border transition-colors flex items-center justify-center"
                title="{{ $inTier ? __('Remove from :tier', ['tier' => ucfirst($tier)]) : __('Add to :tier', ['tier' => ucfirst($tier)]) }}">
                {{ $label }}
            </button>
        @endforeach
    </div>
@endif
```

This matches the exact same pattern used for area-level tier buttons in `⚡template-builder.blade.php` (lines 3757-3765).

- [ ] **Step 4: Test manually**

Open an estimate with tier pricing enabled, edit an area, confirm:
- G/B/B+ buttons appear on each surface row
- Clicking toggles the tier (visual change: blue vs gray)
- At least one tier must remain active

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/estimates/⚡area-builder.blade.php
git commit -m "feat(estimates): add per-surface tier toggle buttons in area builder"
```

---

### Task 8: Save Flow — Persist surface `package_tiers` through template builder

**Files:**
- Modify: `resources/views/components/estimates/⚡template-builder.blade.php`

The template builder saves area data to the database. Surface `package_tiers` must flow through the save pipeline.

- [ ] **Step 1: Pass `package_tiers` when saving surfaces to DB**

In the template builder's save method that creates `EstimateAreaSurface` records (around line 2770), `tier_overrides` is already persisted. Add `package_tiers` right after:

Find:
```php
'tier_overrides' => ! empty($surfaceData['tier_overrides']) ? $surfaceData['tier_overrides'] : null,
```

Add after:
```php
'package_tiers' => ! empty($surfaceData['package_tiers']) ? $surfaceData['package_tiers'] : null,
```

- [ ] **Step 2: Load `package_tiers` when restoring from DB**

When loading existing surfaces from the DB back into the UI (around line 844-854 in the surface loading section), `tier_overrides` is already loaded. Add `package_tiers`:

Find:
```php
'tier_overrides' => $surface->tier_overrides,
```

Add after:
```php
'package_tiers' => $surface->package_tiers,
```

- [ ] **Step 3: Include in tier price computation**

In the `computeTierPricesForTemplate` method (around line 1987-2000), when iterating surfaces for tier-specific pricing, add the tier inclusion check. Find the surface loop that checks `is_required` and `is_default_enabled`:

```php
if (! ($surface['is_required'] ?? false) && ! ($surface['is_default_enabled'] ?? true)) {
    continue;
}
```

Add after that check:
```php
// Skip surfaces not included in this tier
$surfaceTiers = $surface['package_tiers'] ?? null;
if ($surfaceTiers !== null && ! in_array($tier, $surfaceTiers)) {
    continue;
}
```

- [ ] **Step 4: Run existing tests**

```bash
php artisan test --compact tests/Feature/Livewire/Estimates/PackageTierVisibilityTest.php
```

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/estimates/⚡template-builder.blade.php
git commit -m "feat(estimates): persist surface package_tiers through template builder save flow"
```

---

### Task 9: Proposal — Respect surface tier exclusion display

**Files:**
- Modify: `resources/views/components/public/⚡estimate-proposal.blade.php`
- Modify: `resources/views/components/public/⚡proposal-packages.blade.php`

- [ ] **Step 1: Filter/style surfaces in proposal**

In `⚡estimate-proposal.blade.php`, around line 977 where surfaces are filtered:

```php
$visibleSurfaces = $packageSurfaceIds === null
    ? $area->surfaces->where('is_included', true)
    : $area->surfaces->where('is_included', true)->whereIn('id', $packageSurfaceIds);
```

After this, add tier-based filtering/styling. The selected tier comes from the package's paint tier. Add logic to split surfaces into included and excluded:

```php
// Determine the active tier for this package
$activeTier = null;
if ($this->selectedPackageId) {
    $selectedPkg = $this->estimate->packages->firstWhere('id', $this->selectedPackageId);
    $activeTier = $selectedPkg?->getPaintTier();
}

// Separate included vs excluded for this tier
$excludedDisplay = $this->estimate->getExcludedSurfacesDisplay();
if ($activeTier) {
    $includedSurfaces = $visibleSurfaces->filter(fn ($s) => $s->isIncludedInTier($activeTier));
    $excludedSurfaces = ($excludedDisplay === 'shown')
        ? $visibleSurfaces->filter(fn ($s) => ! $s->isIncludedInTier($activeTier))
        : collect();
} else {
    $includedSurfaces = $visibleSurfaces;
    $excludedSurfaces = collect();
}
```

Then in the template where surfaces are rendered, iterate `$includedSurfaces` normally, and render `$excludedSurfaces` with a grayed-out style:

```blade
@foreach ($excludedSurfaces as $surface)
    <div class="flex justify-between text-sm text-zinc-400 dark:text-zinc-600 line-through opacity-60">
        <span>{{ $surface->name }}</span>
        <span class="text-xs italic">{{ __('Not included') }}</span>
    </div>
@endforeach
```

- [ ] **Step 2: Filter surfaces in package proposal view**

In `⚡proposal-packages.blade.php`, around line 126 where surfaces are queried:

```php
foreach ($area->surfaces()->where('is_included', true)->get() as $surface) {
```

Add tier filtering based on the package's paint tier:

```php
$paintTier = $package->getPaintTier();
foreach ($area->surfaces()->where('is_included', true)->get() as $surface) {
    if ($paintTier && ! $surface->isIncludedInTier($paintTier)) {
        continue;
    }
    // ... existing paint product logic
}
```

Apply the same pattern to the second similar loop (around line 187).

- [ ] **Step 3: Run Pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 4: Commit**

```bash
git add resources/views/components/public/⚡estimate-proposal.blade.php resources/views/components/public/⚡proposal-packages.blade.php
git commit -m "feat(estimates): respect surface tier inclusion in proposal views"
```

---

### Task 10: Company Settings UI — Add `excluded_surfaces_display` setting

**Files:**
- Modify: `resources/views/pages/settings/⚡company.blade.php`

- [ ] **Step 1: Add setting field**

Find the estimates/pricing section in the company settings page. Add a select dropdown for `excluded_surfaces_display`:

```blade
<flux:field>
    <flux:label>{{ __('Excluded Surfaces on Proposals') }}</flux:label>
    <flux:select wire:model="excluded_surfaces_display">
        <option value="hidden">{{ __('Hidden — excluded surfaces are not shown') }}</option>
        <option value="shown">{{ __('Shown — excluded surfaces appear grayed out') }}</option>
    </flux:select>
    <flux:description>{{ __('When a surface is excluded from a package tier, choose whether to hide it completely or show it grayed out on the proposal.') }}</flux:description>
</flux:field>
```

Add `$excluded_surfaces_display` as a public property and wire it up in `mount()` and `save()` following the existing patterns in the component.

- [ ] **Step 2: Commit**

```bash
git add resources/views/pages/settings/⚡company.blade.php
git commit -m "feat(settings): add excluded_surfaces_display setting to company settings page"
```

---

### Task 11: Livewire Tests — Surface tier toggle

**Files:**
- Create: `tests/Feature/Livewire/Estimates/SurfaceTierToggleTest.php`

- [ ] **Step 1: Write tests**

```php
<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    CompanySetting::factory()->create();
    $this->user = User::factory()->internal()->create();
    $this->actingAs($this->user);
});

it('saves surface package_tiers to database on template builder save', function () {
    $estimate = Estimate::factory()->create(['created_by' => $this->user->id]);
    $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

    $surface = EstimateAreaSurface::factory()->forArea($area)->wall()->create([
        'package_tiers' => ['better', 'best'],
    ]);

    expect($surface->fresh()->package_tiers)->toBe(['better', 'best']);
    expect($surface->fresh()->isIncludedInTier('good'))->toBeFalse();
    expect($surface->fresh()->isIncludedInTier('better'))->toBeTrue();
});

it('returns correct customer price excluding tier-filtered surfaces', function () {
    $area = EstimateArea::factory()->create();

    // Surface in all tiers
    EstimateAreaSurface::factory()->forArea($area)->create([
        'labor_cost' => 100,
        'material_cost' => 50,
        'total' => 150,
        'is_included' => true,
        'package_tiers' => null,
    ]);

    // Surface in best only
    EstimateAreaSurface::factory()->forArea($area)->create([
        'labor_cost' => 80,
        'material_cost' => 30,
        'total' => 110,
        'is_included' => true,
        'package_tiers' => ['best'],
    ]);

    $goodPrice = $area->getCustomerPrice('good');
    $bestPrice = $area->getCustomerPrice('best');

    // Best should be more expensive (more surfaces)
    expect($bestPrice)->toBeGreaterThan($goodPrice);
});

it('resolves excluded_surfaces_display from estimate then company', function () {
    $settings = CompanySetting::getInstance();
    $settings->update(['excluded_surfaces_display' => 'shown']);

    $estimate = Estimate::factory()->create(['excluded_surfaces_display' => null]);
    expect($estimate->getExcludedSurfacesDisplay())->toBe('shown');

    $estimate->update(['excluded_surfaces_display' => 'hidden']);
    expect($estimate->fresh()->getExcludedSurfacesDisplay())->toBe('hidden');
});
```

- [ ] **Step 2: Run tests**

```bash
php artisan test --compact tests/Feature/Livewire/Estimates/SurfaceTierToggleTest.php
```

- [ ] **Step 3: Run Pint and commit**

```bash
vendor/bin/pint --dirty
git add tests/Feature/Livewire/Estimates/SurfaceTierToggleTest.php tests/Feature/SurfaceTierInclusionTest.php
git commit -m "test(estimates): add tests for surface tier inclusion and excluded display setting"
```

---

### Task 12: Final Integration — Run full related tests

**Files:** (no new changes, just verification)

- [ ] **Step 1: Run all estimate-related tests**

```bash
php artisan test --compact tests/Feature/Livewire/Estimates/
php artisan test --compact tests/Feature/Ai/UpdateSurfaceTierOverridesTest.php
php artisan test --compact tests/Feature/Ai/SetAreaPackageTiersTest.php
php artisan test --compact tests/Feature/SurfaceTierInclusionTest.php
```

- [ ] **Step 2: Fix any failures**

If any existing tests fail due to the new tier filtering, they likely need surfaces to have `is_included` true and will automatically pass since `package_tiers` defaults to `null` (all tiers).

- [ ] **Step 3: Run Pint on all changed files**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "feat(estimates): complete per-surface tier inclusion feature"
```

- [ ] **Step 5: Ask user to run full test suite**

```bash
php artisan test --compact
```
