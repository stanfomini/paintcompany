# Tool Reference

## How Estimates Work — Step by Step

1. Call `get_estimate_state_ai_tool` to see what already exists
2. Search for a template with `search_templates_ai_tool`
3. Create areas via `create_area_from_template_ai_tool` (preferred) or `create_custom_area_ai_tool` (manual)
4. Set paint products via `set_estimate_paint_config_ai_tool` (global) or `set_area_paint_product_ai_tool` (per-area)
5. Configure tiers via `set_tier_pricing_ai_tool` + `update_surface_tier_overrides_ai_tool` (see `06-tier-pricing.md`)
6. Add addons (closets, repairs) via `add_addon_to_area_ai_tool`
7. Recalculate with `recalculate_estimate_ai_tool` to finalize totals

## Modifying Existing Areas

1. `get_estimate_state_ai_tool` — find the area ID and surface IDs by name
2. Change dimensions: `update_area_dimensions_ai_tool` (auto-recalculates all surface quantities)
3. Change coats/paint on a surface: `update_surface_ai_tool` with surface_id
4. Add a missing surface: `add_surface_to_area_ai_tool` with area_id
5. Remove a surface: `remove_surface_ai_tool` with surface_id
6. Delete the entire room: `remove_area_ai_tool`

---

## Paint Product Assignment

Paint products are configured at the **ESTIMATE level** via `set_estimate_paint_config_ai_tool` or **per-tier** via `set_tier_pricing_ai_tool`. Do NOT set `paint_product_id` on individual surfaces unless the user specifically asks for a different paint on one surface.

---

## Discovery Tools (read-only)

- `search_templates_ai_tool` — Find room templates by name. Use BEFORE creating areas. Always search first.
- `list_production_rates_ai_tool` — List rates by scope + category. Use BEFORE adding surfaces. Filter by the same `surface_category` you plan to use.
- `list_paint_products_ai_tool` — List paints by scope, sheen, quality_tier. Use `quality_tier` filter for tier setup.
- `list_substrates_ai_tool` — List surface conditions with prep/paint/material multipliers. Use when user mentions surface condition.
- `list_nested_templates_ai_tool` — List fixed-price addons (closets, half baths, repairs, warranties). Search before using `add_addon_to_area_ai_tool`.
- `get_pricing_config_ai_tool` — Get company pricing settings (wages, margins, cleanup, sundries) by scope. Only needed when user asks about pricing config.
- `get_estimate_state_ai_tool` — **ALWAYS call first.** Returns all areas, surface IDs, pricing, tier config, paint config. Re-call after creating areas to get new IDs.
- `get_estimate_summary_ai_tool` — Formatted pricing summary with per-area breakdown. Optional `tier` filter for tier-specific view.
- `get_project_context_ai_tool` — Customer name, property details, deal info, photos. Use for writing proposal summaries.
- `list_cost_items_ai_tool` — List cost items on an area or surface. Requires `target_type` ("area"/"surface") and `target_id`.

## Creation Tools (permanent, reusable catalog items)

These create resources reusable across all estimates. They do NOT modify the current estimate directly.

- `create_production_rate_ai_tool` — Create a new production rate. Always search first (Lookup-or-Create pattern). NOTE: `surface_category` MUST match the surface you plan to use it on.
- `create_template_ai_tool` — Create a reusable room template with sizes and surfaces. Include `sizes` JSON for S/M/L and `surfaces` with `production_rate_id` values.
- `create_nested_template_ai_tool` — Create a fixed-price addon template (closet, prep item, warranty). Search `list_nested_templates_ai_tool` first.

## Area Building Tools

- `create_area_from_template_ai_tool` — **Preferred.** Create area from template with pre-configured surfaces. Pass `template_id`, optional `size` (default "medium"), `coats` (default 2), `substrate_id`.
- `create_custom_area_ai_tool` — Create area with manual dimensions. Returns area with NO surfaces — you MUST add surfaces after. Use Title Case names. Set `wall_count` for partial rooms (3 = open plan, 1 = accent wall/single face).

## Surface Tools

- `add_surface_to_area_ai_tool` — Add a surface to an area. Requires `area_id`, `surface_category`, `production_rate_id` (category must match). Use `quantity_override` for doors/windows (count-based). Invalid categories: "crown", "molding", "siding", "cabinets" — use "trim", "body" etc.
- `update_surface_ai_tool` — Update BASE rate, coats, paint, or quantity. Changes apply to ALL tiers. Do NOT use for tier-specific changes.
- `update_surface_tier_overrides_ai_tool` — Set per-surface per-tier overrides (coats, rate, paint, labor, materials). **REQUIRED** for tier pricing — call once per surface per tier. Without these, tier tabs are empty.
- `remove_surface_ai_tool` — Delete a surface by `surface_id`.
- `add_addon_to_area_ai_tool` — Attach a fixed-price addon (closet, repair) to an area. Pass `area_id`, `nested_template_id`, optional `quantity`.

## Area Management Tools

- `remove_area_ai_tool` — Delete an entire area and all its surfaces/addons/cost items.
- `update_area_dimensions_ai_tool` — Change area dimensions. Auto-recalculates all surface quantities — no need to update surfaces individually.
- `preview_area_pricing_ai_tool` — Get detailed cost breakdown for one area (per-surface hours, labor, materials, COGS, OTD).
- `set_area_package_tiers_ai_tool` — Control which tiers include this area. Pass `tiers` array (e.g., `["better", "best"]`). Null = all tiers.

## Line Items & Cost Items

- `create_estimate_item_ai_tool` — Standalone line item (equipment rental, subcontractor, permit). Direct customer charge, NO automatic margin. Pass `description`, `quantity`, `unit_price`.
- `add_cost_item_ai_tool` — Add cost to an area or surface (material, rental, equipment). Flows into COGS — margin IS applied. Pass `target_type`, `target_id`, `name`, `unit_cost`. Optional `tier` filter.
- `remove_cost_item_ai_tool` — Remove a cost item by `cost_item_id`.

**Cost items vs line items:** Cost items (COGS, margin applied) for materials/rentals tied to painting work. Line items (no margin) for standalone charges (permits, subcontractors, dumpster).

## Paint & Pricing Tools

- `set_estimate_paint_config_ai_tool` — Set paint globally. Mode "single" (one paint) or "category" (walls/ceiling/trim/doors get different paints).
- `set_area_paint_product_ai_tool` — Override paint for a specific area. Same category options as estimate-level.
- `set_tier_pricing_ai_tool` — Set paint products per tier (Step 1 of tier setup). Sets paint ONLY — does NOT change coats/labor. MUST follow with `update_surface_tier_overrides_ai_tool` (Step 2).
- `update_estimate_settings_ai_tool` — Update title, project_type, tax_rate, deposit_percent, margin_discount_percent, notes.
- `recalculate_estimate_ai_tool` — Force full recalculation of all totals. Call after batch changes, tier setup, paint changes.

## AI Memory & Output

- `save_ai_context_ai_tool` — Save persistent notes to this estimate (customer preferences, budget, decisions). Append to existing notes, don't overwrite.
- `write_estimate_summary_ai_tool` — Write customer-facing proposal summary in markdown. Professional tone, no internal jargon (no COGS, margins, rates). Include scope overview.
