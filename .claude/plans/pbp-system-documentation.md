# PBP (Painting Business Pricing) System Documentation

## Overview

The PBP system is a complete painting estimate pricing engine that calculates accurate labor and material costs based on:
- Area dimensions (sqft, linear feet)
- Surface conditions (substrates)
- Production rates by surface type
- Paint product selection
- Margin calculations (profit, overhead, markup)

## Database Schema

### New Tables Created

#### 1. `substrates`
Surface conditions that affect prep time and paint time.

| Column | Type | Description |
|--------|------|-------------|
| name | string | "Previously Painted - Good Condition" |
| code | string | "PREV-GOOD" (unique identifier) |
| scope_type | enum | interior, exterior, both |
| category | enum | standard, wood, masonry, metal |
| requires_prime | boolean | Whether priming is required |
| requires_sand | boolean | Whether sanding is required |
| requires_scrape | boolean | Whether scraping is required |
| requires_wash | boolean | Whether pressure washing is required |
| prep_time_multiplier | decimal | 1.0 = normal, 2.0 = double prep time |
| paint_time_multiplier | decimal | 1.0 = normal, 1.2 = 20% more paint time |
| material_multiplier | decimal | 1.0 = normal, 1.5 = 50% more material |
| default_production_rate | integer | Sqft per hour |

#### 2. `paint_brands`
Paint manufacturer brands (SW, BM, Behr, PPG).

| Column | Type | Description |
|--------|------|-------------|
| name | string | "Sherwin-Williams" |
| code | string | "SW" |
| logo_path | string | Optional logo image |
| website_url | string | Brand website |

#### 3. `paint_products`
Individual paint products with pricing and coverage.

| Column | Type | Description |
|--------|------|-------------|
| paint_brand_id | FK | Link to brand |
| name | string | "Duration Home" |
| code | string | "SW-DUR-HOME" |
| sheen | enum | flat, matte, eggshell, satin, semi-gloss, gloss |
| product_type | enum | paint, primer, stain, sealer |
| scope_type | enum | interior, exterior, both |
| surface_type | string | walls, ceiling, trim, siding, all |
| price_per_gallon | decimal | $79.99 |
| price_per_five_gallon | decimal | $349.99 |
| coverage_sqft_brush_roll | integer | 400 sqft/gallon |
| coverage_sqft_spray | integer | 550 sqft/gallon |
| recommended_coats | integer | 2 |
| second_coat_coverage_multiplier | decimal | 1.25 (25% better coverage on 2nd coat) |

#### 4. `nested_area_templates`
Fixed-price templates for common small spaces (closets, half baths).

| Column | Type | Description |
|--------|------|-------------|
| name | string | "Small Closet" |
| code | string | "CLOSET-SM" |
| category | enum | closet, bathroom, utility, other |
| fixed_price | decimal | $95.00 |
| include_in_package_pricing | boolean | Include when calculating package rates |
| default_length/width/height | decimal | Default dimensions |
| includes_walls/ceiling/trim/door | boolean | What's included |

#### 5. `estimate_area_paint_selections`
Links areas to specific paint products for each surface.

| Column | Type | Description |
|--------|------|-------------|
| estimate_area_id | FK | Link to area |
| paint_product_id | FK | Link to paint product |
| surface_type | enum | walls, ceiling, trim, doors, windows, cabinets |
| coats | integer | Number of coats |
| sqft_covered | decimal | Square footage to paint |
| gallons_needed | decimal | Calculated gallons |
| paint_cost | decimal | Product cost |
| recycle_fee | decimal | $0.54/gallon |
| total_material_cost | decimal | Total cost |
| color_name/color_code | string | Selected color |

### Modified Tables

#### `estimate_areas` - Added columns:
- `parent_area_id` - For nested areas (closets attached to bedrooms)
- `nested_area_template_id` - Link to template if from template
- `substrate_id` - Surface condition affecting time
- `scope_type` - interior/exterior
- `perimeter_lnft` - Linear feet of perimeter
- `baseboard_lnft` - Linear feet of baseboard
- `application_method` - brush_roll or spray
- `is_fixed_price` - True for closets, half baths
- `fixed_price_amount` - The fixed price

## Models

### New Models

