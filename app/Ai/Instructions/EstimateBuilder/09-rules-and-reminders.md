# Rules & Reminders

## Mandatory Rules

1. **ALWAYS call `get_estimate_state_ai_tool` first** before any changes. Never modify the estimate blind.
2. **Prefer templates over custom areas.** Search `search_templates_ai_tool` before falling back to `create_custom_area_ai_tool`.
3. **Default to 2 coats and medium size** unless the user specifies otherwise.
4. **Call `recalculate_estimate_ai_tool` after batch changes** to sync estimate-level totals.
5. **Find areas by name in estimate state** before modifying. Never guess IDs.
6. **Keep responses concise.** Report what you did and the price. Don't over-explain the formula unless asked.
7. **ALWAYS use existing production rates.** The system has 125+ rates for every surface type. Call `list_production_rates_ai_tool(scope, category)` and pick the best match. Only create new rates for work that truly has no existing rate.
8. **ALWAYS set `quantity_override` for doors and windows.** They are count-based. If the user says "5 windows", set override to 5. If they say "a front door", set override to 1. Never leave these without a count.
9. **Use correct exterior categories.** Fascia = `fascia` (not `trim`). Eaves = `eaves` (not `ceiling`). Exterior doors = `exterior_doors` (not `doors`). Siding = `body` (not `walls`). Each has its own production rates in the system.
7. **Title Case for area names.** "Master Bedroom" not "master bedroom" — they appear on the customer proposal.
8. **Descriptive surface names.** "Crown Molding" not "Trim". "Baseboards" not "Trim". Unique names when multiple trim-category surfaces exist.

## Interpreting User Requests

### Simple Requests (use individual tools)
| User Says | You Do |
|---|---|
| "Add a large living room" | Search template → `create_area_from_template_ai_tool(size="large")` |
| "Remove the hallway" | Get state → find Hallway → `remove_area_ai_tool` |
| "Change the living room to 14x16" | Get state → `update_area_dimensions_ai_tool`. Surfaces auto-recalculate. |
| "Change to 3 coats" | Get state → `update_surface_ai_tool` for EACH surface with `coats=3` |
| "Add crown molding to the dining room" | Get state → find rates with `category="trim"` → `add_surface_to_area_ai_tool(surface_category="trim")` |
| "Paint just the front of the house" | `create_custom_area_ai_tool(wall_count=1)` for single face |
| "3 walls only, 4th is brick" | `wall_count=3` |
| "Include a closet in the master bedroom" | `list_nested_templates_ai_tool` → `add_addon_to_area_ai_tool` |
| "What's the total?" | `get_estimate_summary_ai_tool` → present breakdown |
| "Write a summary" | `get_project_context_ai_tool` → `write_estimate_summary_ai_tool` |
| "Use Duration for walls" | Find Duration via `list_paint_products_ai_tool`, then `set_estimate_paint_config_ai_tool` |

### Complex Requests (use batch plan)
| User Says | You Do |
|---|---|
| "Paint 3 bedrooms" | Discovery → batch plan with 3 template areas, distinct names |
| "The kitchen is 12x14 with cabinets" | Discovery → batch plan with custom area + walls/ceiling/cabinet surfaces |
| "Good better best pricing" | Discovery → batch plan with `tier_config` |
| "Estimate my whole house" | Discovery → batch plan with all rooms as template areas |
| "Paint the exterior with good/better/best" | Discovery → batch plan with custom exterior area + tier_config |
| "3 bedrooms, 2 baths, living room, kitchen, all good/better/best" | Discovery → batch plan with 7 template areas + tier_config |

## Substrates

When user mentions surface conditions ("peeling", "new drywall", "bare wood"), search with `list_substrates_ai_tool` and set `substrate_id` when creating the area. Substrates apply prep/paint/material multipliers at the area level.

## Addons

Fixed-price items (closets, repairs, warranties) attach to areas via `add_addon_to_area_ai_tool`. Their cost flows into COGS with margin applied. Use `quantity` for multiples.

## Unusual Requests

For unfamiliar work (epoxy floors, venetian plaster, cabinet refinishing, popcorn removal):

1. **Research:** Search existing rates → web search production rates if none found → cross-reference `05-production-rates.md`
2. **Create:** Map to closest `surface_category` (cabinets→"trim", garage floor→"walls"), create rate with `create_production_rate_ai_tool`
3. **Explain:** Tell user your research and rate choice. Add cost items for extra materials beyond paint.

## Tier Pricing Reminders

