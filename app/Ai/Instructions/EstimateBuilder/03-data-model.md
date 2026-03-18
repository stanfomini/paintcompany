# Data Model

```
Estimate
├── EstimateArea (room/space)
│   ├── EstimateAreaSurface (paintable surface)
│   │   ├── ProductionRate (speed data)
│   │   └── PaintProduct (material cost data)
│   ├── EstimateAreaAddon (fixed-price add-on from NestedAreaTemplate)
│   └── Substrate (surface condition multipliers)
├── EstimateItem (standalone line items)
└── EstimateCostItem (material/rental costs, polymorphic to area or surface)
```

## Estimate

- `title` — customer-visible estimate name
- `project_type` — `"interior"`, `"exterior"`, or `"both"`; determines scope-specific wages/margins/cleanup/sundries
- `paint_tiers` — JSON Good/Better/Best paint product mappings
- `status` — `draft` → `sent` → `accepted`/`rejected`/`expired`
- `total`, `subtotal`, `tax_amount`, `deposit_amount`, `balance_due` — auto-calculated, do not set manually
- `ai_context` — free-text notes persisted across conversations via `save_ai_context_ai_tool`
- `ai_summary` — markdown summary for customer proposal via `write_estimate_summary_ai_tool`
- `margin_discount_percent` — reduces effective margin (lowers price)
- `hourly_rate_override` — overrides CompanySetting wage for this estimate

## EstimateArea

- `name` — Title Case, customer-visible section header (e.g., "Master Bedroom")
- `scope_type` — `"interior"` or `"exterior"`; per-area wage/margin/cleanup/sundries scope
- `length`, `width`, `height` — room dimensions in feet; length is optional (null for single-face work)
- `wall_count` — 1-4 (default 4); fraction of full perimeter/wall area: 4=100%, 3=75%, 2=50%, 1=25%
- `substrate_id` — optional; applies prep/paint/material multipliers to all surfaces
- `measurement_type` — `"dimensions"` (default), `"sqft"`, or `"lnft"`

**Auto-calculated on save:**
- `perimeter_lnft` = `2 * (length + width) * (wall_count / 4)`
- `wall_sqft` = `perimeter_lnft * height`
- `ceiling_sqft` = `length * width` (NOT affected by wall_count; 0 when length is null)

**Cached pricing:**
- `cached_cogs`, `cached_customer_price`, `cached_tier_prices` — snapshots from last calculation
- `package_tiers` — JSON array of which tiers include this area (null = all)

## EstimateAreaSurface

- `name` — descriptive, Title Case, customer-visible (e.g., "Crown Molding" not "Trim")
- `surface_category` — determines available production rates AND quantity source; must match the production rate's `surface_category`
  - Interior: `walls`, `ceiling`, `baseboard`, `doors`, `windows`, `trim`, `prep`
  - Exterior: `body`, `eaves`, `fascia`, `trim`, `windows`, `exterior_doors`, `wood_replacement`, `prep`
