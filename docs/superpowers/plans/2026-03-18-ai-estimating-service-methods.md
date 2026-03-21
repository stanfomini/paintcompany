# AI Estimating System — Complete Service Methods Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the AI estimating system by adding missing `EstimateAiService` methods, fixing broken category matching in AI tool schemas, and ensuring all tests pass.

**Architecture:** The new AI tools (`AddCostItemAiTool`, `CreateProductionRateAiTool`, `CreateTemplateAiTool`, etc.) call service methods on `EstimateAiService` that don't exist yet. We also have surface category naming mismatches in tool schemas that would cause the AI to store invalid `surface_category` values in the DB.

**Tech Stack:** Laravel 12, PHP 8.3, Pest v4, Livewire 4, `EstimateAiService`, `EstimateAreaSurface`, `ProductionRate`, `EstimateCostItem` models.

---

## Root Cause Summary

Previous agents created 12 new AI tools and 2 test files, but forgot to add the corresponding service methods to `EstimateAiService`. Additionally:

1. **`EstimateAiService` missing 12 methods** — all new tools will throw "method not found"
2. **`getEstimateState()` missing `package_tiers`** — one test fails because area output omits this field
3. **Wrong `surface_category` values in tool schemas** — `AddSurfaceToAreaAiTool` lists `siding`, `crown`, `molding` (invalid). `CreateProductionRateAiTool` lists `other` (invalid) and missing `baseboard`, `prep`, `exterior_doors`, `wood_replacement`

**Valid `surface_category` values** (from `ProductionRate` constants — these are the ONLY valid values):
- **Interior:** `walls`, `ceiling`, `baseboard`, `trim`, `doors`, `windows`, `prep`
- **Exterior:** `body`, `eaves`, `fascia`, `trim`, `exterior_doors`, `windows`, `wood_replacement`, `prep`

---

## File Map

**Files to modify:**
- `app/Services/EstimateAiService.php` — Add 12 missing methods
- `app/Ai/Tools/Estimate/AddSurfaceToAreaAiTool.php` — Fix `surface_category` description
- `app/Ai/Tools/Estimate/CreateProductionRateAiTool.php` — Fix `surface_category` description
- `app/Ai/Tools/Estimate/ListProductionRatesAiTool.php` — Improve `category` description

**Files to create:**
- `tests/Feature/Ai/EstimateAiServiceMethodsTest.php` — Tests for the new service methods

