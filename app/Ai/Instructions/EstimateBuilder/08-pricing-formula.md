# Pricing Formula

This is the complete PBP (Painting Business Pricing) formula that determines how every surface, area, and estimate is priced. Understanding this formula helps you explain pricing to the user, verify calculations, and make informed decisions about cost items and materials.

---

## The PBP Formula (7 Steps)

Every surface on an estimate goes through these 7 steps to arrive at the customer price. The system calculates this automatically — you do not need to do the math yourself. But you need to understand each step so you can explain pricing, troubleshoot unexpected numbers, and make correct decisions about rates, coats, and materials.

### Step 1: Labor Hours

```
labor_hours = (quantity / production_rate) x coat_multiplier x prep_time_multiplier x paint_time_multiplier
```

- **quantity**: The amount of surface being painted. Comes from the area's calculated dimensions (wall_sqft, ceiling_sqft, perimeter_lnft) or from a manual quantity_override. Measured in sqft, lnft, or units.
- **production_rate**: How fast a painter works. Comes from the ProductionRate model (e.g., 200 sqft/hr for textured walls). For `hours_per_unit` rate types, the formula inverts: `quantity x rate` instead of `quantity / rate`.
- **coat_multiplier**: Adjusts labor for multiple coats. From the ProductionRate's coat_multipliers JSON. Standard values: 1 coat = 1.0x, 2 coats = 1.8x, 3 coats = 2.5x. Note: 2 coats is NOT double the time because the first coat includes prep and cutting in that doesn't repeat.
- **prep_time_multiplier**: From the area's Substrate model. Adjusts for surface condition. Default 1.0 (previously painted). Higher for difficult conditions (1.5x for peeling paint, 1.2x for new drywall).
- **paint_time_multiplier**: From the area's Substrate model. Adjusts painting speed for surface condition. Default 1.0.

### Step 2: Labor Cost

```
labor_cost = labor_hours x effective_hourly_rate
```

- **effective_hourly_rate**: The true cost of a painter per hour, including payroll taxes, insurance, and benefits. Calculated as: `base_wage x payroll_burden_multiplier`. Both values come from CompanySetting, and they are scope-specific (interior and exterior have different wages).
- **base_wage**: What the painter is paid per hour (from CompanySetting).
- **payroll_burden_multiplier**: The overhead multiplier for employment costs (e.g., 1.35 means 35% on top of base wage for taxes, workers comp, insurance).

### Step 3: Cleanup Cost

```
cleanup_cost = (labor_hours x cleanup_ratio) x effective_hourly_rate
```

- **cleanup_ratio**: How much cleanup time is needed relative to labor time. From CompanySetting, scope-specific. Example: interior cleanup_ratio = 1/16 means for every 16 hours of painting labor, 1 hour of cleanup is needed.
- The cleanup cost uses the same effective hourly rate as labor because the same painter does the cleanup.

### Step 4: Sundries Cost

```
sundries_cost = labor_hours x sundries_cost_per_hour
```

- **sundries_cost_per_hour**: Cost of consumable supplies (tape, drop cloths, caulk, sandpaper, brushes, rollers) per labor hour. From CompanySetting, scope-specific.
- This is a per-hour allocation — more labor hours means more sundries consumed.

### Step 5: Material Cost

```
material_cost = paint_cost + cost_items_total
```

- **paint_cost**: Calculated from the PaintProduct assigned to the surface. Formula: `gallons_needed x price_per_gallon + recycle_fee`. Gallons needed = `quantity / coverage_rate x coats`. Coverage rate depends on application method (brush/roll vs spray — spray uses more paint per sqft).
- **cost_items_total**: Sum of all cost items attached to the surface or area (baseboard material, primer, deglosser, rental equipment, etc.). Added via `add_cost_item_ai_tool`.

### Step 6: COGS (Cost of Goods Sold)

```
COGS = labor_cost + cleanup_cost + sundries_cost + material_cost
```

This is the total cost to the company for performing the work. It includes everything: painter time, cleanup time, supplies, paint, and any additional cost items.

### Step 7: Customer Price (OTD — Out The Door)

```
OTD = COGS / (margin_percent / 100)
```

- **margin_percent**: The target COGS-to-price ratio from CompanySetting, scope-specific. This determines how much of the customer price is COGS and how much is gross profit.
- **50% margin** means COGS is 50% of the customer price. So OTD = COGS / 0.50 = 2x COGS. The company keeps 50% gross profit.
- **41% margin** means COGS is 41% of the customer price. So OTD = COGS / 0.41 = ~2.44x COGS. The company keeps 59% gross profit.
- **Important**: Margin is applied PER AREA, not as a single global margin on the total estimate COGS. Each area calculates its own OTD independently based on its scope type's margin.

---

## Worked Example

**Scenario:** Interior room, 400 sqft of walls, 2 coats, using "Textured Walls same color" rate (200 sqft/hr), SuperPaint at $76/gallon, previously painted substrate (all multipliers = 1.0).

**Company settings (interior):**
- Base wage: $21/hr
- Payroll burden: 1.35x
- Effective rate: $21 x 1.35 = $28.35/hr
- Cleanup ratio: 1/16 (0.0625)
- Sundries: $3.30/hr
- Margin: 50%

**Step 1 — Labor Hours:**
```
(400 sqft / 200 sqft_hr) x 1.8 (2-coat multiplier) x 1.0 (prep) x 1.0 (paint) = 3.6 hours
```

