# Tier Pricing — Good/Better/Best

## What Tier Pricing Is

The estimate can offer customers three price levels — Good, Better, and Best. Each tier uses different paint products (and optionally different coats), which changes material cost and labor, resulting in three distinct prices for the same work. The customer sees all three options on their proposal and chooses one.

This is a core selling feature. It lets the customer pick the level of quality and investment that fits their budget. Good uses budget-tier paint with fewer coats. Best uses premium paint with full coats. The same rooms, the same surfaces, the same work — but different price points because the materials and labor hours differ.

## What Are Tier Snapshots?

Tier snapshots are the mechanism that makes Good/Better/Best work in the UI. Here's how they connect:

### The Area Builder Tabs

The area builder modal (the detail page for each room) has tabs across the top:

**[All Tiers] [Good] [Better] [Best]**

- **All Tiers tab** — Shows the base values shared across all tiers. This is what you set with `update_surface_ai_tool`.
- **Good tab** — Shows the Good tier's snapshot values for every surface in the area.
- **Better tab** — Shows the Better tier's snapshot values.
- **Best tab** — Shows the Best tier's snapshot values.

Each tab loads that tier's snapshot — a saved copy of every surface's settings including coats, production rate, paint product, labor hours, and material cost.

### Without Snapshots (tier_overrides = null) — BROKEN

When a surface has no tier snapshots (its `tier_overrides` field is null):
- All 3 tier tabs show the **same values** — the base values from the All Tiers tab
- The UI has **no tier-specific data** to display
- Clicking the Good, Better, or Best tab shows **nothing** (empty/default)
- The customer proposal shows **no price differentiation** between tiers
- The feature is **completely broken** for that surface

### With Snapshots — WORKING

When a surface has tier snapshots for all three tiers:
- Each tier tab loads **different settings** — different coats, different paint products, different labor hours, different material costs
- Good might show 1 coat of ProMar 200 at $48/gallon
- Better might show 2 coats of SuperPaint at $76/gallon
- Best might show 2 coats of Duration at $95/gallon
- The prices differ based on **both labor AND materials**
- The customer sees three distinct prices and can choose

## MANDATORY RULE: Every Area Must Have Tier Snapshots

**RULE: Every area on a tiered estimate MUST have tier snapshots for Good, Better, AND Best on EVERY surface.**

Tier snapshots are created with `update_surface_tier_overrides_ai_tool`. Without them, the area builder UI shows no tier data and the customer sees no differentiation. This is the #1 feature of the estimate builder — never skip it.

If an estimate has tier pricing enabled (paint products assigned to Good, Better, Best) but the surfaces don't have tier snapshots, the feature is broken. The estimate-level tier paint assignments (Step 1) mean nothing without surface-level tier snapshots (Step 2). Both steps are always required.

## The Two Required Steps (ALWAYS Do BOTH)

Every time you set up tier pricing, you MUST complete both steps. Step 1 without Step 2 is useless. Step 2 without Step 1 works but uses incorrect paint assignments.

### Step 1: Set Paint Products Per Tier (Estimate-Level)

This tells the system which paint products to use for each tier across the entire estimate. Call `set_tier_pricing_ai_tool` once per tier:

```
set_tier_pricing_ai_tool(tier="good", paint_product_id=budget_paint_id)
set_tier_pricing_ai_tool(tier="better", paint_product_id=midrange_paint_id)
set_tier_pricing_ai_tool(tier="best", paint_product_id=premium_paint_id)
```

To find the right paint products, search by quality tier:
```
list_paint_products_ai_tool(quality_tier="good")    → budget paints (ProMar 200, Behr Premium Plus, etc.)
list_paint_products_ai_tool(quality_tier="better")  → mid-range paints (SuperPaint, Regal Select, etc.)
list_paint_products_ai_tool(quality_tier="best")    → premium paints (Duration, Aura, Marquee, etc.)
```

You can also set different paints per surface category within a tier:
```
set_tier_pricing_ai_tool(
    tier="best",
    paint_product_id=premium_wall_paint_id,
    walls_paint_id=premium_wall_paint_id,
    ceiling_paint_id=premium_ceiling_paint_id,
    trim_paint_id=premium_trim_paint_id,
    doors_paint_id=premium_door_paint_id
)
```

### Step 2: Create Tier Snapshots on EVERY Surface (Surface-Level) — NEVER SKIP THIS

This is where the actual tier data is created. You must call `update_surface_tier_overrides_ai_tool` for EACH surface on EACH tier. This is not optional. Without this step, the tier tabs in the area builder UI are empty.

