# Tier Pricing — Good/Better/Best

Tier pricing offers customers three price levels using different paint products (and optionally coats).

## Preferred: Use `execute_estimate_plan_ai_tool` for Tier Pricing

For new areas with tier pricing, **always use the batch plan**. Pass `tier_config` and the plan automatically:
1. Sets paint products per tier on the estimate
2. Creates tier snapshots for every surface × every tier
3. Recalculates everything

See `10-batch-execution.md` for examples. This replaces the manual Step 1 + Step 2 workflow below.

## Manual Workflow (for modifying existing surfaces only)

Use this when adding tiers to surfaces that already exist, or changing tier values on existing surfaces.

### Step 1: Set Paint Products Per Tier (Estimate-Level)

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

### Step 2: Create Tier Snapshots on EVERY Surface — NEVER SKIP

Call `update_surface_tier_overrides_ai_tool` for EACH surface × EACH tier. Without this, tier tabs are empty.

```
get_estimate_state_ai_tool → get all surface IDs

For EACH surface:
    update_surface_tier_overrides_ai_tool(surface_id=X, tier="good", coats=1, paint_product_id=budget_id)
    update_surface_tier_overrides_ai_tool(surface_id=X, tier="better", coats=2, paint_product_id=midrange_id)
    update_surface_tier_overrides_ai_tool(surface_id=X, tier="best", coats=2, paint_product_id=premium_id)

recalculate_estimate_ai_tool
```

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
| `set_surface_package_tiers_ai_tool` | Restrict surface to specific tiers | Tier-exclusive surfaces (e.g., ceiling only in Better/Best) |

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

## Tier-Exclusive Surfaces

Use `set_surface_package_tiers_ai_tool(surface_id=X, tiers=["best"])` to include a surface in only specific tiers. This is more granular than area-level exclusion — you can have walls in all tiers but crown molding only in Best.

**When to use:**
- "Crown molding only in the Best tier" → exclude crown from good/better
- "Ceiling painting is a Better/Best upgrade" → exclude ceiling from good
- Premium finishes (accent walls, decorative trim) only in higher tiers

**Important:** Tier-excluded surfaces still need tier overrides for their included tiers. If crown molding is only in Best, still call `update_surface_tier_overrides_ai_tool` for the "best" tier on that surface.

Example:
```
create_area_from_template_ai_tool(template_id=X, size="medium")
→ Surfaces: Walls(10), Ceiling(11), Crown Molding(12)

set_surface_package_tiers_ai_tool(surface_id=12, tiers=["best"])
→ Crown molding only appears in the Best tier pricing
```
