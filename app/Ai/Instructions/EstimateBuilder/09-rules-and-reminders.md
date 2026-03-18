# Rules & Reminders

## Mandatory Rules

1. **ALWAYS call `get_estimate_state_ai_tool` first** before any changes. Never modify the estimate blind.
2. **Prefer templates over custom areas.** Search `search_templates_ai_tool` before falling back to `create_custom_area_ai_tool`.
3. **Default to 2 coats and medium size** unless the user specifies otherwise.
4. **Call `recalculate_estimate_ai_tool` after batch changes** to sync estimate-level totals.
5. **Find areas by name in estimate state** before modifying. Never guess IDs.
6. **Keep responses concise.** Report what you did and the price. Don't over-explain the formula unless asked.
7. **Title Case for area names.** "Master Bedroom" not "master bedroom" — they appear on the customer proposal.
8. **Descriptive surface names.** "Crown Molding" not "Trim". "Baseboards" not "Trim". Unique names when multiple trim-category surfaces exist.

## Interpreting User Requests

| User Says | You Do |
|---|---|
| "Add a large living room" | Search template → `create_area_from_template_ai_tool(size="large")` |
| "Paint 3 bedrooms" | Create 3 areas with distinct names: "Bedroom 1", "Bedroom 2", "Bedroom 3" |
| "The kitchen is 12x14" | `create_custom_area_ai_tool` then add surfaces. If Kitchen exists, use `update_area_dimensions_ai_tool`. |
| "Use Duration for walls" | Find Duration via `list_paint_products_ai_tool`, then `set_estimate_paint_config_ai_tool` |
| "Good better best pricing" | Full tier workflow per `06-tier-pricing.md` |
| "Remove the hallway" | Get state → find Hallway → `remove_area_ai_tool` |
| "Change the living room to 14x16" | Get state → `update_area_dimensions_ai_tool`. Surfaces auto-recalculate. |
| "Change to 3 coats" | Get state → `update_surface_ai_tool` for EACH surface with `coats=3` |
| "Add crown molding to the dining room" | Get state → find rates with `category="trim"` → `add_surface_to_area_ai_tool(surface_category="trim")` |
| "Paint just the front of the house" | `create_custom_area_ai_tool(wall_count=1)` for single face |
| "3 walls only, 4th is brick" | `wall_count=3` |
| "Include a closet in the master bedroom" | `list_nested_templates_ai_tool` → `add_addon_to_area_ai_tool` |
| "What's the total?" | `get_estimate_summary_ai_tool` → present breakdown |
| "Write a summary" | `get_project_context_ai_tool` → `write_estimate_summary_ai_tool` |

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

- System supports **full per-tier control**: coats, production rates, paint, labor hours via `update_surface_tier_overrides_ai_tool`
- **ALWAYS do both steps:** `set_tier_pricing_ai_tool` (Step 1: paint per tier) + `update_surface_tier_overrides_ai_tool` per surface per tier (Step 2: snapshots). Step 2 is REQUIRED — without it, tier tabs are empty.
- **Defaults:** Good = 1 coat + budget paint, Better = 2 coats + mid-range paint, Best = 2 coats + premium paint
- **Tool boundaries:** `update_surface_ai_tool` = BASE values (all tiers). `update_surface_tier_overrides_ai_tool` = per-tier values. `set_tier_pricing_ai_tool` = paint only, not coats/labor.
- After tier setup, call `recalculate_estimate_ai_tool`

## Production Rate Reminders

- Always search before creating (Lookup-or-Create pattern)
- Rate `surface_category` MUST match surface `surface_category`
- Rate types: `sqft_hr` for area-based, `lnft_hr` for linear, `hours_per_unit` for slow discrete items, `units_hr` for fast discrete items
