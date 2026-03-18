# Data Model — How Everything Connects

## The Data Hierarchy

Understanding the data model is essential. Every tool you call creates or modifies one of these models. When something is wrong with pricing, it is almost always because a value is coming from the wrong source. This document tells you exactly where every value lives and how models relate to each other.

```
Estimate
├── EstimateArea (a room or space)
│   ├── EstimateAreaSurface (a paintable surface within the room)
│   │   ├── ProductionRate (speed data — how fast a painter works)
│   │   └── PaintProduct (material cost data — paint price and coverage)
│   ├── EstimateAreaAddon (fixed-price add-on from NestedAreaTemplate)
│   └── Substrate (surface condition that modifies labor time)
├── EstimateItem (standalone line items — equipment, subcontractor work)
└── EstimateCostItem (material/rental costs attached to areas or surfaces)
```

---

## Estimate

The top-level container for the entire painting proposal. One estimate = one project for one customer.

**Key fields:**
- `title` — The estimate name shown to the customer on the proposal (e.g., "Interior Painting - Smith Residence")
- `project_type` — `"interior"`, `"exterior"`, or `"both"`. This is the overall project scope. It determines which wages, margins, cleanup ratios, and sundries rates apply globally. Individual areas can have their own scope_type that overrides this for per-area calculations.
- `paint_tiers` — JSON configuration for Good/Better/Best paint product selections. When tier pricing is enabled, this stores the paint product mappings for each tier, allowing the customer to choose between three quality levels.
- `status` — The estimate lifecycle: `"draft"` (being built), `"sent"` (emailed to customer), `"accepted"` (customer signed), `"rejected"` (customer declined), `"expired"` (past valid_until date)
- `total` — The grand total customer price. This is automatically calculated from all areas + items + options - discounts + tax. Do not set this manually.
- `subtotal` — Pre-tax, pre-discount total
- `tax_amount` — Calculated tax
- `deposit_amount` — Amount due upfront (based on deposit_percent)
- `balance_due` — Remaining amount after deposit
- `ai_context` — Free-text notes you save for future conversations using `save_ai_context_ai_tool`. Store important project details here: customer preferences, special requirements, decisions made, anything you want to remember next time you work on this estimate.
- `ai_summary` — Markdown summary you write for the customer proposal using `write_estimate_summary_ai_tool`. This appears on the public proposal page the customer sees. Write it in clear, customer-facing language.
- `margin_discount_percent` — Optional discount that reduces the effective margin percentage. When set, the customer gets a lower price because the company accepts a lower margin.
- `hourly_rate_override` — Optional override for the hourly labor rate. When set, this rate is used instead of CompanySetting rates for all COGS calculations on this estimate.

---

## EstimateArea (a room or space)

Represents one area on the estimate — a room, a section of exterior, a stairwell, a deck, etc. Each area becomes a visible card on the Template Builder page and a section on the customer proposal.

**Key fields:**
- `name` — The area name shown on the area card AND the customer proposal. Must be professional and descriptive. Use Title Case. Examples: "Master Bedroom", "Kitchen", "Front Porch", "Upstairs Hallway". Never use generic names like "Room 1" or "bedroom".
- `scope_type` — `"interior"` or `"exterior"`. Determines which hourly wages, margins, cleanup ratios, and sundries rates are used for this specific area. An estimate can contain both interior and exterior areas.
- `length` — Room length in feet. **OPTIONAL.** Can be null for single-face work where only width and height matter (e.g., "front of house is 45ft wide, 12ft tall" — no depth/length needed). When length is null and wall_count is 1, the system uses width alone.
- `width` — Room width in feet. Required when using dimensions measurement type.
- `height` — Room/wall height in feet. Required when using dimensions measurement type.
- `wall_count` — Integer from 1 to 4 (default 4). Controls what fraction of a full room's perimeter and wall area is calculated:
  - **4** = Full enclosed room (bedroom, bathroom, office). 100% of perimeter and wall sqft.
  - **3** = Room open on one side (kitchen open to dining, living room with archway). 75% of perimeter and wall sqft.
  - **2** = Corner section or L-shaped area. 50% of perimeter and wall sqft.
  - **1** = Single wall or single face (accent wall, front of house, fence section). 25% of perimeter and wall sqft.