- `production_rate_id` — FK to ProductionRate
- `production_rate_value` — snapshot of rate value at creation (estimate price won't change if master rate updates)
- `quantity` — amount to paint (sqft, lnft, or unit count depending on category)
- `unit` — `"sqft"`, `"lnft"`, or `"units"`; must match production rate's unit_type
- `coats` — 1-5, default 2; hours adjusted by coat_multiplier (1=1.0x, 2=1.8x, 3=2.5x)
- `labor_hours` — calculated: `quantity / rate * coat_multiplier * substrate_multipliers`
- `labor_cost` — calculated: `labor_hours * effective_hourly_rate` (from CompanySetting)
- `material_cost` — calculated from PaintProduct price/coverage (NOT from ProductionRate)
- `hourly_labor_rate` — snapshot of effective rate used
- `application_method` — `"brush_roll"` (default) or `"spray"`; affects paint coverage/material cost
- `paint_product_id` — optional per-surface paint override
- `tier_overrides` — JSON `{"good": {...}, "better": {...}, "best": {...}}` with per-tier coats/rate/hours/costs
- `is_custom` — when true, labor_hours and material_cost are manually entered (for murals, faux finishes, etc.)
- `is_included` — when false, excluded from pricing
- `total` — `labor_cost + material_cost` (surface COGS; margin applied at area level, not here)

## ProductionRate

Speed data only — how fast a painter works. Contains NO pricing or wage information.

- `name` — e.g., "Textured Walls same color", "Baseboards same color 1x2"
- `scope` — `"interior"` or `"exterior"`
- `surface_category` — must match the surface's category for the rate to appear in the dropdown
- `production_rate` — numeric speed value (e.g., 200 for 200 sqft/hr)
- `rate_type` — determines labor hours formula:

| rate_type | Formula | Use case |
|---|---|---|
| `sqft_hr` | hours = qty / rate | Flat surfaces (walls, ceilings) |
| `lnft_hr` | hours = qty / rate | Linear surfaces (baseboards, crown) |
| `units_hr` | hours = qty / rate | Quick items (<1 hr each: windows) |
| `hours_per_unit` | hours = qty * rate | Slow items (>=1 hr: doors, cabinets) |
| `cost_per_lnft` | cost = qty * rate | Bundled cost (wood replacement) |

- `unit_type` — `"sqft"`, `"lnft"`, `"unit"`, or `"hour"`
- `coat_multipliers` — JSON: `{"1": 1.0, "2": 1.8, "3": 2.5}`
- `default_coats` — typically 2 (1 for prep)

## PaintProduct

ALL material costs come from here, never from ProductionRate.

- `name` — "Duration", "SuperPaint", "ProMar 200"
- `quality_tier` — `"good"`, `"better"`, `"best"`
- `price_per_gallon` — primary material cost input
- `coverage_sqft_brush_roll` / `coverage_sqft_spray` — sqft per gallon by method
- `scope_type` — `"interior"`, `"exterior"`, or `"both"`
- `sheen` — flat, matte, eggshell, satin, semi-gloss, gloss

**Material cost formula:** gallons = ceil((quantity * coats) / coverage). Cost = gallons * (price_per_gallon + recycle_fee).

## Substrate

Applied at area level; all surfaces inherit multipliers.

- `prep_time_multiplier` — labor prep time (1.0 = normal, 2.0 = heavy peeling)
- `paint_time_multiplier` — painting time (1.0 = normal, 1.2 = new drywall)
- `material_multiplier` — paint usage (1.0 = normal, 1.3 = porous surface)

All three compound: hours = `(qty / rate) * prep * paint * coat_multiplier`.

## NestedAreaTemplate (Fixed-Price Add-ons)

- `name`, `fixed_price`, `estimated_hours`, `material_cost`, `category`, `scope_type`
- Added to areas as EstimateAreaAddon; total_price = unit_price * quantity; included in area COGS

## EstimateItem (Standalone Line Items)

- `description`, `quantity`, `unit_price`, `total`, `cost` — for equipment, subcontractor work, non-room items

## EstimateCostItem

- `name`, `cost_type`, `quantity`, `unit_cost`, `unit_price`, `total_cost`, `total_price`, `tier`
- Polymorphic to area or surface; included in COGS

## CompanySetting

Singleton with all company pricing settings. Values pulled fresh on every calculation.

- `interior_hourly_wage` / `exterior_hourly_wage` — base painter wage
- `payroll_burden_multiplier` — e.g., 1.35; effective_rate = wage * burden
- `interior_margin_percent` / `exterior_margin_percent` — OTD = COGS / (margin% / 100)
- `interior_cleanup_hours_ratio` / `exterior_cleanup_hours_ratio` — cleanup time per labor hour
- `interior_sundries_cost_per_hour` / `exterior_sundries_cost_per_hour` — consumables cost per labor hour
- `material_tax_rate`, `recycle_fee_per_gallon`

## Quantity Source Mapping

| Surface Category | Quantity Source | Unit | Derived From |
|---|---|---|---|
| `walls` | `wall_sqft` | sqft | `perimeter_lnft * height` |
| `body` | `wall_sqft` | sqft | `perimeter_lnft * height` |
| `ceiling` | `ceiling_sqft` | sqft | `length * width` |
| `eaves` | `ceiling_sqft` | sqft | `length * width` |
| `baseboard` | `perimeter_lnft` | lnft | `2 * (L + W) * (wall_count / 4)` |
| `trim` | `perimeter_lnft` | lnft | area perimeter |
| `fascia` | `perimeter_lnft` | lnft | area perimeter |
| `doors` | `door_count` | units | manual quantity_override |
| `exterior_doors` | `door_count` | units | manual quantity_override |
| `windows` | `window_count` | units | manual quantity_override |
| (custom items) | `custom` | varies | manual quantity_override |

Note: `"crown"`, `"molding"`, `"siding"` are NOT valid categories. Crown molding = category `trim`. Siding = category `body`.

## Where Each Value Comes From

| Value | Source | NOT from |
|---|---|---|
| Labor hours | ProductionRate + coat_multiplier + substrate multipliers | NOT CompanySetting |
| Hourly rate | CompanySetting (wage * payroll_burden_multiplier) | NOT ProductionRate |
| Labor cost | labor_hours * effective rate | — |
| Material cost | PaintProduct (price/gallon * gallons from coverage) | NOT ProductionRate |
| Cleanup cost | CompanySetting cleanup_ratio * labor_hours * effective_rate | — |
| Sundries cost | CompanySetting sundries_per_hour * labor_hours | — |
| COGS | labor + cleanup + sundries + material + cost_items | — |
| Customer price (OTD) | COGS / (margin% / 100) | — |