**Files already exist (the new tools — don't modify):**
- `app/Ai/Tools/Estimate/AddCostItemAiTool.php`
- `app/Ai/Tools/Estimate/CreateEstimateItemAiTool.php`
- `app/Ai/Tools/Estimate/CreateNestedTemplateAiTool.php`
- `app/Ai/Tools/Estimate/CreateProductionRateAiTool.php`
- `app/Ai/Tools/Estimate/CreateTemplateAiTool.php`
- `app/Ai/Tools/Estimate/GetProjectContextAiTool.php`
- `app/Ai/Tools/Estimate/ListCostItemsAiTool.php`
- `app/Ai/Tools/Estimate/RemoveCostItemAiTool.php`
- `app/Ai/Tools/Estimate/SaveAiContextAiTool.php`
- `app/Ai/Tools/Estimate/SetAreaPackageTiersAiTool.php`
- `app/Ai/Tools/Estimate/UpdateSurfaceTierOverridesAiTool.php`
- `app/Ai/Tools/Estimate/WriteEstimateSummaryAiTool.php`

**Existing tests (don't modify, just run):**
- `tests/Feature/Ai/SetAreaPackageTiersTest.php`
- `tests/Feature/Ai/UpdateSurfaceTierOverridesTest.php`

---

## Task 1: Fix `getEstimateState()` — Add `package_tiers` to area output

**Files:**
- Modify: `app/Services/EstimateAiService.php:233-276`

**What:** The `getEstimateState()` area map must include `package_tiers` for the existing test `UpdateSurfaceTierOverridesTest::it('exposes package_tiers in getEstimateState')`.

- [ ] **Step 1: Run the failing test**

```bash
php artisan test --compact tests/Feature/Ai/UpdateSurfaceTierOverridesTest.php --filter="exposes package_tiers"
```
Expected: FAIL — `Failed asserting that array has key 'package_tiers'`

- [ ] **Step 2: Add `package_tiers` to area map in `getEstimateState()`**

In `app/Services/EstimateAiService.php`, find the area map in `getEstimateState()` (around line 273) and add after `'is_fixed_price' => $area->is_fixed_price,`:

```php
'package_tiers' => $area->package_tiers,
```

- [ ] **Step 3: Run the test again**

```bash
php artisan test --compact tests/Feature/Ai/UpdateSurfaceTierOverridesTest.php --filter="exposes package_tiers"
```
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Services/EstimateAiService.php
git commit -m "fix(ai): add package_tiers to getEstimateState area output"
```

---

## Task 2: Fix `surface_category` in AI tool schemas

**Files:**
- Modify: `app/Ai/Tools/Estimate/AddSurfaceToAreaAiTool.php`
- Modify: `app/Ai/Tools/Estimate/CreateProductionRateAiTool.php`
- Modify: `app/Ai/Tools/Estimate/ListProductionRatesAiTool.php`

**Why:** Invalid category names (`siding`, `crown`, `molding`, `other`) in the schema descriptions cause the AI to store incorrect `surface_category` values in `estimate_area_surfaces`. This breaks paint product cascading, the `getQuantityForCategory()` auto-calc, and rate lookup queries.

- [ ] **Step 1: Fix `AddSurfaceToAreaAiTool` schema**

Change the `surface_category` description from:
```php
'surface_category' => $schema->string()->description('Surface category: walls, ceiling, baseboard, trim, doors, windows, body, siding, fascia, eaves, crown, molding')->required(),
```
To:
```php
'surface_category' => $schema->string()->description('Surface category — use EXACT values. Interior: walls|ceiling|baseboard|trim|doors|windows|prep. Exterior: body|eaves|fascia|trim|exterior_doors|windows|wood_replacement|prep. Do NOT use siding, crown, molding, or other.')->required(),
```

- [ ] **Step 2: Fix `CreateProductionRateAiTool` schema**

Change the `surface_category` description from:
```php
'surface_category' => $schema->string()->description('Category: walls, ceiling, trim, doors, body, fascia, eaves, other')->required(),
```
To:
```php
'surface_category' => $schema->string()->description('Surface category — use EXACT values. Interior: walls|ceiling|baseboard|trim|doors|windows|prep. Exterior: body|eaves|fascia|trim|exterior_doors|windows|wood_replacement|prep. No "other" or aliases.')->required(),
```

- [ ] **Step 3: Fix `ListProductionRatesAiTool` schema**

Change the `category` description from:
```php
'category' => $schema->string()->description('Filter by surface category: walls, ceiling, trim, doors, etc.'),
```
To:
```php
'category' => $schema->string()->description('Filter by surface category. Interior: walls|ceiling|baseboard|trim|doors|windows|prep. Exterior: body|eaves|fascia|trim|exterior_doors|windows|wood_replacement|prep.'),
```

- [ ] **Step 4: Commit**

```bash
git add app/Ai/Tools/Estimate/AddSurfaceToAreaAiTool.php app/Ai/Tools/Estimate/CreateProductionRateAiTool.php app/Ai/Tools/Estimate/ListProductionRatesAiTool.php
git commit -m "fix(ai): correct surface_category valid values in tool schemas"
```

---

## Task 3: Add `setAreaPackageTiers()` and `updateSurfaceTierOverrides()` to `EstimateAiService`

**Files:**
- Modify: `app/Services/EstimateAiService.php`

These two methods are tested by the existing test files (which currently fail). Add them after `getEstimateSummary()`, before the private helpers section.

- [ ] **Step 1: Run the existing tests to confirm they fail**

```bash
php artisan test --compact tests/Feature/Ai/SetAreaPackageTiersTest.php tests/Feature/Ai/UpdateSurfaceTierOverridesTest.php
```
Expected: Multiple FAIL — `Call to undefined method setAreaPackageTiers / updateSurfaceTierOverrides`

- [ ] **Step 2: Add `setAreaPackageTiers()` to `EstimateAiService`**

Add after `getEstimateSummary()` (around line 924):

```php
// =========================================================================
// PACKAGE TIER + SURFACE TIER OVERRIDE METHODS
// =========================================================================

/**
 * Set which package tiers (good/better/best) include this area.
 * Null = all tiers. Empty array = all tiers. Specific array = only those tiers.
 *
 * @param  array<string>|null  $tiers
 * @return array{area_id: int, package_tiers: array|null, included_in: array}
 */
public function setAreaPackageTiers(int $areaId, ?array $tiers): array
{
    $area = EstimateArea::findOrFail($areaId);

    // Validate and filter to only accepted tier names
    $validTiers = ['good', 'better', 'best'];
    if ($tiers !== null && ! empty($tiers)) {
        $tiers = array_values(array_intersect($tiers, $validTiers));
        if (empty($tiers)) {
            $tiers = null;
        }
    } else {
        $tiers = null;
    }

    // Clear cached pricing since tier config changed
    $area->update([
        'package_tiers' => $tiers,
        'cached_cogs' => null,
        'cached_customer_price' => null,
        'cached_tier_prices' => null,
    ]);

    $includedIn = $tiers ?? $validTiers;

    return [
        'area_id' => $area->id,
        'package_tiers' => $tiers,
        'included_in' => $includedIn,
    ];
}

/**
 * Set per-tier overrides for labor (coats/rate) or material (paint product) on a surface.
 * Auto-computes labor hours from coats+rate. Auto-computes material cost from paint product.
 * Dual-writes to tier_overrides JSON AND flat columns (material_cost_good/better/best).
 *
 * @return array{surface_id: int, tier: string, tier_data: array, area_tier_cogs: float, area_tier_price: float}
 */
public function updateSurfaceTierOverrides(
    int $surfaceId,
    string $tier,
    ?int $productionRateId = null,
    ?int $coats = null,
    ?float $quantityOverride = null,
    ?int $paintProductId = null,
    ?float $laborHours = null,
    ?float $materialCost = null,
): array {
    $validTiers = ['good', 'better', 'best'];
    if (! in_array($tier, $validTiers, true)) {
        throw new \InvalidArgumentException("Invalid tier: {$tier}. Must be one of: good, better, best");
    }

    $surface = EstimateAreaSurface::with(['area', 'productionRate'])->findOrFail($surfaceId);
    $scopeType = $surface->area?->scope_type ?? 'interior';
    $settings = CompanySetting::getInstance();

    // Build base snapshot on first override (captures current unmodified state)
    $overrides = $surface->tier_overrides ?? [];
    if (! isset($overrides['_base'])) {
        $overrides['_base'] = [
            'production_rate_id' => $surface->production_rate_id,
            'default_coats' => $surface->coats ?? 2,
            'labor_hours' => round((float) $surface->labor_hours, 4),
            'labor_cost' => round((float) $surface->labor_cost, 4),
            'material_cost' => round((float) $surface->material_cost, 4),
        ];
    }

    // Resolve the production rate for this tier
    $rate = $productionRateId
        ? ProductionRate::findOrFail($productionRateId)
        : $surface->productionRate;

    // Resolve coat count
    $resolvedCoats = $coats ?? $overrides[$tier]['default_coats'] ?? $surface->coats ?? 2;

    // Calculate labor hours
    if ($laborHours !== null) {
        $resolvedLaborHours = $laborHours;
    } elseif ($rate) {
        $quantity = $quantityOverride ?? (float) $surface->quantity;
        $resolvedLaborHours = round($rate->calculateLaborHours($quantity, $resolvedCoats), 4);
    } else {
        $resolvedLaborHours = (float) $surface->labor_hours;
    }

    // Calculate labor cost
    $effectiveRate = $settings->getEffectiveHourlyRateForScope($scopeType);
    $resolvedLaborCost = round($resolvedLaborHours * $effectiveRate, 4);

    // Calculate material cost
    if ($materialCost !== null) {
        $resolvedMaterialCost = $materialCost;
        $resolvedPaintProductId = $paintProductId;
    } elseif ($paintProductId !== null) {
        $paint = PaintProduct::findOrFail($paintProductId);
        $costCalc = $paint->calculateCost(
            (float) $surface->quantity,
            $resolvedCoats,
            $surface->application_method ?? 'brush_roll'
        );
        $resolvedMaterialCost = round($costCalc['total_cost'], 4);
        $resolvedPaintProductId = $paintProductId;
    } else {
        $resolvedMaterialCost = $overrides[$tier]['material_cost'] ?? (float) $surface->material_cost;
        $resolvedPaintProductId = $overrides[$tier]['paint_product_id'] ?? null;
    }

    // Build tier data
    $overrides[$tier] = array_filter([
        'production_rate_id' => $productionRateId ?? ($overrides[$tier]['production_rate_id'] ?? null),
        'default_coats' => $resolvedCoats,
        'labor_hours' => $resolvedLaborHours,
        'labor_cost' => $resolvedLaborCost,
        'material_cost' => $resolvedMaterialCost,
        'paint_product_id' => $resolvedPaintProductId,
    ], fn ($v) => $v !== null);

    // Flat column dual-write
    $flatMaterialCol = "material_cost_{$tier}";
    $flatPaintCol = "paint_product_id_{$tier}";

    $surface->tier_overrides = $overrides;
    $surface->{$flatMaterialCol} = $resolvedMaterialCost;
    if ($resolvedPaintProductId) {
        $surface->{$flatPaintCol} = $resolvedPaintProductId;
    }
    $surface->save();

    // Clear area cached pricing
    if ($surface->area) {
        $surface->area->update([
            'cached_cogs' => null,
            'cached_customer_price' => null,
            'cached_tier_prices' => null,
        ]);
    }

    $areaTierCogs = $surface->area ? round($surface->area->getFullCOGS($tier, bypassCache: true), 2) : 0.0;
    $areaTierPrice = $surface->area ? round($surface->area->getCustomerPrice($tier), 2) : 0.0;

    return [
        'surface_id' => $surface->id,
        'tier' => $tier,
        'tier_data' => $overrides[$tier],
        'area_tier_cogs' => $areaTierCogs,
        'area_tier_price' => $areaTierPrice,
    ];
}
```

- [ ] **Step 3: Add missing imports to `EstimateAiService`** (if not present)

At top of file, ensure:
```php
use App\Models\CompanySetting;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
```

- [ ] **Step 4: Run the tests**

```bash
php artisan test --compact tests/Feature/Ai/SetAreaPackageTiersTest.php tests/Feature/Ai/UpdateSurfaceTierOverridesTest.php
```
Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/EstimateAiService.php
git commit -m "feat(ai): add setAreaPackageTiers and updateSurfaceTierOverrides to EstimateAiService"
```

---

## Task 4: Add cost item service methods (`addCostItem`, `removeCostItem`, `listCostItems`)

**Files:**
- Modify: `app/Services/EstimateAiService.php`
- Create: `tests/Feature/Ai/EstimateAiServiceMethodsTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/Ai/EstimateAiServiceMethodsTest.php`:

```php
<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateCostItem;
use App\Services\EstimateAiService;

beforeEach(function () {
    $this->service = new EstimateAiService;
    $this->estimate = Estimate::factory()->create(['status' => 'draft']);

    CompanySetting::firstOrCreate([], [
        'company_name' => 'Test Co',
        'interior_hourly_wage' => 21.00,
        'exterior_hourly_wage' => 27.00,
        'payroll_burden_multiplier' => 1.35,
        'interior_margin_percent' => 50.00,
        'exterior_margin_percent' => 41.00,
        'interior_cleanup_hours_ratio' => 16.00,
        'exterior_cleanup_hours_ratio' => 20.00,
        'interior_sundries_cost_per_hour' => 3.30,
        'exterior_sundries_cost_per_hour' => 1.00,
        'recycle_fee_per_gallon' => 0.54,
        'material_tax_rate' => 7.50,
        'labor_tax_rate' => 0.00,
    ]);
    CompanySetting::clearInstanceCache();

    $this->area = EstimateArea::factory()->forEstimate($this->estimate)->create([
        'name' => 'Living Room',
        'scope_type' => 'interior',
    ]);
});

// ===== Cost Items =====

it('adds a cost item to an area', function () {
    $result = $this->service->addCostItem(
        targetType: 'area',
        targetId: $this->area->id,
        name: 'Sprayer Rental',
        costType: 'rental',
        quantity: 2,
        unit: 'day',
        unitCost: 50.00,
        unitPrice: 100.00,
    );

    expect($result['id'])->toBeInt()
        ->and($result['name'])->toBe('Sprayer Rental')
        ->and($result['total_cost'])->toBe(100.00)
        ->and($result['total_price'])->toBe(200.00);

    expect(EstimateCostItem::where('costable_type', EstimateArea::class)
        ->where('costable_id', $this->area->id)
        ->count())->toBe(1);
});

it('adds a cost item to a surface', function () {
    $surface = EstimateAreaSurface::factory()->forArea($this->area)->create(['name' => 'Walls']);

    $result = $this->service->addCostItem(
        targetType: 'surface',
        targetId: $surface->id,
        name: 'Masking tape',
        costType: 'material',
        quantity: 5,
        unit: 'roll',
        unitCost: 3.00,
        unitPrice: 6.00,
    );

    expect($result['total_cost'])->toBe(15.00)
        ->and($result['total_price'])->toBe(30.00);
});

it('lists cost items on an area', function () {
    EstimateCostItem::create([
        'costable_type' => EstimateArea::class,
        'costable_id' => $this->area->id,
        'name' => 'Item A',
        'cost_type' => 'material',
        'quantity' => 1,
        'unit' => 'each',
        'unit_cost' => 10.00,
        'unit_price' => 20.00,
        'total_cost' => 10.00,
        'total_price' => 20.00,
    ]);

    $result = $this->service->listCostItems('area', $this->area->id);

    expect($result)->toHaveCount(1)
        ->and($result[0]['name'])->toBe('Item A');
});

it('removes a cost item', function () {
    $item = EstimateCostItem::create([
        'costable_type' => EstimateArea::class,
        'costable_id' => $this->area->id,
        'name' => 'To Remove',
        'cost_type' => 'material',
        'quantity' => 1,
        'unit' => 'each',
        'unit_cost' => 5.00,
        'unit_price' => 10.00,
        'total_cost' => 5.00,
        'total_price' => 10.00,
    ]);

    $result = $this->service->removeCostItem($item->id);

    expect($result['success'])->toBeTrue();
    expect(EstimateCostItem::find($item->id))->toBeNull();
});

// ===== AI Context/Summary =====

it('saves ai context to estimate', function () {
    $result = $this->service->updateAiContext(
        estimate: $this->estimate,
        context: 'Customer prefers warm tones, mentioned liking SW Accessible Beige.'
    );

    expect($result['success'])->toBeTrue();
    expect($this->estimate->fresh()->ai_context)->toContain('Accessible Beige');
});

it('saves ai summary to estimate', function () {
    $result = $this->service->updateAiSummary(
        estimate: $this->estimate,
        summary: '## Scope of Work\n\nThis estimate covers interior painting of the main living areas.'
    );

    expect($result['success'])->toBeTrue();
    expect($this->estimate->fresh()->ai_summary)->toContain('Scope of Work');
});

// ===== Project Context =====

it('returns project context for estimate without deal', function () {
    $result = $this->service->getProjectContext($this->estimate);

    expect($result)->toHaveKey('customer')
        ->and($result)->toHaveKey('property')
        ->and($result)->toHaveKey('estimate_photos');
});

// ===== Create Production Rate =====

it('creates a production rate with valid surface_category', function () {
    $result = $this->service->createProductionRate(
        name: 'Sunroom Walls — Brush/Roll',
        scope: 'interior',
        surfaceCategory: 'walls',
        productionRate: 180.0,
        rateType: 'sqft_hr',
        unitType: 'sqft',
        defaultCoats: 2,
    );

    expect($result['id'])->toBeInt()
        ->and($result['name'])->toBe('Sunroom Walls — Brush/Roll')
        ->and($result['surface_category'])->toBe('walls');
});

it('rejects invalid surface_category when creating production rate', function () {
    $this->service->createProductionRate(
        name: 'Bad Rate',
        scope: 'interior',
        surfaceCategory: 'other',
        productionRate: 100.0,
        rateType: 'sqft_hr',
        unitType: 'sqft',
    );
})->throws(\InvalidArgumentException::class);

// ===== Create Estimate Item =====

it('creates an estimate line item', function () {
    $result = $this->service->createEstimateItem(
        estimate: $this->estimate,
        description: 'Sprayer rental (2 days)',
        quantity: 2.0,
        unit: 'day',
        unitPrice: 85.00,
        cost: 40.00,
    );

    expect($result['id'])->toBeInt()
        ->and($result['description'])->toBe('Sprayer rental (2 days)')
        ->and($result['total_price'])->toBe(170.00);
});
```

- [ ] **Step 2: Run failing tests**

```bash
php artisan test --compact tests/Feature/Ai/EstimateAiServiceMethodsTest.php
```
Expected: FAIL — methods not found

- [ ] **Step 3: Add `addCostItem()`, `removeCostItem()`, `listCostItems()` to `EstimateAiService`**

Add these after the tier methods:

```php
// =========================================================================
// COST ITEM METHODS
// =========================================================================

/**
 * Add a cost item (material, rental, equipment) to an area or surface.
 *
 * @return array{id: int, name: string, cost_type: string, quantity: float, unit: string, unit_cost: float, unit_price: float, total_cost: float, total_price: float}
 */
public function addCostItem(
    string $targetType,
    int $targetId,
    string $name,
    string $costType = 'material',
    float $quantity = 1,
    string $unit = 'each',
    float $unitCost = 0,
    float $unitPrice = 0,
    ?string $tier = null,
    ?string $notes = null,
): array {
    $morphableClass = match ($targetType) {
        'area' => EstimateArea::class,
        'surface' => EstimateAreaSurface::class,
        default => throw new \InvalidArgumentException("Invalid target_type: {$targetType}. Use 'area' or 'surface'."),
    };

    $target = $morphableClass::findOrFail($targetId);

    $item = EstimateCostItem::create([
        'costable_type' => $morphableClass,
        'costable_id' => $targetId,
        'name' => $name,
        'cost_type' => $costType,
        'quantity' => $quantity,
        'unit' => $unit,
        'unit_cost' => $unitCost,
        'unit_price' => $unitPrice,
        'tier' => $tier,
        'notes' => $notes,
    ]);

    return [
        'id' => $item->id,
        'name' => $item->name,
        'cost_type' => $item->cost_type,
        'quantity' => (float) $item->quantity,
        'unit' => $item->unit,
        'unit_cost' => (float) $item->unit_cost,
        'unit_price' => (float) $item->unit_price,
        'total_cost' => (float) $item->total_cost,
        'total_price' => (float) $item->total_price,
        'tier' => $item->tier,
    ];
}

/**
 * Remove a cost item by ID.
 *
 * @return array{success: bool, cost_item_id: int}
 */
public function removeCostItem(int $costItemId): array
{
    $item = EstimateCostItem::findOrFail($costItemId);
    $item->delete();

    return ['success' => true, 'cost_item_id' => $costItemId];
}

/**
 * List cost items on an area or surface.
 *
 * @return array<int, array{id: int, name: string, cost_type: string, quantity: float, unit: string, unit_cost: float, unit_price: float, total_cost: float, total_price: float, tier: string|null}>
 */
public function listCostItems(string $targetType, int $targetId): array
{
    $morphableClass = match ($targetType) {
        'area' => EstimateArea::class,
        'surface' => EstimateAreaSurface::class,
        default => throw new \InvalidArgumentException("Invalid target_type: {$targetType}. Use 'area' or 'surface'."),
    };

    return EstimateCostItem::where('costable_type', $morphableClass)
        ->where('costable_id', $targetId)
        ->orderBy('sort_order')
        ->get()
        ->map(fn (EstimateCostItem $item) => [
            'id' => $item->id,
            'name' => $item->name,
            'cost_type' => $item->cost_type,
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'unit_cost' => (float) $item->unit_cost,
            'unit_price' => (float) $item->unit_price,
            'total_cost' => (float) $item->total_cost,
            'total_price' => (float) $item->total_price,
            'tier' => $item->tier,
            'notes' => $item->notes,
        ])
        ->toArray();
}
```

- [ ] **Step 4: Add `updateAiContext()` and `updateAiSummary()` to `EstimateAiService`**

```php
// =========================================================================
// AI MEMORY METHODS
// =========================================================================

/**
 * Save/update AI context notes on the estimate.
 * Appends to existing context rather than replacing.
 *
 * @return array{success: bool, estimate_id: int}
 */
public function updateAiContext(Estimate $estimate, string $context): array
{
    $existing = $estimate->ai_context;
    $newContext = $existing ? $existing."\n\n---\n\n".$context : $context;

    $estimate->update(['ai_context' => $newContext]);

    return ['success' => true, 'estimate_id' => $estimate->id];
}

/**
 * Write/update the AI-generated proposal summary.
 *
 * @return array{success: bool, estimate_id: int}
 */
public function updateAiSummary(Estimate $estimate, string $summary): array
{
    $estimate->update(['ai_summary' => $summary]);

    return ['success' => true, 'estimate_id' => $estimate->id];
}
```

- [ ] **Step 5: Add `getProjectContext()` to `EstimateAiService`**

```php
/**
 * Get project context: customer, property, deal, and photos.
 *
 * @return array{customer: array|null, property: array|null, deal: array|null, estimate_photos: array}
 */
public function getProjectContext(Estimate $estimate): array
{
    $estimate->load(['deal.customer', 'deal.property', 'photos']);

    $deal = $estimate->deal;
    $customer = $deal?->customer;
    $property = $deal?->property;

    return [
        'customer' => $customer ? [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone ?? null,
        ] : null,
        'property' => $property ? [
            'address' => $property->address ?? null,
            'full_address' => $property->full_address ?? ($property->address ?? null),
            'sqft' => $property->sqft ?? null,
            'year_built' => $property->year_built ?? null,
            'bedrooms' => $property->bedrooms ?? null,
            'bathrooms' => $property->bathrooms ?? null,
            'stories' => $property->stories ?? null,
        ] : null,
        'deal' => $deal ? [
            'id' => $deal->id,
            'stage' => $deal->stage ?? null,
            'notes' => $deal->notes ?? null,
        ] : null,
        'estimate_photos' => $estimate->photos->map(fn ($p) => [
            'id' => $p->id,
            'url' => $p->url ?? null,
            'caption' => $p->caption ?? null,
            'show_on_proposal' => (bool) ($p->show_on_proposal ?? false),
        ])->toArray(),
    ];
}
```

- [ ] **Step 6: Add missing imports at top of `EstimateAiService`**

Ensure these imports exist:
```php
use App\Models\EstimateCostItem;
```

- [ ] **Step 7: Run the new tests**

```bash
php artisan test --compact tests/Feature/Ai/EstimateAiServiceMethodsTest.php
```
Expected: Some pass, some still fail (need createProductionRate, createEstimateItem)

---

## Task 5: Add `createProductionRate()` and `createEstimateItem()` to `EstimateAiService`

**Files:**
- Modify: `app/Services/EstimateAiService.php`

- [ ] **Step 1: Add `createProductionRate()` to `EstimateAiService`**

Add in the creation section:

```php
// =========================================================================
// CREATION METHODS (permanent, reusable records)
// =========================================================================

/**
 * Create a new production rate.
 * surface_category MUST be one of the valid ProductionRate constants.
 *
 * Interior: walls|ceiling|baseboard|trim|doors|windows|prep
 * Exterior: body|eaves|fascia|trim|exterior_doors|windows|wood_replacement|prep
 *
 * @return array{id: int, name: string, scope: string, surface_category: string, production_rate: float, rate_type: string}
 */
public function createProductionRate(
    string $name,
    string $scope,
    string $surfaceCategory,
    float $productionRate,
    string $rateType = 'sqft_hr',
    string $unitType = 'sqft',
    int $defaultCoats = 2,
    ?string $description = null,
): array {
    // Validate scope
    if (! in_array($scope, ['interior', 'exterior'], true)) {
        throw new \InvalidArgumentException("Invalid scope: {$scope}. Must be 'interior' or 'exterior'.");
    }

    // Validate surface_category against ProductionRate constants
    $validCategories = $scope === 'interior'
        ? array_keys(ProductionRate::INTERIOR_CATEGORIES)
        : array_keys(ProductionRate::EXTERIOR_CATEGORIES);

    if (! in_array($surfaceCategory, $validCategories, true)) {
        $valid = implode(', ', $validCategories);
        throw new \InvalidArgumentException("Invalid surface_category '{$surfaceCategory}' for scope '{$scope}'. Valid: {$valid}");
    }

    // Get or create the category for this scope
    $categoryName = $scope === 'interior' ? 'Interior Painting' : 'Exterior Painting';
    $category = ProductionRateCategory::firstOrCreate(
        ['name' => $categoryName],
        ['type' => $scope, 'is_active' => true, 'sort_order' => $scope === 'interior' ? 1 : 2]
    );

    $rate = ProductionRate::create([
        'category_id' => $category->id,
        'name' => $name,
        'scope' => $scope,
        'surface_category' => $surfaceCategory,
        'description' => $description ?? 'Custom rate created by AI',
        'unit_type' => $unitType,
        'production_rate' => $productionRate,
        'rate_type' => $rateType,
        'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
        'default_coats' => $defaultCoats,
        'is_active' => true,
        'sort_order' => 999,
    ]);

    return [
        'id' => $rate->id,
        'name' => $rate->name,
        'scope' => $rate->scope,
        'surface_category' => $rate->surface_category,
        'production_rate' => (float) $rate->production_rate,
        'rate_type' => $rate->rate_type,
        'unit_type' => $rate->unit_type,
        'default_coats' => $rate->default_coats,
    ];
}
```

- [ ] **Step 2: Add `createEstimateItem()` to `EstimateAiService`**

```php
/**
 * Create a line item on the estimate (equipment, subcontractor, misc charge).
 *
 * @return array{id: int, description: string, quantity: float, unit: string, unit_price: float, total_price: float}
 */
public function createEstimateItem(
    Estimate $estimate,
    string $description,
    float $quantity,
    string $unit = 'each',
    float $unitPrice = 0,
    ?float $cost = null,
): array {
    $item = $estimate->items()->create([
        'description' => $description,
        'quantity' => $quantity,
        'unit' => $unit,
        'unit_price' => $unitPrice,
        'unit_cost' => $cost ?? 0,
        'total' => $quantity * $unitPrice,
    ]);

    $estimate->calculateTotals();
    $estimate->calculateDepositAndBalance();
    $estimate->save();

    return [
        'id' => $item->id,
        'description' => $item->description,
        'quantity' => (float) $item->quantity,
        'unit' => $item->unit,
        'unit_price' => (float) $item->unit_price,
        'total_price' => (float) $item->total,
    ];
}
```

- [ ] **Step 3: Add missing import for `ProductionRateCategory`**

Ensure `use App\Models\ProductionRateCategory;` is in the imports.

- [ ] **Step 4: Run all new tests**

```bash
php artisan test --compact tests/Feature/Ai/EstimateAiServiceMethodsTest.php
```
Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/EstimateAiService.php tests/Feature/Ai/EstimateAiServiceMethodsTest.php
git commit -m "feat(ai): add createProductionRate, createEstimateItem, cost item, AI memory methods to EstimateAiService"
```

---

## Task 6: Add `createNestedTemplate()` and `createTemplate()` to `EstimateAiService`

**Files:**
- Modify: `app/Services/EstimateAiService.php`

These are complex creation methods. Both create permanent, reusable records.

- [ ] **Step 1: Add `createNestedTemplate()` to `EstimateAiService`**

```php
/**
 * Create a NestedAreaTemplate (fixed-price closets, half baths, prep work).
 * Optionally adds NestedTemplateSurface records for accurate PBP-based calculation.
 *
 * @param  array<int, array{name: string, surface_type: string, production_rate_id: int, calculation_method?: string, calculation_value?: float, default_coats?: int}>|null  $surfaces
 * @return array{id: int, name: string, category: string, fixed_price: float|null, estimated_hours: float|null}
 */
public function createNestedTemplate(
    string $name,
    string $scopeType = 'interior',
    string $category = 'other',
    ?float $fixedPrice = null,
    ?float $estimatedHours = null,
    ?float $materialCost = null,
    ?string $description = null,
    ?array $surfaces = null,
    bool $includesWalls = false,
    bool $includesCeiling = false,
    bool $includesTrim = false,
    bool $includesDoor = false,
): array {
    $template = NestedAreaTemplate::create([
        'name' => $name,
        'scope_type' => $scopeType,
        'category' => $category,
        'description' => $description,
        'fixed_price' => $fixedPrice ?? 0,
        'use_margin_pricing' => ($estimatedHours !== null && $estimatedHours > 0),
        'estimated_hours' => $estimatedHours,
        'material_cost' => $materialCost,
        'includes_walls' => $includesWalls,
        'includes_ceiling' => $includesCeiling,
        'includes_trim' => $includesTrim,
        'includes_door' => $includesDoor,
        'is_active' => true,
        'sort_order' => 999,
    ]);

    if (! empty($surfaces)) {
        foreach ($surfaces as $index => $surfaceData) {
            \App\Models\NestedTemplateSurface::create([
                'nested_area_template_id' => $template->id,
                'name' => $surfaceData['name'],
                'surface_type' => $surfaceData['surface_type'],
                'calculation_method' => $surfaceData['calculation_method'] ?? 'ratio',
                'calculation_value' => $surfaceData['calculation_value'] ?? 1.0,
                'unit_type' => $surfaceData['unit_type'] ?? 'sqft',
                'production_rate_id' => $surfaceData['production_rate_id'] ?? null,
                'default_coats' => $surfaceData['default_coats'] ?? 2,
                'is_required' => false,
                'is_default_enabled' => true,
                'sort_order' => $index,
            ]);
        }
    }

    return [
        'id' => $template->id,
        'name' => $template->name,
        'category' => $template->category,
        'scope_type' => $template->scope_type,
        'fixed_price' => (float) $template->fixed_price,
        'estimated_hours' => $estimatedHours,
        'has_surfaces' => ! empty($surfaces),
        'surfaces_count' => count($surfaces ?? []),
    ];
}
```

- [ ] **Step 2: Add `createTemplate()` to `EstimateAiService`**

This creates an `EstimateTemplate` (used by `SearchTemplatesAiTool` and `CreateAreaFromTemplateAiTool`).

```php
/**
 * Create a room/area template (EstimateTemplate) reusable across estimates.
 *
 * @param  array<string, array{length: float, width: float, height: float}>|null  $sizes  Keys: small|medium|large
 * @param  array<int, array{name: string, surface_type: string, production_rate_id: int}>|null  $surfaces
 * @return array{id: int, name: string, scope_type: string, category: string}
 */
public function createTemplate(
    string $name,
    string $scopeType,
    string $category = 'room',
    string $pricingMethod = 'production_rate',
    ?array $sizes = null,
    ?array $surfaces = null,
    ?string $description = null,
    ?float $basePrice = null,
    ?float $perSqftRate = null,
): array {
    $metadata = [];

    if ($sizes) {
        $metadata['dimensions'] = $sizes;
    }

    $template = EstimateTemplate::create([
        'name' => $name,
        'scope_type' => $scopeType,
        'category' => $category,
        'pricing_method' => $pricingMethod,
        'description' => $description,
        'base_price' => $basePrice,
        'per_sqft_rate' => $perSqftRate,
        'metadata' => $metadata,
        'is_active' => true,
        'sort_order' => 999,
    ]);

    if (! empty($surfaces)) {
        foreach ($surfaces as $index => $surfaceData) {
            $template->surfaces()->create([
                'name' => $surfaceData['name'],
                'surface_type' => $surfaceData['surface_type'],
                'surface_category' => $surfaceData['surface_type'],
                'production_rate_id' => $surfaceData['production_rate_id'] ?? null,
                'sort_order' => $index,
            ]);
        }
    }

    return [
        'id' => $template->id,
        'name' => $template->name,
        'scope_type' => $template->scope_type,
        'category' => $template->category,
        'pricing_method' => $template->pricing_method,
        'has_sizes' => ! empty($sizes),
        'has_surfaces' => ! empty($surfaces),
    ];
}
```

- [ ] **Step 3: Add tests for createNestedTemplate and createTemplate**

Add to `tests/Feature/Ai/EstimateAiServiceMethodsTest.php`:

```php
// ===== Create Nested Template =====

it('creates a nested area template', function () {
    $result = $this->service->createNestedTemplate(
        name: 'Large Walk-in Closet',
        scopeType: 'interior',
        category: 'closet',
        fixedPrice: 150.00,
        estimatedHours: 3.0,
        includesWalls: true,
        includesCeiling: true,
    );

    expect($result['id'])->toBeInt()
        ->and($result['name'])->toBe('Large Walk-in Closet')
        ->and($result['category'])->toBe('closet');
});

// ===== Create Template =====

it('creates a room template with sizes', function () {
    $result = $this->service->createTemplate(
        name: 'Sunroom',
        scopeType: 'interior',
        category: 'room',
        sizes: [
            'medium' => ['length' => 16, 'width' => 14, 'height' => 9],
            'large' => ['length' => 20, 'width' => 16, 'height' => 10],
        ],
    );

    expect($result['id'])->toBeInt()
        ->and($result['name'])->toBe('Sunroom')
        ->and($result['has_sizes'])->toBeTrue();
});
```

- [ ] **Step 4: Run all tests**

```bash
php artisan test --compact tests/Feature/Ai/EstimateAiServiceMethodsTest.php
```
Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/EstimateAiService.php tests/Feature/Ai/EstimateAiServiceMethodsTest.php
git commit -m "feat(ai): add createNestedTemplate and createTemplate to EstimateAiService"
```

---

## Task 7: Run all AI tests and full suite check

- [ ] **Step 1: Run all AI-related tests**

```bash
php artisan test --compact tests/Feature/Ai/
```
Expected: ALL PASS

- [ ] **Step 2: Run format check**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 3: Run all estimate tests to check for regressions**

```bash
php artisan test --compact tests/Feature/Livewire/Estimates/
```
Expected: ALL PASS (or same baseline as before)

- [ ] **Step 4: Final commit with all tracked changes**

Commit all remaining modified files (the ones from git status that haven't been committed yet):

```bash
git add app/Ai/Gateway/XaiTextGateway.php
git add app/Ai/Tools/Estimate/SetTierPricingAiTool.php
git add app/Events/EstimateAreaUpdated.php
git add app/Events/EstimateAiToolCompleted.php
git add app/Models/EstimateArea.php
git add app/Models/EstimateAreaSurface.php
git add app/Models/EstimateCostItem.php
git add app/Models/NestedAreaTemplate.php
git add app/Models/NestedTemplateSurface.php
git add app/Models/ProductionRate.php
git add app/Models/TemplateCostItem.php
git add app/Services/ProductionRateService.php
git add database/migrations/
git add "resources/views/components/estimates/⚡template-builder.blade.php"
git add "resources/views/components/public/⚡estimate-proposal.blade.php"
git add app/Ai/Tools/Estimate/
git add tests/Feature/Ai/
git commit -m "feat(ai): complete AI estimating system — tools, service methods, models, migrations"
```

---

## Key Design Decisions

1. **`surface_category` validation in `createProductionRate()`** — validates against `ProductionRate::INTERIOR_CATEGORIES` / `EXTERIOR_CATEGORIES` key sets; throws `InvalidArgumentException` for invalid values. This prevents bad data from entering the DB.

2. **`setAreaPackageTiers()` treats `[]` as null** — empty array and `null` both mean "all tiers." Only non-empty arrays with valid tier names restrict visibility.

3. **`updateSurfaceTierOverrides()` `_base` snapshot** — captured on first call to preserve the original surface values. This allows the UI to show the "unmodified" baseline state.

4. **`updateAiContext()` appends rather than replaces** — AI notes accumulate across sessions. The `ai_summary` field is replaced (proposal summary should be fresh each time).

5. **`createTemplate()` requires `EstimateTemplate` model** — not `NestedAreaTemplate`. The `EstimateTemplate` is for main area templates (Living Room, Bedroom) used in `CreateAreaFromTemplateAiTool`. `NestedAreaTemplate` is for addons (closets, half baths).