1. **Substrate** (`app/Models/Substrate.php`)
   - Scopes: `active()`, `interior()`, `exterior()`, `forScope()`, `category()`
   - Accessors: `getTotalTimeMultiplierAttribute()`, `getPrepRequirementsAttribute()`

2. **PaintBrand** (`app/Models/PaintBrand.php`)
   - Relationships: `products()`, `activeProducts()`
   - Scopes: `active()`, `ordered()`

3. **PaintProduct** (`app/Models/PaintProduct.php`)
   - Relationships: `brand()`, `areaSelections()`
   - Methods: `getCoverage()`, `calculateGallons()`, `calculateCost()`
   - Scopes: `active()`, `forScope()`, `interior()`, `exterior()`, `sheen()`, `productType()`

4. **NestedAreaTemplate** (`app/Models/NestedAreaTemplate.php`)
   - Relationships: `productionRate()`, `estimateAreas()`
   - Scopes: `active()`, `closets()`, `bathrooms()`, `category()`, `forScope()`
   - Accessors: `getFormattedPriceAttribute()`, `getIncludedItemsAttribute()`

5. **EstimateAreaPaintSelection** (`app/Models/EstimateAreaPaintSelection.php`)
   - Relationships: `estimateArea()`, `paintProduct()`
   - Methods: `calculateCost()`
   - Accessors: `getPaintNameAttribute()`, `getColorDisplayAttribute()`

### Updated Models

1. **EstimateArea** - Added:
   - Relationships: `parentArea()`, `childAreas()`, `nestedAreaTemplate()`, `substrate()`, `paintSelections()`
   - Scopes: `topLevel()`, `nested()`, `forScope()`, `interior()`, `exterior()`
   - Accessors: `getTotalFixedPriceAttribute()`, `getTotalPaintCostAttribute()`, `getIsNestedAttribute()`

## Services

### PaintingEstimateCalculatorService

The core pricing engine that implements the full PBP calculation:

```php
$service = new PaintingEstimateCalculatorService();

// Quick estimate (for on-the-fly calculations)
$result = $service->quickEstimate(
    sqft: 400,
    productionRate: 200,
    coats: 2,
    substrateId: $substrate->id  // optional
);
// Returns: hours, labor_cost, price_with_margins

// Calculate paint for a surface
$paint = $service->calculatePaintForSurface(
    sqft: 400,
    paintProductId: $product->id,
    coats: 2,
    applicationMethod: 'brush_roll'
);
// Returns: gallons, paint_cost, recycle_fee, total_cost

// Full area calculation
$areaResult = $service->calculateArea($area);
// Returns: sqft, labor, materials, nested_areas, subtotal, total

// Complete estimate
$estimate = $service->calculateEstimate($areas);
// Returns: areas[], summary, pricing settings

// Get pricing explanation for transparency
$explanation = $service->getPricingExplanation();
// Returns: formula, steps[], settings
```

## Pricing Formula

The PBP pricing formula follows these steps:

1. **Calculate Hours**: `sqft ÷ production_rate × substrate_multipliers × coat_multiplier`
2. **Calculate Labor Cost**: `hours × effective_hourly_rate`
3. **Calculate Materials**: `gallons_needed × price_per_gallon + recycle_fee`
4. **Get Subtotal**: `labor_cost + material_cost`
5. **Apply Profit**: `subtotal × (1 + profit_percent)`
6. **Apply Overhead**: `with_profit × (1 + overhead_percent)`
7. **Apply Markup**: `with_overhead × (1 + markup_percent)`

### Default Values (from PBP Spreadsheets)

**Interior:**
- Base Hourly Wage: $21/hr
- Profit Margin: 30%
- Overhead: 10%
- Markup: 50%

**Exterior:**
- Base Hourly Wage: $27/hr
- Profit Margin: 30%
- Overhead: 12%
- Markup: 41%

## Seeders

### Available Seeders

```bash
# Seed substrates (surface conditions)
php artisan db:seed --class=SubstrateSeeder

# Seed paint catalog (brands + products)
php artisan db:seed --class=PaintCatalogSeeder

# Seed nested area templates (closets, half baths)
php artisan db:seed --class=NestedAreaTemplateSeeder
```

### Seeded Data