```
get_estimate_state_ai_tool → get all areas and their surface IDs

For EACH surface on EACH area:
    update_surface_tier_overrides_ai_tool(surface_id=X, tier="good", coats=1, paint_product_id=budget_id)
    update_surface_tier_overrides_ai_tool(surface_id=X, tier="better", coats=2, paint_product_id=midrange_id)
    update_surface_tier_overrides_ai_tool(surface_id=X, tier="best", coats=2, paint_product_id=premium_id)

recalculate_estimate_ai_tool
```

Yes, this means if you have 4 areas with 4 surfaces each, you make 48 calls (16 surfaces x 3 tiers). This is correct and necessary. Each call creates a snapshot for one surface at one tier level.

## Default Tier Differentiation

When the user says "set up good better best" or "add tiers" without specifying exact coats, use these defaults:

| Tier | Default Coats | Paint Quality | What This Means |
|---|---|---|---|
| **Good** | 1 coat | Budget paint (quality_tier=good) | Lowest price — fewer coats, less expensive paint. Acceptable for rental properties, quick refreshes, or budget-conscious customers. |
| **Better** | 2 coats | Mid-range paint (quality_tier=better) | Middle option — standard coats, quality paint. This is what most homeowners choose. Good coverage and durability. |
| **Best** | 2 coats | Premium paint (quality_tier=best) | Highest price — same coats as Better but with premium paint. Superior durability, better coverage, richer finish. Best for high-traffic areas or customers who want top quality. |

When the user specifies their own differentiation (e.g., "Good = 1 coat, Better = 2, Best = 3 coats"), use their values instead. The user's explicit instructions always override defaults.

## Tool Reference for Tier Pricing

| Tool | What It Does | When to Use |
|---|---|---|
| `set_tier_pricing_ai_tool` | Sets paint products per tier at the estimate level. Configures which paint is used for Good, Better, and Best across the whole estimate. | **ALWAYS** — Step 1 of tier setup. Call once per tier (3 calls total). |
| `update_surface_tier_overrides_ai_tool` | Creates a tier snapshot on a single surface for a single tier. Saves that tier's coats, production rate, paint product, labor hours, and material cost for that surface. | **ALWAYS** — Step 2 of tier setup. Call once per surface per tier (surfaces x 3 calls). |
| `update_surface_ai_tool` | Sets the BASE values for a surface — the defaults that apply when no tier-specific override exists. These are the values shown on the "All Tiers" tab. | Only for non-tier changes. Use when you need to change a surface's production rate, coats, or quantity and you want the change to apply to all tiers equally. **NEVER** use this for tier-specific settings. |
| `set_area_package_tiers_ai_tool` | Controls which tiers include a specific area. By default, all areas appear in all tiers. Use this when the user wants an area to only appear in certain tiers (e.g., "crown molding only in the Best package"). | Only when the user wants tier-exclusive areas — areas that appear in some tiers but not others. |

### Critical Distinction: `update_surface_ai_tool` vs `update_surface_tier_overrides_ai_tool`

These two tools do fundamentally different things:

- **`update_surface_ai_tool`** changes the BASE values. If you call this to set coats=2, then ALL tiers show 2 coats (unless overridden by tier snapshots). Use this for changes that should affect every tier equally.
- **`update_surface_tier_overrides_ai_tool`** changes ONE TIER's snapshot. If you call this with tier="good" and coats=1, only the Good tier shows 1 coat. Better and Best keep their own values.

If you use `update_surface_ai_tool` to try to set different coats per tier, it won't work — there's only one set of base values, so the last call wins and all tiers get the same coats. You MUST use `update_surface_tier_overrides_ai_tool` for per-tier differentiation.

## Full Example: "Add a living room with good better best"

Here is the complete step-by-step workflow. Every step is required.

### Step 1: Find and create the area

```
1. search_templates_ai_tool("living room")
   → Found "Living Room" template with S/M/L sizes

2. create_area_from_template_ai_tool(template_id=X, size="medium")
   → Created area with 4 surfaces: Walls, Ceiling, Baseboards, Doors
```

### Step 2: Find paint products for each tier

```
3. list_paint_products_ai_tool(quality_tier="good")
   → Pick budget paint, e.g., ProMar 200 (id=9)

4. list_paint_products_ai_tool(quality_tier="better")
   → Pick mid-range paint, e.g., SuperPaint (id=3)

5. list_paint_products_ai_tool(quality_tier="best")
   → Pick premium paint, e.g., Duration (id=45)
```

### Step 3: Set estimate-level tier paint assignments

```
6. set_tier_pricing_ai_tool(tier="good", paint_product_id=9)
7. set_tier_pricing_ai_tool(tier="better", paint_product_id=3)
8. set_tier_pricing_ai_tool(tier="best", paint_product_id=45)
```

