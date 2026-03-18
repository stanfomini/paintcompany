# Tier Pricing — Good/Better/Best

Tier pricing offers customers three price levels using different paint products (and optionally coats). Both steps below are ALWAYS required — Step 1 without Step 2 leaves tier tabs empty.

## Step 1: Set Paint Products Per Tier (Estimate-Level)

Call `set_tier_pricing_ai_tool` once per tier:
```
list_paint_products_ai_tool(quality_tier="good")    → budget paints
list_paint_products_ai_tool(quality_tier="better")  → mid-range paints
list_paint_products_ai_tool(quality_tier="best")    → premium paints

set_tier_pricing_ai_tool(tier="good", paint_product_id=budget_id)
set_tier_pricing_ai_tool(tier="better", paint_product_id=midrange_id)
set_tier_pricing_ai_tool(tier="best", paint_product_id=premium_id)
```

Optional: set per-surface-category paints within a tier using `walls_paint_id`, `ceiling_paint_id`, `trim_paint_id`, `doors_paint_id` params.

## Step 2: Create Tier Snapshots on EVERY Surface — NEVER SKIP

Call `update_surface_tier_overrides_ai_tool` for EACH surface × EACH tier. Without this, tier tabs are empty.

```
get_estimate_state_ai_tool → get all surface IDs

For EACH surface:
    update_surface_tier_overrides_ai_tool(surface_id=X, tier="good", coats=1, paint_product_id=budget_id)
    update_surface_tier_overrides_ai_tool(surface_id=X, tier="better", coats=2, paint_product_id=midrange_id)
    update_surface_tier_overrides_ai_tool(surface_id=X, tier="best", coats=2, paint_product_id=premium_id)

recalculate_estimate_ai_tool
```

4 areas × 4 surfaces × 3 tiers = 48 calls. This is correct and necessary.

## Default Tier Values

| Tier | Coats | Paint Quality |
|---|---|---|
| Good | 1 | Budget (quality_tier=good) |
| Better | 2 | Mid-range (quality_tier=better) |
| Best | 2 | Premium (quality_tier=best) |

User's explicit values always override these defaults.

## Tool Reference

| Tool | Purpose | When |
|---|---|---|
| `set_tier_pricing_ai_tool` | Set paint products per tier (estimate-level) | Step 1 — call once per tier (3 calls) |
| `update_surface_tier_overrides_ai_tool` | Create tier snapshot on one surface for one tier | Step 2 — call per surface × per tier |
| `update_surface_ai_tool` | Set BASE values (All Tiers tab) | Non-tier changes only. Last call wins for all tiers. |
| `set_area_package_tiers_ai_tool` | Restrict area to specific tiers | Tier-exclusive areas (e.g., crown molding only in Best) |

`update_surface_ai_tool` sets base values shared by ALL tiers. `update_surface_tier_overrides_ai_tool` sets ONE tier's snapshot. Use the latter for per-tier differentiation.

## Full Example: "Add a living room with good better best"

```
1.  search_templates_ai_tool("living room")
2.  create_area_from_template_ai_tool(template_id=X, size="medium")
    → Surfaces: Walls(10), Ceiling(11), Baseboards(12), Doors(13)
3.  list_paint_products_ai_tool(quality_tier="good")    → ProMar 200 (id=9)
4.  list_paint_products_ai_tool(quality_tier="better")  → SuperPaint (id=3)
5.  list_paint_products_ai_tool(quality_tier="best")    → Duration (id=45)
6.  set_tier_pricing_ai_tool(tier="good", paint_product_id=9)
7.  set_tier_pricing_ai_tool(tier="better", paint_product_id=3)
8.  set_tier_pricing_ai_tool(tier="best", paint_product_id=45)
9.  get_estimate_state_ai_tool → confirm surface IDs
10. For each surface (10,11,12,13):
      update_surface_tier_overrides_ai_tool(surface_id=X, tier="good", coats=1, paint_product_id=9)
      update_surface_tier_overrides_ai_tool(surface_id=X, tier="better", coats=2, paint_product_id=3)
      update_surface_tier_overrides_ai_tool(surface_id=X, tier="best", coats=2, paint_product_id=45)
11. recalculate_estimate_ai_tool
```

## Adding Areas to an Existing Tiered Estimate

Estimate-level tier paints already exist. For new areas, only do:
1. Create the area
2. `get_estimate_state_ai_tool` for new surface IDs
3. `update_surface_tier_overrides_ai_tool` for each new surface × each tier
4. `recalculate_estimate_ai_tool`

## Tier-Exclusive Areas

Use `set_area_package_tiers_ai_tool(area_id=X, tiers=["best"])` to include an area in only specific tiers. Tier-exclusive areas still need tier snapshots for their included tiers.