**Substrates (22 total):**
- Interior: Previously Painted (Good/Fair), New Drywall, Repaired, Peeling, Smoke/Water Damage, Wood Trim (Stained/Painted), Bare Wood
- Exterior: Previously Painted (Good/Fair/Peeling), Wood Siding (Normal/Weathered), Cedar Shake, Stucco (Normal/Damaged), Brick, Concrete, Metal (Painted/Rusty)

**Paint Brands (4):**
- Sherwin-Williams (SW)
- Benjamin Moore (BM)
- Behr (BEHR)
- PPG

**Paint Products (25+):**
- SW: Duration Home, Cashmere, SuperPaint, ProMar 200/400, ProClassic Trim, Duration Exterior, A-100, Primers
- BM: Regal Select, Aura, Ben, Advance, Aura Exterior, Ultra Spec
- Behr: Marquee, Ultra, Premium Plus, Alkyd

**Nested Area Templates (15):**
- Closets: Small ($95), Medium ($165), Large ($245), w/ Shelving ($195)
- Bathrooms: Half ($175), Full Standard ($285), Full Large ($385)
- Utility: Laundry Small ($145), Laundry Standard ($225), Pantry ($125), Mudroom Small/Large
- Other: Stairwell ($325), Entry Foyer Small/2-Story

## Factories

All models have factories with named states:

```php
// Substrates
Substrate::factory()->previouslyPainted()->create();
Substrate::factory()->newDrywall()->create();
Substrate::factory()->peelingPaint()->create();
Substrate::factory()->woodSiding()->create();
Substrate::factory()->stucco()->create();

// Paint Products
PaintProduct::factory()->durationHome()->create();
PaintProduct::factory()->proMar200()->create();
PaintProduct::factory()->durationExterior()->create();
PaintProduct::factory()->primer()->create();
PaintProduct::factory()->ceilingPaint()->create();
PaintProduct::factory()->trimPaint()->create();

// Nested Area Templates
NestedAreaTemplate::factory()->smallCloset()->create();
NestedAreaTemplate::factory()->mediumCloset()->create();
NestedAreaTemplate::factory()->largeCloset()->create();
NestedAreaTemplate::factory()->halfBath()->create();
NestedAreaTemplate::factory()->laundryRoom()->create();
```

## Tests

```bash
# Run PBP-specific tests
php artisan test --compact tests/Feature/Services/PaintingEstimateCalculatorServiceTest.php

# All tests pass: 11 tests, 44 assertions
```

## Remaining Work

### Phase 7: Rebuild Detailed Builder with Full Integration
- Update `Estimates/Builder.php` Livewire component to use:
  - Substrate selection per area
  - Paint product selection per surface
  - Nested area templates (add closets to rooms)
  - Real-time pricing calculation

### Phase 8: Build Template Management UI
- Admin interface to manage:
  - Substrates (add/edit/delete)
  - Paint brands and products
  - Nested area templates
  - Production rates (already exists)

## File Locations

```
app/
├── Models/
│   ├── Substrate.php
│   ├── PaintBrand.php
│   ├── PaintProduct.php
│   ├── NestedAreaTemplate.php
│   ├── EstimateAreaPaintSelection.php
│   └── EstimateArea.php (updated)
├── Services/
│   └── PaintingEstimateCalculatorService.php
database/
├── factories/
│   ├── SubstrateFactory.php
│   ├── PaintBrandFactory.php
│   ├── PaintProductFactory.php
│   ├── NestedAreaTemplateFactory.php
│   └── EstimateAreaPaintSelectionFactory.php
├── migrations/
│   ├── 2026_01_30_192115_create_substrates_table.php
│   ├── 2026_01_30_192117_create_paint_brands_table.php
│   ├── 2026_01_30_192119_create_paint_products_table.php
│   ├── 2026_01_30_192121_create_nested_area_templates_table.php
│   ├── 2026_01_30_192123_add_nesting_and_substrate_to_estimate_areas_table.php
│   └── 2026_01_30_192125_create_estimate_area_paint_selections_table.php
├── seeders/
│   ├── SubstrateSeeder.php
│   ├── PaintCatalogSeeder.php
│   └── NestedAreaTemplateSeeder.php
tests/Feature/Services/
└── PaintingEstimateCalculatorServiceTest.php
```