### Step 4: Get all surface IDs

```
9. get_estimate_state_ai_tool
   → Area "Living Room" has surfaces:
     - Walls (id=10)
     - Ceiling (id=11)
     - Baseboards (id=12)
     - Doors (id=13)
```

### Step 5: Create tier snapshots for EVERY surface x EVERY tier

```
10. For Walls (id=10):
    update_surface_tier_overrides_ai_tool(surface_id=10, tier="good", coats=1, paint_product_id=9)
    update_surface_tier_overrides_ai_tool(surface_id=10, tier="better", coats=2, paint_product_id=3)
    update_surface_tier_overrides_ai_tool(surface_id=10, tier="best", coats=2, paint_product_id=45)

11. For Ceiling (id=11):
    update_surface_tier_overrides_ai_tool(surface_id=11, tier="good", coats=1, paint_product_id=9)
    update_surface_tier_overrides_ai_tool(surface_id=11, tier="better", coats=2, paint_product_id=3)
    update_surface_tier_overrides_ai_tool(surface_id=11, tier="best", coats=2, paint_product_id=45)

    For Baseboards (id=12):
    update_surface_tier_overrides_ai_tool(surface_id=12, tier="good", coats=1, paint_product_id=9)
    update_surface_tier_overrides_ai_tool(surface_id=12, tier="better", coats=2, paint_product_id=3)
    update_surface_tier_overrides_ai_tool(surface_id=12, tier="best", coats=2, paint_product_id=45)

    For Doors (id=13):
    update_surface_tier_overrides_ai_tool(surface_id=13, tier="good", coats=1, paint_product_id=9)
    update_surface_tier_overrides_ai_tool(surface_id=13, tier="better", coats=2, paint_product_id=3)
    update_surface_tier_overrides_ai_tool(surface_id=13, tier="best", coats=2, paint_product_id=45)
```

### Step 6: Recalculate

```
12. recalculate_estimate_ai_tool
```

**Result:** The area builder UI now shows different data on each tier tab. The customer proposal shows three distinct prices for the Living Room. The Good price is lowest (1 coat, budget paint), the Best price is highest (2 coats, premium paint), and Better falls in between.

## Adding New Areas to an Existing Tiered Estimate

When the estimate already has tier pricing set up and the user asks you to add another room, you MUST create tier snapshots on the new area's surfaces too. The estimate-level tier paint assignments from Step 1 already exist, so you only need to:

1. Create the new area (from template or custom)
2. Get the new surface IDs with `get_estimate_state_ai_tool`
3. Call `update_surface_tier_overrides_ai_tool` for each new surface x each tier
4. Call `recalculate_estimate_ai_tool`

Do NOT skip the tier snapshots just because tiers are "already set up." The estimate-level settings don't automatically propagate to new surfaces. Each surface needs its own tier snapshots.

## Tier-Exclusive Areas

Sometimes the user wants an area to only appear in certain tiers. For example: "Add crown molding to the living room, but only in the Best package."

Use `set_area_package_tiers_ai_tool` for this:

```
set_area_package_tiers_ai_tool(area_id=X, tiers=["best"])
```

This means the crown molding area only appears in the Best tier's pricing. Good and Better don't include it, so their prices are lower. The customer sees crown molding as a value-add in the Best option.

You can also use this for "upgrade" areas:
```
set_area_package_tiers_ai_tool(area_id=X, tiers=["better", "best"])
```
This area appears in Better and Best but not Good.

**Note:** Even tier-exclusive areas need tier snapshots on their surfaces for the tiers they're included in. If an area is only in "best", it still needs `update_surface_tier_overrides_ai_tool` called for the "best" tier on every surface.

## Common Mistakes to Avoid

1. **Doing Step 1 without Step 2.** Setting paint products at the estimate level (set_tier_pricing) but never creating surface snapshots (update_surface_tier_overrides). Result: tier tabs are empty, feature is broken.

2. **Using `update_surface_ai_tool` for tier-specific changes.** This sets base values, not tier values. All tiers will show the same thing. Use `update_surface_tier_overrides_ai_tool` instead.

3. **Forgetting to create snapshots for new areas.** When adding a room to an estimate that already has tiers, the new surfaces need their own tier snapshots.

4. **Skipping `recalculate_estimate_ai_tool` after tier setup.** Always recalculate after creating or modifying tier snapshots to ensure totals are correct.

5. **Creating snapshots for only one or two tiers.** All three tiers (Good, Better, Best) need snapshots on every surface. If you only create Good and Best, the Better tab will be empty.