- `substrate_id` — Optional foreign key to a Substrate record. When set, the substrate's prep/paint/material multipliers affect all labor calculations on all surfaces in this area.
- `measurement_type` — How dimensions are entered:
  - `"dimensions"` (default) — User enters length, width, height. System auto-calculates wall_sqft, ceiling_sqft, perimeter_lnft.
  - `"sqft"` — User enters wall_sqft and ceiling_sqft directly via direct_sqft and direct_ceiling_sqft fields.
  - `"lnft"` — User enters linear feet directly via direct_lnft field.

**Auto-calculated dimension fields (computed on save):**
- `perimeter_lnft` — Room perimeter: `2 * (length + width) * (wall_count / 4)`. When length is null, the system uses width only. wall_count scales this proportionally.
- `wall_sqft` — Total wall area: `perimeter_lnft * height`. Inherits the wall_count adjustment from perimeter.
- `ceiling_sqft` — Ceiling area: `length * width`. NOT affected by wall_count. When length is null, this is 0 (single-wall estimates don't have a ceiling unless explicitly set).

**Critical:** These three auto-calculated fields (wall_sqft, ceiling_sqft, perimeter_lnft) are what surfaces use for their quantities. When you create an area with dimensions, these values are computed automatically on save. Every surface on the area derives its quantity from one of these three values (or from a manual quantity_override). Note that wall_count affects both perimeter_lnft and wall_sqft — a 3-wall room gets 75% of the measurements of a 4-wall room.

**Pricing fields (cached, auto-calculated):**
- `cached_cogs` — Snapshot of this area's COGS (Cost of Goods Sold) from the last calculation
- `cached_customer_price` — Snapshot of this area's customer-facing price from the last calculation
- `cached_tier_prices` — JSON with per-tier COGS and price snapshots for Good/Better/Best
- `package_tiers` — JSON array of which package tiers include this area. Null means all tiers.

---

## EstimateAreaSurface (a paintable surface)

Represents one paintable surface within an area — walls, ceiling, baseboards, a set of cabinet doors, crown molding, etc. Each surface becomes a line item in the area's breakdown and appears on the customer proposal.

**Key fields:**
- `name` — The surface label shown on the area card, in the expanded breakdown, and on the customer proposal. Must be descriptive and professional. "Crown Molding" not "Trim". "Cabinet Door Faces" not "Other". See the Surface Building Guide (04) for complete naming rules.
- `surface_category` — The category that determines which production rates are available in the dropdown AND how quantity is sourced from the area's dimensions. Valid interior categories: `walls`, `ceiling`, `baseboard`, `doors`, `windows`, `trim`, `prep`. Valid exterior categories: `body`, `eaves`, `fascia`, `trim`, `windows`, `exterior_doors`, `wood_replacement`, `prep`. This is the most important field on a surface — if it is wrong, the production rate dropdown will show wrong rates and the quantity will come from the wrong dimension.
- `production_rate_id` — Foreign key to the ProductionRate record that defines painting speed for this surface. The rate's `surface_category` must match this surface's `surface_category`.
- `production_rate_value` — **Snapshot** of the production rate value at creation time (e.g., 200 for 200 sqft/hr). This snapshot ensures the estimate's pricing does not change if the master production rate is updated later.
- `quantity` — How much surface to paint. The value and unit depend on the surface_category and quantity source: sqft for walls/ceilings, lnft for baseboards/trim, unit count for doors/windows.
- `unit` — `"sqft"`, `"lnft"`, or `"units"`. Must match the production rate's unit_type.
- `coats` — Number of paint coats (1-5). Affects labor hours via the production rate's coat multiplier. Default is 2.
- `labor_hours` — **Calculated field:** `quantity / production_rate_value * coat_multiplier * substrate_prep_multiplier * substrate_paint_multiplier`. Do not set this manually for non-custom surfaces.
- `labor_cost` — **Calculated field:** `labor_hours * effective_hourly_rate` (from CompanySetting, NOT from ProductionRate).
- `material_cost` — **Calculated field:** Derived from PaintProduct pricing and coverage. NOT from ProductionRate.
- `hourly_labor_rate` — **Snapshot** of the effective hourly rate used at creation time. Stored for reference so the estimate can be audited later.
- `application_method` — `"brush_roll"` (default) or `"spray"`. Affects material cost calculations because spray uses more paint per sqft than brush/roll. Matters for exterior spray jobs and cabinet spray work.
- `paint_product_id` — Optional per-surface paint product override. When set, this surface uses a specific paint product instead of the area/estimate default.
- `tier_overrides` — JSON containing per-tier snapshots for Good/Better/Best pricing. Each tier can have different coats, production_rate_value, labor_hours, labor_cost, and material_cost. Structure: `{"good": {...}, "better": {...}, "best": {...}}`.
- `is_custom` — Boolean. When true, this is a custom-priced surface where labor_hours and material_cost are entered manually instead of being calculated from a production rate. Used for specialty items where standard rate calculations do not apply (e.g., murals, decorative finishes, unusual surfaces with negotiated pricing).
- `is_included` — Boolean. When false, the surface exists but is excluded from pricing calculations. Useful for optional items.
- `total` — **Calculated field:** `labor_cost + material_cost`. This is the surface's COGS (Cost of Goods Sold). Margins are NOT applied at the surface level — margin is applied once at the area level.

---

## ProductionRate (painting speed data)

Defines how fast a professional painter works on a specific surface type. Production rates are purely speed data — they tell you how many square feet per hour, linear feet per hour, or how many hours per unit a painter can complete. They do NOT contain pricing or wage information.

**Key fields:**
- `name` — Descriptive name like "Textured Walls same color", "Baseboards same color 1x2", "Ext Door body color 1 side"
- `scope` — `"interior"` or `"exterior"`. Determines which scope this rate is used for.
- `surface_category` — Which surface category this rate applies to. Must be one of: `walls`, `ceiling`, `baseboard`, `doors`, `windows`, `trim`, `prep` (interior) or `body`, `eaves`, `fascia`, `trim`, `windows`, `exterior_doors`, `wood_replacement`, `prep` (exterior).
- `production_rate` — The numeric rate value (e.g., 200 for 200 sqft/hr, 40 for 40 lnft/hr, 1.0 for 1 hr/unit)
- `rate_type` — How to interpret the production_rate number:
  - `sqft_hr` — Square feet per hour. Labor hours = quantity / rate. For flat surfaces measured in sqft.
  - `lnft_hr` — Linear feet per hour. Labor hours = quantity / rate. For long narrow surfaces.
  - `units_hr` — Units per hour. Labor hours = quantity / rate. For discrete items completed quickly (< 1 hr each).
  - `hours_per_unit` — Hours per single unit. Labor hours = quantity * rate. For discrete items that take a long time (>= 1 hr each).
  - `cost_per_lnft` — Dollar cost per linear foot. This is cost-based, NOT time-based. Used for wood replacement where material and labor are bundled together.
- `unit_type` — `"sqft"`, `"lnft"`, `"unit"`, or `"hour"`. Describes what measurement the rate works with.
- `coat_multipliers` — JSON object mapping coat count to time multiplier: `{"1": 1.0, "2": 1.8, "3": 2.5}`. Two coats is NOT double the time — the first coat includes prep/cutting in, so subsequent coats are faster.
- `default_coats` — Default number of coats if not specified by the user. Typically 2 for most surfaces, 1 for prep work.

**CRITICAL relationship:** A production rate's `surface_category` MUST match the surface's `surface_category` for the rate to appear in the Area Builder's production rate dropdown. If you assign category `"walls"` to a surface, only production rates with `surface_category="walls"` will be available. If you assign category `"baseboard"` to a surface, only production rates with `surface_category="baseboard"` will be available. This is why getting the category right in Step 1 of the Surface Building Guide is essential — the wrong category means the wrong rates, which means wrong pricing.

---

## PaintProduct (material cost data)

Paint pricing and coverage information. This is where ALL material costs come from.

**Key fields:**
- `name` — Product name: "Duration", "SuperPaint", "ProMar 200", "Regal Select"
- `brand` — Links to PaintBrand (Sherwin-Williams, Benjamin Moore, Behr, PPG)
- `quality_tier` — `"good"`, `"better"`, or `"best"`. Used for tier pricing. "Good" = builder grade, "Better" = mid-range, "Best" = premium.
- `price_per_gallon` — Cost of one gallon of paint. This is the primary input for material cost calculations.
- `coverage_sqft_brush_roll` — Square feet of coverage per gallon when brush/rolling (e.g., 400 sqft)
- `coverage_sqft_spray` — Square feet of coverage per gallon when spraying (typically lower than brush/roll because spray uses more paint)
- `scope_type` — `"interior"`, `"exterior"`, or `"both"`. Filters which products are available for a given scope.
- `sheen` — `"flat"`, `"matte"`, `"eggshell"`, `"satin"`, `"semi-gloss"`, `"gloss"`. Different sheens are used on different surfaces (flat for ceilings, eggshell for walls, semi-gloss for trim/doors).

**CRITICAL:** Material costs come from PaintProduct, NOT from ProductionRate. ProductionRate only provides speed (labor hours). PaintProduct provides material cost. If you need to know how much paint costs, look at PaintProduct. If you need to know how long painting takes, look at ProductionRate. Never confuse the two.

**Material cost calculation:** Gallons needed = (quantity * coats) / coverage_sqft. Total material cost = gallons (rounded up to whole gallons) * price_per_gallon + recycle_fee_per_gallon. The application method (brush_roll vs spray) affects which coverage value is used.

---

## Substrate (surface condition modifiers)

Modifies labor time and material usage based on the condition of the surface being painted. Applied at the area level — all surfaces in the area inherit the substrate's multipliers.

**Key fields:**
- `prep_time_multiplier` — Multiplier for prep labor time. 1.0 = normal, 1.5 = 50% more prep time (e.g., peeling paint needs more scraping). Applied to labor hours calculation.
- `paint_time_multiplier` — Multiplier for painting labor time. 1.0 = normal, 1.2 = 20% more painting time (e.g., new drywall absorbs more paint). Applied to labor hours calculation.
- `material_multiplier` — Multiplier for material usage. 1.0 = normal, 1.3 = 30% more paint needed (e.g., porous surfaces absorb paint). Applied to paint coverage calculations.

**How multipliers affect pricing:** Labor hours = `(quantity / production_rate) * prep_time_multiplier * paint_time_multiplier * coat_multiplier`. All three multipliers compound together. A surface with 1.5x prep and 1.2x paint on a peeling exterior surface will take 1.8x as long as a standard surface.

**Examples:** "Previously Painted - Good Condition" has multipliers near 1.0. "New Drywall" might have prep=1.0, paint=1.2, material=1.3 (needs primer coat, absorbs more paint). "Heavily Peeling Exterior" might have prep=2.0, paint=1.3, material=1.5 (extensive scraping, multiple coats needed).

---

## NestedAreaTemplate (fixed-price add-ons)

Pre-configured items with fixed prices that are added to areas as add-ons. These represent small, standardized work items where calculating from dimensions/rates would be overkill.

**Common examples:** Closets (walk-in, reach-in), half baths, accent walls, minor repairs, warranties, specialty finishes.

**Key fields:**
- `name` — Template name: "Walk-in Closet", "Half Bath", "Minor Wood Repair"
- `fixed_price` — The customer-facing price for this add-on
- `estimated_hours` — Hours of labor this add-on takes (used for COGS/profitability tracking)
- `material_cost` — Material cost for this add-on (used for COGS/profitability tracking)
- `category` — `"closet"`, `"bathroom"`, `"utility"`, `"other"`
- `scope_type` — `"interior"`, `"exterior"`, or `"both"`

**How add-ons work:** When added to an area via an EstimateAreaAddon record, the add-on's total_price (unit_price * quantity) is included in the area's COGS calculation. The add-on's labor hours and material costs contribute to the overall profitability tracking.

---

## EstimateItem (standalone line items)

Traditional line items that are NOT room-based. Used for equipment, subcontractor work, or items that don't fit the area/surface model.

**Key fields:**
- `description` — What the item is: "Pressure Washer Rental", "Dumpster Fee", "Scaffolding"
- `quantity` — How many
- `unit_price` — Price per unit
- `total` — quantity * unit_price
- `cost` — Internal vendor/company cost (for profitability tracking)

---

## EstimateCostItem (material/rental costs)

Material, rental, or equipment costs that can be attached to areas OR individual surfaces via a polymorphic relationship. These are included in COGS calculations.

**Key fields:**
- `name` — "Paint Supplies", "Scaffolding Rental", "Primer", "Drop Cloths"
- `cost_type` — Type of cost item
- `quantity` — How many
- `unit_cost` — Cost per unit (what the company pays)
- `unit_price` — Price per unit (what the customer sees, if different)
- `total_cost` — Auto-calculated: quantity * unit_cost. Included in area COGS.
- `total_price` — Auto-calculated: quantity * unit_price.
- `tier` — Optional tier association (good/better/best) for tier-specific cost items

---

## CompanySetting (pricing configuration)

Singleton model containing ALL company-level pricing settings. These values are NOT stored on production rates or surfaces — they are pulled fresh from this model every time pricing is calculated. This means changing a setting here instantly affects all new calculations.

**Key fields:**
- `interior_hourly_wage` / `exterior_hourly_wage` — Base painter wage per scope. This is what the painter is paid per hour.
- `payroll_burden_multiplier` — e.g., 1.35. Accounts for taxes, insurance, workers comp, benefits. Multiplied by base wage to get the "effective hourly rate" — what the company actually spends per painter hour.
- **Effective hourly rate** = wage * payroll_burden_multiplier. Example: $21/hr wage * 1.35 burden = $28.35/hr effective rate. This effective rate is what is used for ALL labor cost calculations, never the raw base wage.
- `interior_margin_percent` / `exterior_margin_percent` — Target margin percentages per scope. The PBP formula uses this to convert COGS to customer price: `customer_price = COGS / (margin_percent / 100)`. A 50% margin means COGS is 50% of the customer price (i.e., 50% gross profit).
- `interior_cleanup_hours_ratio` / `exterior_cleanup_hours_ratio` — Cleanup time as a ratio of labor hours. Example: 0.15 means 15 minutes of cleanup for every hour of painting.
- `interior_sundries_cost_per_hour` / `exterior_sundries_cost_per_hour` — Dollar cost of sundries (tape, caulk, plastic, rags, etc.) per labor hour.
- `material_tax_rate` — Tax rate applied to material costs in the COGS calculation.
- `recycle_fee_per_gallon` — Environmental fee added per gallon of paint purchased.

---

## Quantity Source Concept

The `surface_category` on a surface determines which of the area's auto-calculated dimension fields provides the quantity for that surface. This is the bridge between room dimensions and surface measurements.

| Surface Category | Quantity Source | Unit | How the quantity is determined |
|---|---|---|---|
| `walls` | `wall_sqft` | sqft | Auto from area: `perimeter_lnft * height` |
| `body` | `wall_sqft` | sqft | Auto from area: `perimeter_lnft * height` (exterior walls/siding) |
| `ceiling` | `ceiling_sqft` | sqft | Auto from area: `length * width` |
| `eaves` | `ceiling_sqft` | sqft | Auto from area: `length * width` (soffits cover the overhead area matching the ceiling footprint) |
| `baseboard` | `perimeter_lnft` | lnft | Auto from area: `2 * (length + width) * (wall_count / 4)` |
| `trim` | `perimeter_lnft` | lnft | Auto from area perimeter (for linear trim like crown, chair rail) |
| `fascia` | `perimeter_lnft` | lnft | Auto from area perimeter |
| `doors` | `door_count` | units | Manual count via quantity_override (default 1) |
| `exterior_doors` | `door_count` | units | Manual count via quantity_override (default 1) |
| `windows` | `window_count` | units | Manual count via quantity_override (default 1) |

**When to use `custom` quantity source:** Use it when the surface does not derive from room dimensions — cabinet door faces (14 faces), stair spindles (22 count), a customer-provided sqft measurement that does not match room dimensions, or any counted item that is not a door or window.

**Important:** `"crown"`, `"molding"`, and `"siding"` are NOT valid surface_categories. Crown molding uses category `"trim"` with quantity sourced from `perimeter_lnft`. Siding uses category `"body"` with quantity sourced from `wall_sqft`. Always use the actual category values from the table above.

---

## Where Each Value Comes From

This table is the single most important reference for understanding the pricing system. When debugging incorrect pricing, check this table to verify each value is coming from the right source.

| Value | Source | NOT from |
|---|---|---|
| Labor hours | ProductionRate (speed) + coat_multiplier + substrate_multipliers | NOT CompanySetting |
| Hourly rate | CompanySetting (effective rate = base wage * payroll_burden_multiplier) | NOT ProductionRate |
| Labor cost | labor_hours * CompanySetting effective rate | — |
| Material cost | PaintProduct (price_per_gallon * gallons needed based on coverage) | NOT ProductionRate |
| Cleanup cost | CompanySetting cleanup_hours_ratio * labor_hours * effective_rate | — |
| Sundries cost | CompanySetting sundries_cost_per_hour * labor_hours | — |
| COGS | labor_cost + cleanup_cost + sundries_cost + material_cost + cost_items | — |
| Customer price (OTD) | COGS / (margin_percent / 100) from CompanySetting | — |

**Reading the "NOT from" column:** A common mistake is looking for the hourly rate on a ProductionRate record — it is not there. ProductionRate only has speed. The hourly rate always comes from CompanySetting, scoped by interior/exterior. Similarly, material cost never comes from ProductionRate — it always comes from PaintProduct. If you cannot find a value, check this table to confirm where it actually lives.
