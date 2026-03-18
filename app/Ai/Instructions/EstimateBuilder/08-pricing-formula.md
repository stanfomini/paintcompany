# Pricing Formula

## The PBP Formula (7 Steps)

Every surface goes through these steps automatically. Understand them to explain pricing and verify calculations.

1. **Labor Hours:** `(quantity / production_rate) × coat_multiplier × prep_time_multiplier × paint_time_multiplier`
   - coat_multipliers: 1 coat = 1.0x, 2 coats = 1.8x, 3 coats = 2.5x
   - For `hours_per_unit` rates: `quantity × rate` instead
2. **Labor Cost:** `labor_hours × effective_hourly_rate` (effective = base_wage × payroll_burden_multiplier)
3. **Cleanup Cost:** `(labor_hours × cleanup_ratio) × effective_hourly_rate`
4. **Sundries Cost:** `labor_hours × sundries_cost_per_hour`
5. **Material Cost:** `paint_cost + cost_items_total` (paint = gallons_needed × price_per_gallon + recycle_fee)
6. **COGS:** `labor_cost + cleanup_cost + sundries_cost + material_cost`
7. **Customer Price (OTD):** `COGS / (margin_percent / 100)` — margin applied PER AREA, not globally

## Worked Example

Interior room, 400 sqft walls, 2 coats, rate 200 sqft/hr, SuperPaint $76/gal, previously painted (multipliers 1.0).
Company settings: wage $21/hr, burden 1.35x (effective $28.35/hr), cleanup 1/16, sundries $3.30/hr, margin 50%.

```
Step 1: (400 / 200) × 1.8 × 1.0 × 1.0 = 3.6 hrs
Step 2: 3.6 × $28.35 = $102.06
Step 3: (3.6 × 0.0625) × $28.35 = $6.38
Step 4: 3.6 × $3.30 = $11.88
Step 5: (400 / 400 coverage × 2 coats) = 2 gal × $76 = $152.00
Step 6: $102.06 + $6.38 + $11.88 + $152.00 = $272.32
Step 7: $272.32 / 0.50 = $544.64 OTD
```

Customer sees **$544.64**. Company COGS $272.32, gross profit $272.32 (50%).

## Cost Items

Three levels for adding extra costs to COGS (margin applied automatically):

- **Surface-level:** `add_cost_item_ai_tool(target_type="surface", ...)` — e.g., baseboard material at $2.50/lnft
- **Area-level:** `add_cost_item_ai_tool(target_type="area", ...)` — e.g., sprayer rental $200/day × 2 days
- **Estimate-level line items:** `create_estimate_item_ai_tool(...)` — standalone charges, NO margin applied (permits, subcontractors)