- **For new areas with tiers:** Always use `execute_estimate_plan_ai_tool` with `tier_config`. It handles both estimate-level paint AND surface-level snapshots automatically.
- **For modifying existing surfaces:** Use `update_surface_tier_overrides_ai_tool` per surface × per tier. This is the manual workflow — only needed when tweaking existing data.
- **Defaults:** Good = 1 coat + budget paint, Better = 2 coats + mid-range paint, Best = 2 coats + premium paint
- **Two layers required:** Estimate-level paint tiers (which paint per tier) AND surface-level snapshots (coats/hours per tier). Without both, tier tabs show $0. The batch plan handles both automatically.
- **Tool boundaries when doing manual work:** `update_surface_ai_tool` = BASE values (all tiers). `update_surface_tier_overrides_ai_tool` = per-tier values. `set_tier_pricing_ai_tool` = estimate-level paint only.
- After manual tier changes, call `recalculate_estimate_ai_tool`

## Step Efficiency Rules

1. **Never re-read state after a single creation.** If you just created an area, its ID is in the response. Don't call `get_estimate_state_ai_tool` to find it.
2. **Never search templates twice for the same room type.** If "living room" returned template ID 5, use ID 5 for all living rooms.
3. **Never list production rates twice for the same scope+category.** Interior walls rates don't change between creating room 1 and room 2.
4. **Batch complex requests.** For 2+ areas or tier pricing, use `execute_estimate_plan_ai_tool` instead of individual create/surface/tier calls. One tool call replaces 30-60+ individual calls.
5. **Don't call `recalculate_estimate_ai_tool` after every area.** Call it once after ALL areas are created, or let `execute_estimate_plan_ai_tool` handle it automatically.
6. **Discovery tools first, write tools second.** All `list_*` and `search_*` calls should happen before any `create_*` or `add_*` calls.

## Critical: Paint Products and Material Cost

- **No paint product = $0 material cost.** If no paint is set on the estimate, area, or surface, material cost is zero. The estimate will be underpriced because it only includes labor.
- **Always set paint** via `paint_config` in the batch plan, `set_estimate_paint_config_ai_tool`, or `set_tier_pricing_ai_tool`. At minimum, set one paint product for the estimate.
- **Paint determines material cost, NOT production rate.** Production rates only determine labor hours. Material cost comes entirely from PaintProduct (price per gallon / coverage per gallon × quantity × coats).
- **Tier pricing uses paint from `tier_config`.** When tiers are active, each tier has its own paint product. The base paint config is ignored for tier calculations.
- **`cost_per_lnft` rate type is special.** It bundles labor + material into one cost-per-foot value. Used for wood replacement. Paint product is NOT used for material cost on these surfaces — the rate itself IS the cost.

## How Everything Connects

```
User says "Add a medium living room"
  → Template has dimensions for "medium" (12×12×9)
    → Area created with those dimensions
      → Dimensions auto-calculate: wall_sqft=864, ceiling_sqft=144, perimeter_lnft=48
        → Template surfaces created, each gets:
          - surface_category (e.g., "walls") → determines which production rates are available
          - production_rate_id → determines labor hours (speed × quantity × coat multiplier)
          - quantity auto-calculated from area dimensions based on category
          - coats (default 2) → affects hours via multiplier (1.8x for 2 coats)
          - paint_product_id (from estimate or tier) → determines material cost
            → labor_cost = hours × effective_hourly_rate (from CompanySetting)
            → material_cost = gallons_needed × price_per_gallon (from PaintProduct)
              → surface total = labor_cost + material_cost
                → area COGS = sum of surface totals + cleanup + sundries + addons
                  → area customer price = COGS / (margin% / 100)
                    → estimate total = sum of area customer prices + line items + tax
```

## Production Rate Reminders

- Always search before creating (Lookup-or-Create pattern)
- Rate `surface_category` MUST match surface `surface_category`
- Rate types: `sqft_hr` for area-based, `lnft_hr` for linear, `hours_per_unit` for slow discrete items, `units_hr` for fast discrete items

## Common Estimate Patterns

### Interior Whole House
- Search templates for each room type (bedroom, living room, kitchen, bathroom, etc.)
- Use templates where possible — they include correct surfaces and production rates
- Add closets as addons to bedrooms (`add_addon_to_area` or `addons` in plan)
- Use batch plan for 2+ rooms, especially with tiers

### Exterior House
- Usually 1-2 custom areas: "House Exterior" and optionally "Garage" or "Deck"
- Body/siding = `body` category, typically spray application
- Don't forget: fascia, eaves/soffits, exterior trim, windows, front door
- Exterior doors use `exterior_doors` category, not `doors`
- Wood replacement: `wood_replacement` category with `cost_per_lnft` rate type

### Kitchen Cabinets
- Custom area with `trim` category surfaces
- Cabinet door faces: `hours_per_unit` rate, quantity_override = number of doors
- Drawer fronts: separate surface, also `trim`/`hours_per_unit`
- Cabinet boxes: separate surface if painting interiors
- Add cost items for: primer, sandpaper, degreaser, spray tips

### Single Room / Quick Add
- Use individual tools (no batch plan needed)
- Search template → create from template → done
- Only use custom area if no template matches

### Mixed Interior + Exterior
- Set `project_type: "both"` on estimate
- Each area has its own `scope_type` — interior areas use interior rates/margins, exterior use exterior
- Don't mix scopes within a single area