**Step 2 — Labor Cost:**
```
3.6 hrs x $28.35/hr = $102.06
```

**Step 3 — Cleanup Cost:**
```
(3.6 hrs x 0.0625) x $28.35/hr = 0.225 hrs x $28.35 = $6.38
```

**Step 4 — Sundries Cost:**
```
3.6 hrs x $3.30/hr = $11.88
```

**Step 5 — Material Cost:**
```
Paint: 400 sqft / 400 sqft_per_gal x 2 coats = 2 gallons
2 gal x $76/gal = $152.00
Cost items: $0 (none added)
Total materials: $152.00
```

**Step 6 — COGS:**
```
$102.06 + $6.38 + $11.88 + $152.00 = $272.32
```

**Step 7 — Customer Price (OTD):**
```
$272.32 / 0.50 = $544.64
```

The customer sees **$544.64** for this wall surface. The company's COGS is $272.32, leaving $272.32 (50%) gross profit.

---

## How a Full Room Works

A room has multiple surfaces. Each surface calculates its own COGS independently. Then:

1. Sum all surface COGS for the area
2. Add any area-level cost items to the total COGS
3. Add any addon (nested template) costs to the total COGS
4. Apply the scope-specific margin to get the area's OTD price

Example — a medium Living Room:
- Walls: COGS $272.32
- Ceiling: COGS $118.50
- Baseboards: COGS $45.20
- Door (1): COGS $38.00
- Area COGS: $474.02
- OTD (50% margin): $474.02 / 0.50 = **$948.04**

---

## Where Each Value Comes From

This table clarifies which model is responsible for which value. This is critical because confusion about where values come from leads to wrong tool calls.

| Value | Source Model | NOT From |
|---|---|---|
| Labor hours (painting speed) | ProductionRate (rate value + coat_multipliers) | NOT CompanySetting |
| Hourly rate (what a painter costs) | CompanySetting (effective_rate = wage x burden) | NOT ProductionRate |
| Labor cost ($) | labor_hours x CompanySetting effective rate | — |
| Material cost ($) | PaintProduct (price/gallon x gallons needed) | NOT ProductionRate |
| Cleanup cost ($) | CompanySetting cleanup_ratio x labor_hours x effective_rate | — |
| Sundries cost ($) | CompanySetting sundries_per_hour x labor_hours | — |
| COGS ($) | labor + cleanup + sundries + materials + cost_items | — |
| Customer price (OTD) | COGS / (CompanySetting margin% / 100) | — |
| Substrate multipliers | Substrate model (set on area) | NOT ProductionRate |

---

## Cost Items — Adding Extra Costs to COGS

Cost items are additional materials, rentals, equipment, or services that flow into COGS so the company's margin is automatically applied. There are three levels where costs can be added:

### Surface-Level Cost Items
Attach directly to a specific surface. The cost is added to that surface's COGS before margin is applied.

**Example:** Baseboard material at $2.50 per linear foot
```
add_cost_item_ai_tool(target_type="surface", target_id=<baseboard_surface_id>, name="Baseboard Material", unit_cost=2.50, unit="lnft", quantity=52)
```
This adds $130 to the baseboard surface's material cost. With 50% margin, the customer pays an additional $260 for the material.

### Area-Level Cost Items
Attach to an area. The cost is added to the area's total COGS before margin.

**Example:** Sprayer rental for 2 days at $200/day
```
add_cost_item_ai_tool(target_type="area", target_id=<area_id>, name="Sprayer Rental", cost_type="rental", unit_cost=200, unit="day", quantity=2)
```
This adds $400 to the area's COGS. With 50% margin, the customer pays an additional $800.

### Estimate-Level Line Items
Standalone items on the estimate that are NOT part of any area's COGS. These are direct customer charges with no automatic margin application. Use for items that should be billed at a specific price.

**Example:** Scaffolding rental at a flat $500
```
create_estimate_item_ai_tool(description="Scaffolding Rental", quantity=1, unit_price=500)
```
The customer pays exactly $500. No margin is applied.

### When to Use Cost Items vs Line Items

| Scenario | Tool | Why |
|---|---|---|
| Material that's part of the painting work (primer, baseboard material, caulk) | `add_cost_item_ai_tool` | Should have margin applied — it's part of COGS |
| Equipment rental needed for the painting work (sprayer, scaffolding for a room) | `add_cost_item_ai_tool` | Should have margin applied — it's a job cost |
| Standalone service not tied to a room (dumpster, permit, power washing) | `create_estimate_item_ai_tool` | Priced independently, billed at a specific amount |
| Subcontractor charge (electrician, plumber) | `create_estimate_item_ai_tool` | Fixed price, not subject to painting margin |

---

## Scope-Specific Settings

Interior and exterior work use different pricing settings. Every area has a `scope_type` ("interior" or "exterior") that determines which set of company settings applies.

| Setting | Interior | Exterior |
|---|---|---|
| Base wage | `hourly_wage_interior` | `hourly_wage_exterior` |
| Effective rate | wage x burden | wage x burden |
| Margin % | `margin_percent_interior` | `margin_percent_exterior` |
| Cleanup ratio | `cleanup_hours_ratio_interior` | `cleanup_hours_ratio_exterior` |
| Sundries $/hr | `sundries_cost_per_hour_interior` | `sundries_cost_per_hour_exterior` |

This means an interior room and an exterior room with identical surface areas and identical rates will have different prices because the wages, margins, cleanup, and sundries may differ.
