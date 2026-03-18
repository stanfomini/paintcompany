# Tool Reference

You have 34 tools organized into 8 groups. This reference covers every tool: its exact name (use these names when calling tools), what it does, when to use it, all parameters, and common mistakes to avoid.

---

## How Estimates Work — Step by Step

Before diving into individual tools, understand the standard workflow for building an estimate:

1. **Always start** by calling `get_estimate_state_ai_tool` to see what already exists on the estimate
2. **Search for a template** using `search_templates_ai_tool` (e.g., "living room", "bedroom")
3. **Create areas** using `create_area_from_template_ai_tool` (preferred) or `create_custom_area_ai_tool` (manual)
4. **Set paint products** using `set_estimate_paint_config_ai_tool` (global) or `set_area_paint_product_ai_tool` (per-area)
5. **Configure tiers** using `set_tier_pricing_ai_tool` + `update_surface_tier_overrides_ai_tool` (see Tier Pricing in `06-tier-pricing.md`)
6. **Add addons** like closets or repairs using `add_addon_to_area_ai_tool`
7. **Recalculate** with `recalculate_estimate_ai_tool` to finalize all totals

## Modifying Existing Areas

When the user wants to change a room that already exists on the estimate:

1. Call `get_estimate_state_ai_tool` to find the area ID and surface IDs by name
2. To change dimensions: use `update_area_dimensions_ai_tool` (auto-recalculates all surface quantities)
3. To change coats or paint on a surface: use `update_surface_ai_tool` with the surface_id
4. To add a missing surface: use `add_surface_to_area_ai_tool` with the area_id
5. To remove a surface: use `remove_surface_ai_tool` with the surface_id
6. To delete the entire room: use `remove_area_ai_tool`

---

## Group 1: Discovery Tools (Read-Only)

These tools read data without modifying anything. Use them to look up IDs, check what exists, and find resources before creating or modifying.

### `search_templates_ai_tool`

**Purpose:** Find room/area templates by name or keywords.

**When to use:** BEFORE creating areas. Always search for a template first — if one exists, use `create_area_from_template_ai_tool` instead of building a custom area from scratch. Templates come pre-configured with the correct surfaces, production rates, and size options, which saves time and produces more accurate estimates.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `query` | Yes | string | Search query for template name or keywords. Examples: "living room", "bedroom", "exterior", "kitchen", "deck" |
| `scope_type` | No | string | Filter by scope: "interior" or "exterior" |

**Returns:** Matching templates with available sizes (S/M/L dimensions), surface configurations, and production rate IDs.

**Common mistakes:**
- Skipping the search and jumping straight to `create_custom_area_ai_tool`. Always search first.
- Using overly specific queries. Search "bedroom" not "medium sized master bedroom with vaulted ceiling".

---

### `list_production_rates_ai_tool`

**Purpose:** List painting production rates filtered by scope and surface category.

**When to use:** BEFORE adding surfaces with `add_surface_to_area_ai_tool`. You need a `production_rate_id` to add a surface, and this tool is how you find it. Filter by the same `surface_category` you plan to use on the surface — the categories must match.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `scope_type` | No | string | Filter by scope: "interior" or "exterior" |
| `category` | No | string | Filter by surface category: "walls", "ceiling", "baseboard", "trim", "doors", "windows", "body", "eaves", "fascia", "exterior_doors", "prep", "wood_replacement" |

**Returns:** Production rates with IDs, names, rate values (e.g., 200 sqft/hr), rate types, and unit types.

**Common mistakes:**
- Filtering by the wrong category. If you plan to add a surface with `surface_category="baseboard"`, filter rates by `category="baseboard"`, NOT `category="trim"`. The surface category and the rate's category must match.
- Not filtering at all. Without a category filter, you get ALL rates and might pick the wrong one.

---

### `list_paint_products_ai_tool`

**Purpose:** List available paint products with pricing and coverage information.

**When to use:** When setting paint products on the estimate, areas, tiers, or individual surfaces. Use the `quality_tier` filter during tier pricing setup to find appropriate paints for each tier level.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `scope_type` | No | string | Filter by scope: "interior", "exterior", or "both" |
| `sheen` | No | string | Filter by sheen: "flat", "matte", "eggshell", "satin", "semi-gloss", "gloss" |
| `quality_tier` | No | string | Filter by quality tier: "good", "better", "best" |

**Returns:** Paint products with IDs, names, brand names, price per gallon, coverage rates, sheens, and quality tiers.

**Common mistakes:**
- Not using the `quality_tier` filter during tier pricing setup. Call once per tier level to find the right paint for each.

---

### `list_substrates_ai_tool`

**Purpose:** List surface conditions (substrates) that modify labor time through prep, paint, and material multipliers.

**When to use:** When the user mentions the condition of the surfaces being painted — peeling paint, new drywall, bare wood, previously painted, etc. The substrate_id is set at the area level when creating an area.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `scope_type` | No | string | Filter by scope: "interior", "exterior", or "both" |

**Returns:** Substrates with IDs, names, prep_time_multiplier, paint_time_multiplier, material_multiplier.

---

### `list_nested_templates_ai_tool`

**Purpose:** List fixed-price add-on items like closets, half baths, accent walls, repairs, wallpaper removal, and warranties.

**When to use:** When the user mentions items that are not full rooms but are add-ons to a room. Examples: "the master bedroom has a walk-in closet", "include wallpaper removal in the dining room", "add a warranty". These are fixed-price items that get attached to an area.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `scope_type` | No | string | Filter by scope: "interior", "exterior", or "both" |
| `category` | No | string | Filter by category: "closet", "bathroom", "utility", "repair", "warranty", "other" |

**Returns:** Nested templates with IDs, names, fixed prices, estimated hours, descriptions.

---

### `get_pricing_config_ai_tool`

**Purpose:** Get the company's pricing configuration — hourly wages, effective rates (wage x burden), margins, cleanup ratios, and sundries rates for both interior and exterior scopes.

**When to use:** When you need to understand or explain the pricing formula to the user, or when you need to verify that your mental model of the pricing matches the actual company settings. Most of the time, you do NOT need to call this — the pricing is applied automatically by the system when you create areas and surfaces. But call it if the user asks "what's our margin?" or "what hourly rate are we using?"

**Parameters:** None.

**Returns:** Company pricing settings organized by scope (interior/exterior), including base wages, payroll burden multiplier, effective hourly rates, margin percentages, cleanup ratios, and sundries costs per hour.

---

### `get_estimate_state_ai_tool`

**Purpose:** Get the complete current state of this estimate — all areas with their surface IDs, pricing, paint configuration, tier configuration, and totals.

**When to use:** **ALWAYS call this first** before making any changes to the estimate. This is the single most important tool call you will make. It tells you:
- What areas already exist (so you don't create duplicates)
- The area IDs and surface IDs you need for modification tools
- Current pricing and totals
- Whether tier pricing is active
- Paint configuration

Without this information, you cannot reliably modify existing areas or know which IDs to pass to other tools.

**Parameters:** None.

**Returns:** Full estimate state including all areas, surfaces with IDs, dimensions, production rates, coats, labor hours, material costs, pricing, tier overrides, paint configuration, and grand totals.

**Common mistakes:**
- Skipping this call and trying to modify areas without knowing their IDs. You will fail or modify the wrong things.
- Calling it once at the start but not re-calling after making changes. If you need updated IDs (e.g., after creating a new area), call it again.

---

### `get_estimate_summary_ai_tool`

**Purpose:** Get a formatted, human-readable pricing summary with per-area breakdown and grand totals.

**When to use:** When the user asks "what's the total?", "show me the breakdown", "how much is the estimate?", or any variation of wanting to see the pricing summary. Optionally filter by tier to show a specific tier's pricing.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `tier` | No | string | Optional tier filter: "good", "better", or "best". Omit for default pricing. |

**Returns:** Formatted summary with per-area prices, surface breakdowns, labor hours, and grand totals.

---

### `get_project_context_ai_tool`

**Purpose:** Get project context — customer name and contact info, property details (address, square footage, year built), deal information (stage, listing data), and estimate photos.

**When to use:** When you need customer or property details for writing summaries, making informed decisions about the estimate, or when the user references "the property" or "the customer" without giving specifics. Also useful when writing the estimate summary for the customer proposal — you can personalize it with the customer's name and property address.

**Parameters:** None.

**Returns:** Customer details, property information, deal data, and photo URLs.

---

### `list_cost_items_ai_tool`

**Purpose:** List cost items (materials, rentals, equipment, services) attached to an area or surface.

**When to use:** When you need to see what cost items already exist on an area or surface before adding, modifying, or removing them. Also useful when the user asks "what materials are on the kitchen?" or "show me the cost breakdown."

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `target_type` | Yes | string | Target type: "area" or "surface" |
| `target_id` | Yes | integer | The area ID or surface ID to list cost items for |

**Returns:** List of cost items with IDs, names, cost types, quantities, units, unit costs, unit prices, and totals.

---

## Group 2: Creation Tools (Permanent, Reusable)

These tools create resources that persist across estimates and are reusable by future estimates. They do NOT modify the current estimate directly — they create catalog items that you then reference when building areas and surfaces.

### `create_production_rate_ai_tool`

**Purpose:** Create a new production rate for a surface type that does not already have one in the system.

**When to use:** When you search `list_production_rates_ai_tool` and find no matching rate for the work being estimated. Always follow the Lookup-or-Create Pattern: search first, create only when no match exists. The rate you create is permanent and reusable across all future estimates.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `name` | Yes | string | Descriptive rate name. Examples: "Sunroom Walls - Brush/Roll", "Cabinet Door Faces - Spray", "Crown Molding same color" |
| `scope` | Yes | string | Scope: "interior" or "exterior" |
| `surface_category` | Yes | string | The surface category this rate applies to. Must match the category you will use on the surface. Values: "walls", "ceiling", "baseboard", "trim", "doors", "windows", "body", "eaves", "fascia", "exterior_doors", "prep", "wood_replacement", "other" |
| `production_rate` | Yes | number | The rate value. Examples: 200 for 200 sqft/hr, 40 for 40 lnft/hr, 1 for 1 hr/unit |
| `rate_type` | No | string | How to interpret the rate value. Default: "sqft_hr". Options: "sqft_hr", "lnft_hr", "units_hr", "hours_per_unit", "cost_per_lnft" |
| `unit_type` | No | string | Unit of measurement. Default: "sqft". Options: "sqft", "lnft", "unit", "hour" |
| `default_coats` | No | integer | Default coat count. Default: 2 |
| `description` | No | string | Description of when to use this rate. Helpful for future reference. |

**Common mistakes:**
- Setting `surface_category` that does not match the surface you plan to use it on. If you create a rate with `surface_category="trim"` but then try to add it to a surface with `surface_category="baseboard"`, it will not appear in the rate dropdown.
- Not explaining to the user why you chose the rate value. Always narrate: "I researched cabinet painting rates and industry standard is 0.5-1 hr per face. Using 0.67 hrs/face for standard brush work."

---

### `create_template_ai_tool`

**Purpose:** Create a new room/area template that can be reused across estimates.

**When to use:** After creating production rates for something new, consider creating a template so the configuration is reusable. For example, after building a "Staircase" area with railing, spindle, and tread surfaces, create a template so the next staircase estimate is one click.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `name` | Yes | string | Template name. Examples: "Sunroom", "Staircase", "Kitchen Cabinet Refinishing" |
| `scope_type` | Yes | string | Scope: "interior" or "exterior" |
| `category` | No | string | Template category. Default: "room". Options: "room" (with S/M/L sizes), "surface" (single surface type), "item" (fixed/per_sqft pricing), "addon" (fixed price) |
| `pricing_method` | No | string | How pricing works. Default: "production_rate". Options: "production_rate", "fixed", "per_sqft" |
| `sizes` | No | string | JSON object with S/M/L dimensions. Example: `{"small": {"length": 10, "width": 10, "height": 8}, "medium": {"length": 14, "width": 12, "height": 8}, "large": {"length": 18, "width": 14, "height": 9}}` |
| `surfaces` | No | string | JSON array of surfaces. Example: `[{"name": "Walls", "surface_type": "walls", "production_rate_id": 1}, {"name": "Ceiling", "surface_type": "ceiling", "production_rate_id": 3}]` |
| `description` | No | string | Template description |
| `base_price` | No | number | Fixed base price (for "fixed" pricing method) |
| `per_sqft_rate` | No | number | Per-sqft rate (for "per_sqft" pricing method) |

**Common mistakes:**
- Forgetting to include `sizes` for "room" category templates. Without S/M/L sizes, the template cannot be used with size selection.
- Not including `surfaces` with correct `production_rate_id` values. Look up rate IDs first, then reference them in the surfaces array.

---

### `create_nested_template_ai_tool`

**Purpose:** Create a nested area template (closet, half bath, prep work item, warranty) with optional real surfaces for accurate labor/cost calculation. The template is permanent and reusable as an add-on with `add_addon_to_area_ai_tool`.

**When to use:** When a fixed-price add-on item does not exist in the system. Search `list_nested_templates_ai_tool` first. Create only when no match exists.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `name` | Yes | string | Template name. Examples: "Large Walk-in Closet", "Wallpaper Removal", "2-Year Warranty" |
| `scope_type` | No | string | Scope. Default: "interior". Options: "interior", "exterior", "both" |
| `category` | No | string | Category. Default: "other". Options: "closet", "bathroom", "utility", "other" |
| `fixed_price` | No | number | Fixed price (used as fallback if no surfaces defined) |
| `estimated_hours` | No | number | Estimated labor hours |
| `material_cost` | No | number | Estimated material cost |
| `description` | No | string | Description of what this template covers |
| `surfaces` | No | string | JSON array of surfaces with production rate IDs. Example: `[{"name": "Walls", "surface_type": "walls", "production_rate_id": 1, "calculation_method": "ratio", "calculation_value": 1.0}]` |
| `includes_walls` | No | boolean | Whether this template includes wall painting |
| `includes_ceiling` | No | boolean | Whether this template includes ceiling painting |
| `includes_trim` | No | boolean | Whether this template includes trim painting |
| `includes_door` | No | boolean | Whether this template includes door painting |

---

## Group 3: Area Building Tools

These tools create rooms/areas on the estimate.

### `create_area_from_template_ai_tool`

**Purpose:** Create a new area on the estimate from an existing template. This is the **preferred** method for creating areas.

**When to use:** When a matching template exists (found via `search_templates_ai_tool`). Templates come pre-configured with the correct surfaces, production rates, quantity sources, and size options. Using a template produces a complete, correctly-built area in a single tool call — no need to manually add surfaces.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `template_id` | Yes | integer | Template ID from `search_templates_ai_tool` results |
| `size` | No | string | Size option. Default: "medium". Options: "small", "medium", "large" |
| `coats` | No | integer | Number of coats for all surfaces. Default: 2 |
| `substrate_id` | No | integer | Optional substrate ID for the area's surface condition |

**Returns:** The created area with all surfaces, calculated dimensions, labor hours, and pricing.

**Common mistakes:**
- Not searching for templates first. Always call `search_templates_ai_tool` before falling back to `create_custom_area_ai_tool`.
- Forgetting to specify `size` when the user says "large" or "small". Default is "medium" which is correct when the user doesn't specify.

---

### `create_custom_area_ai_tool`

**Purpose:** Create a new area with manual dimensions. Returns the area with calculated wall_sqft, ceiling_sqft, and perimeter_lnft but **NO surfaces** — you must add surfaces one by one with `add_surface_to_area_ai_tool`.

**When to use:** When no matching template exists, or when the user provides specific dimensions (e.g., "the kitchen is 12x14"). After creating the area, you MUST follow up by adding surfaces — an area with zero surfaces has zero price.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `name` | Yes | string | Area name in **Title Case**. Examples: "Master Bedroom", "Front Porch", "Upstairs Hallway". This appears on the customer proposal — make it professional and descriptive. |
| `scope_type` | Yes | string | Scope: "interior" or "exterior" |
| `length` | Yes | number | Room length in feet. Use 0 for single-face exterior work where only width and height matter. |
| `width` | Yes | number | Room width in feet |
| `height` | Yes | number | Wall/ceiling height in feet. Typical: 8-9 for standard rooms, 10-12 for exterior faces |
| `wall_count` | No | integer | Number of walls. Default: 4. Use 3 for open-plan rooms (kitchen open to dining), 2 for corner sections, 1 for single wall/accent wall/single exterior face. |
| `substrate_id` | No | integer | Optional substrate ID for surface condition |

**Returns:** The created area with calculated wall_sqft, ceiling_sqft, perimeter_lnft — but NO surfaces. You must add surfaces next.

**Common mistakes:**
- Forgetting to add surfaces after creating the area. An area with zero surfaces has $0 price.
- Using lowercase names like "bedroom" instead of Title Case "Bedroom" or descriptive names like "Master Bedroom".
- Not using `wall_count` appropriately. For a single accent wall, use wall_count=1. For a room open to another on one side, use wall_count=3.

---

## Group 4: Surface Tools

These tools manage individual paintable surfaces within areas.

### `add_surface_to_area_ai_tool`

**Purpose:** Add a paintable surface (walls, ceiling, trim, doors, etc.) to an area. The surface's quantity auto-calculates from the area's dimensions based on the surface category.

**When to use:** After creating a custom area with `create_custom_area_ai_tool` (which creates no surfaces), or when adding an additional surface to any existing area (e.g., "add crown molding to the living room"). Before calling this, you need:
1. The `area_id` (from `get_estimate_state_ai_tool` or from the area creation response)
2. The correct `surface_category` (see the decision table in `04-surface-building-guide.md`)
3. A `production_rate_id` (from `list_production_rates_ai_tool` filtered by the same category)

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `area_id` | Yes | integer | ID of the area to add the surface to |
| `surface_category` | Yes | string | Surface category. **CRITICAL** — determines which production rates appear and how quantity is sourced. Interior values: "walls", "ceiling", "baseboard", "trim", "doors", "windows", "prep". Exterior values: "body", "eaves", "fascia", "trim", "windows", "exterior_doors", "wood_replacement", "prep". |
| `production_rate_id` | Yes | integer | Production rate ID from `list_production_rates_ai_tool`. The rate's `surface_category` MUST match the surface's `surface_category`. |
| `coats` | No | integer | Number of coats. Default: 2 |
| `quantity_override` | No | number | Override the auto-calculated quantity. Use for doors (e.g., 3), windows (e.g., 6), or custom measurements. For walls/ceiling/baseboard, only override if the user provides a specific measurement. |

**Returns:** The created surface with calculated quantity, labor hours, material cost, and pricing.

**Common mistakes:**
- **Wrong category** — This is the #1 mistake. If you use `surface_category="walls"` but the production_rate_id is for a baseboard rate, the tool will fail or produce wrong results. The rate's category MUST match.
- **Forgetting quantity_override for doors/windows** — Doors and windows are count-based. If the user says "3 doors", set `quantity_override=3`. Without it, the default is typically 1.
- **Using invalid categories** — "crown", "molding", "siding", "cabinets" are NOT valid surface_category values. Crown molding uses category "trim". Siding uses category "body". Cabinets use category "trim".

---

### `update_surface_ai_tool`

**Purpose:** Update a surface's BASE production rate, coat count, paint product, or quantity. These are the default values used across all tiers. Changes here apply to every tier equally.

**When to use:** When modifying a surface's fundamental settings — changing from 2 coats to 3 coats, switching to a different production rate, assigning a specific paint product, or overriding the auto-calculated quantity. Use this for changes that should apply across all tiers.

**IMPORTANT:** Do NOT use this tool for tier-specific changes. If you need different coats per tier (e.g., Good=1 coat, Best=3 coats), use `update_surface_tier_overrides_ai_tool` instead.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `surface_id` | Yes | integer | ID of the surface to update. Get this from `get_estimate_state_ai_tool`. |
| `production_rate_id` | No | integer | New production rate ID |
| `coats` | No | integer | New coat count |
| `paint_product_id` | No | integer | New paint product ID |
| `quantity_override` | No | number | Override quantity (sqft, lnft, or unit count) |

**Returns:** Updated surface with recalculated labor hours, material cost, and pricing.

**Common mistakes:**
- Using this for tier-specific changes. This sets BASE values that apply to ALL tiers. For per-tier settings, use `update_surface_tier_overrides_ai_tool`.

---

### `update_surface_tier_overrides_ai_tool`

**Purpose:** Set per-surface tier overrides for Good/Better/Best pricing. Creates a "snapshot" of settings for a specific tier on a specific surface. Each tier can have different coats, production rates, paint products, labor hours, and material costs.

**When to use:** **ALWAYS** when tier pricing is being set up. After calling `set_tier_pricing_ai_tool` (Step 1 of tier setup), you MUST call this tool for EVERY surface on EVERY tier (Step 2). Without tier snapshots, the tier tabs in the area builder UI show no data, which breaks the feature.

Also use this when the user says things like "Good tier should be 1 coat, Best tier should be 3 coats" — this is the only tool that supports per-tier coat differentiation.

The tool auto-computes labor hours from coats/rate and material cost from paint product when you provide those values, so you usually only need to set coats and paint_product_id. Use explicit labor_hours or material_cost only when you need to override the auto-calculation.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `surface_id` | Yes | integer | ID of the surface to configure |
| `tier` | Yes | string | Tier name: "good", "better", or "best" |
| `production_rate_id` | No | integer | Override production rate for this tier |
| `coats` | No | integer | Override coat count for this tier (e.g., 1 for Good, 2 for Better, 3 for Best) |
| `quantity_override` | No | number | Override quantity for this tier |
| `paint_product_id` | No | integer | Override paint product for this tier |
| `labor_hours` | No | number | Explicit labor hours (skips auto-calculation from rate and coats) |
| `material_cost` | No | number | Explicit material cost (skips auto-calculation from paint product) |

**Call pattern:** Call once per surface per tier. For an area with 4 surfaces and 3 tiers, that is 12 calls.

**Common mistakes:**
- Skipping this tool entirely. Tier snapshots are REQUIRED, not optional. Without them, tier tabs are empty.
- Only calling it for some surfaces but not others. Every surface on every area must have snapshots for all 3 tiers.
- Only calling it for one tier. All 3 tiers (good, better, best) need snapshots on every surface.

---

### `remove_surface_ai_tool`

**Purpose:** Delete a surface from an area.

**When to use:** When the user wants to remove a specific surface. Example: "remove the ceiling from the kitchen" or "don't include baseboards in the hallway."

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `surface_id` | Yes | integer | ID of the surface to remove. Get this from `get_estimate_state_ai_tool`. |

---

### `add_addon_to_area_ai_tool`

**Purpose:** Add a fixed-price nested template add-on to an area. Add-ons are items like closets, half baths, accent walls, repairs, or warranties that have a fixed price and get attached to a room.

**When to use:** When the user mentions add-on items: "the master bedroom has a walk-in closet", "include wallpaper removal", "add the warranty". Search `list_nested_templates_ai_tool` first to find the right template.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `area_id` | Yes | integer | ID of the area to add the addon to |
| `nested_template_id` | Yes | integer | Nested template ID from `list_nested_templates_ai_tool` results |
| `quantity` | No | integer | Number of this addon. Default: 1. Example: a bedroom with 2 closets → quantity=2 |

**Returns:** The addon details with pricing added to the area.

---

## Group 5: Area Management Tools

These tools modify or delete entire areas.

### `remove_area_ai_tool`

**Purpose:** Delete an entire area and all its surfaces, add-ons, and cost items from the estimate.

**When to use:** When the user says "remove the hallway", "delete that room", "take off the kitchen". Always call `get_estimate_state_ai_tool` first to find the area_id.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `area_id` | Yes | integer | ID of the area to remove |

---

### `update_area_dimensions_ai_tool`

**Purpose:** Change an area's dimensions. Automatically recalculates all surface quantities (wall_sqft, ceiling_sqft, perimeter_lnft) and reprices everything.

**When to use:** When the user says "actually the living room is 14x16", "change the kitchen dimensions", "make that room bigger". This is the correct tool for dimension changes — it propagates the change to all surfaces automatically. You do NOT need to update each surface individually.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `area_id` | Yes | integer | ID of the area to update |
| `length` | No | number | New length in feet |
| `width` | No | number | New width in feet |
| `height` | No | number | New wall height in feet |
| `wall_count` | No | integer | New number of walls (1-4) |

**Returns:** Updated area with recalculated dimensions and repriced surfaces.

**Common mistakes:**
- Manually updating each surface's quantity after changing dimensions. You don't need to — this tool auto-recalculates everything.

---

### `preview_area_pricing_ai_tool`

**Purpose:** Get a detailed cost breakdown for a single area. Shows per-surface labor hours, labor costs, material costs, COGS, and the OTD (customer) price.

**When to use:** After making changes to an area to verify the pricing looks correct before moving on. Also useful when the user asks "show me the breakdown for the kitchen" or "how much is the living room?"

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `area_id` | Yes | integer | ID of the area to preview pricing for |

**Returns:** Detailed breakdown with per-surface quantities, rates, hours, labor costs, material costs, COGS, and OTD price.

---

### `set_area_package_tiers_ai_tool`

**Purpose:** Control which Good/Better/Best tiers include this area. Create tier-exclusive areas — for example, an accent wall that only appears in the "Best" tier, or a basic room that appears in all tiers but has different surfaces per tier.

**When to use:** When the user wants certain rooms to only appear in certain tiers. Example: "only include the accent wall in the Best package", "the detached garage is only in Better and Best."

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `area_id` | Yes | integer | ID of the area to configure |
| `tiers` | No | array | Array of tier names this area appears in. Examples: `["best"]`, `["better", "best"]`, `["good", "better", "best"]`. Null or empty = all tiers (default). |

---

## Group 6: Line Items & Cost Items

These tools manage charges outside the standard surface pricing model.

### `create_estimate_item_ai_tool`

**Purpose:** Create a standalone line item on the estimate for equipment rental, material purchases, subcontractor work, or other charges that are not tied to a specific area or surface. These are customer-facing items that appear on the proposal.

**When to use:** When the user needs to add charges that exist outside the PBP surface model:
- "Add $500 for scaffolding rental"
- "Include a $200 power washing fee"
- "Add a 10% referral fee"
- Subcontractor charges, permit fees, dumpster rentals, etc.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `description` | Yes | string | Item description. Examples: "Sprayer rental (2 days)", "Scaffolding rental", "Power washing" |
| `quantity` | Yes | number | Quantity. Example: 2 for 2 days of rental |
| `unit` | No | string | Unit. Default: "each". Options: "each", "day", "hour", "gallon", "lnft", "sqft" |
| `unit_price` | Yes | number | Customer price per unit (what the customer pays) |
| `cost` | No | number | Vendor/internal cost per unit (optional, for profit tracking) |

**Returns:** The created line item with total price.

**Common mistakes:**
- Using this for materials that should flow through COGS margins. If you want the company's margin applied to the cost, use `add_cost_item_ai_tool` instead (cost items flow into COGS). Estimate items are direct customer charges with no automatic margin application.

---

### `add_cost_item_ai_tool`

**Purpose:** Add a cost item (material, rental, equipment, service) to an area or surface. Cost items flow into COGS and are included in margin calculations — the company's margin percentage is automatically applied on top of the cost.

**When to use:** When adding materials, rentals, or other costs that should be part of the area's COGS:
- "Baseboard material at $2.50 per linear foot" → target_type="surface", target_id=baseboard_surface_id
- "Sprayer rental $200/day for 2 days" → target_type="area", target_id=area_id
- "Primer for new drywall, 2 gallons at $35/gal" → target_type="area", target_id=area_id

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `target_type` | Yes | string | Target: "area" or "surface" |
| `target_id` | Yes | integer | Area ID or Surface ID |
| `name` | Yes | string | Item name. Examples: "Baseboard Material", "Sprayer Rental", "Bonding Primer" |
| `cost_type` | No | string | Type. Default: "material". Options: "material", "rental", "equipment", "service", "custom" |
| `quantity` | No | number | Quantity. Default: 1 |
| `unit` | No | string | Unit. Default: "each". Options: "lnft", "sqft", "day", "each", "hour", "gallon" |
| `unit_cost` | No | number | Vendor cost per unit (what the company pays) |
| `unit_price` | No | number | Customer price per unit (what the customer is charged) |
| `tier` | No | string | Optional tier filter: "good", "better", "best". Null = applies to all tiers. |
| `notes` | No | string | Optional notes |

**Returns:** The created cost item with calculated totals.

**Common mistakes:**
- Confusing `add_cost_item_ai_tool` with `create_estimate_item_ai_tool`. Cost items attach to areas/surfaces and flow into COGS (margins apply). Estimate items are standalone line items on the estimate (no automatic margin).

---

### `remove_cost_item_ai_tool`

**Purpose:** Remove a cost item from an area or surface.

**When to use:** When the user wants to remove a previously added cost item. Use `list_cost_items_ai_tool` first to find the cost_item_id.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `cost_item_id` | Yes | integer | Cost item ID to remove. Get this from `list_cost_items_ai_tool`. |

---

## Group 7: Paint & Pricing Tools

These tools manage paint product assignments, tier pricing, estimate-level settings, and recalculation.

### `set_estimate_paint_config_ai_tool`

**Purpose:** Set paint product(s) for the entire estimate globally. Two modes: "single" (one paint for all surfaces) or "category" (different paint per surface type — walls get one paint, trim gets another, etc.).

**When to use:** When the user says "use SuperPaint for everything", "use Duration for walls and ProMar for trim", or when you need to set a baseline paint product for the estimate.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `mode` | Yes | string | Paint mode: "single" (one paint for all surfaces) or "category" (different paint per surface type) |
| `paint_product_id` | No | integer | Paint product ID for single mode, or default fallback for category mode |
| `walls_paint_id` | No | integer | Paint product ID for walls (category mode only) |
| `ceiling_paint_id` | No | integer | Paint product ID for ceilings (category mode only) |
| `trim_paint_id` | No | integer | Paint product ID for trim/baseboards (category mode only) |
| `doors_paint_id` | No | integer | Paint product ID for doors (category mode only) |

---

### `set_area_paint_product_ai_tool`

**Purpose:** Override the paint product for a specific area. Allows per-area paint customization when different rooms need different paints.

**When to use:** When the user says "use a different paint for the kitchen" or "the bathroom needs semi-gloss". This overrides the estimate-level paint config for just this area.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `area_id` | Yes | integer | ID of the area to set paint for |
| `paint_product_id` | No | integer | Paint product ID for all surfaces in this area |
| `walls_paint_id` | No | integer | Paint product ID for walls only |
| `ceiling_paint_id` | No | integer | Paint product ID for ceilings only |
| `trim_paint_id` | No | integer | Paint product ID for trim/baseboards only |
| `doors_paint_id` | No | integer | Paint product ID for doors only |

---

### `set_tier_pricing_ai_tool`

**Purpose:** Configure Good/Better/Best tier pricing by setting paint products per tier at the estimate level. This tool sets PAINT PRODUCTS only — it does NOT change coats or labor hours. To set per-tier coats and labor, use `update_surface_tier_overrides_ai_tool`.

**When to use:** As Step 1 of the tier pricing workflow. Call once per tier (good, better, best). You can set a single paint for the whole tier, or set different paints per surface category.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `tier` | Yes | string | Tier name: "good", "better", or "best" |
| `paint_product_id` | No | integer | Default paint product ID for this tier (applies to all surfaces unless overridden by category-specific params) |
| `walls_paint_id` | No | integer | Paint product ID for walls in this tier |
| `ceiling_paint_id` | No | integer | Paint product ID for ceilings in this tier |
| `trim_paint_id` | No | integer | Paint product ID for trim in this tier |
| `doors_paint_id` | No | integer | Paint product ID for doors in this tier |

**Common mistakes:**
- Thinking this tool alone completes tier setup. It does NOT. You MUST also call `update_surface_tier_overrides_ai_tool` for every surface on every tier (Step 2). Without Step 2, tier tabs are empty and broken.

---

### `update_estimate_settings_ai_tool`

**Purpose:** Update estimate-level settings — title, project type, tax rate, deposit percentage, margin discount, and internal notes.

**When to use:** When the user says "change the title to...", "set tax rate to 8.31%", "add a 50% deposit", "give them a 5% discount", or "change this to an exterior estimate".

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `title` | No | string | Estimate title/name |
| `project_type` | No | string | Project type: "interior", "exterior", or "both" |
| `tax_rate` | No | number | Tax rate percentage (e.g., 8.31 for 8.31%) |
| `deposit_percent` | No | number | Deposit percentage (e.g., 50 for 50% deposit) |
| `margin_discount_percent` | No | number | Margin discount to lower the customer price (e.g., 5 for a 5% discount off the margin) |
| `notes` | No | string | Internal notes for the estimate (not shown to customer) |

---

### `recalculate_estimate_ai_tool`

**Purpose:** Force a full recalculation of all areas, surfaces, and estimate totals. Refreshes all pricing from current data.

**When to use:** **ALWAYS call this after making multiple changes** to ensure all totals are synchronized. Individual tool calls (like `add_surface_to_area_ai_tool`) recalculate their own area, but the estimate-level totals (subtotal, tax, grand total, deposit, balance due) may need a refresh. Calling this ensures everything is consistent.

Also call this after:
- Completing tier pricing setup
- Adding or removing multiple areas
- Changing paint products or estimate settings
- Any batch of changes where you want to confirm final totals

**Parameters:** None.

**Returns:** Updated estimate totals with per-area breakdown.

---

## Group 8: AI Memory & Output

These tools manage persistent AI notes and customer-facing output.

### `save_ai_context_ai_tool`

**Purpose:** Save internal AI notes to this estimate that persist across conversations. Use this to remember important project details, customer preferences, or decisions made during the conversation so future sessions have context.

**When to use:** When the user shares important information you want to remember:
- Customer preferences: "customer wants all flat sheen"
- Budget constraints: "budget is $8,000"
- Special requirements: "popcorn ceilings throughout"
- Decisions made: "agreed on 2 coats for walls, 1 coat for ceilings"
- Property details: "2-story colonial, 2,800 sqft"

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `context` | Yes | string | Internal notes to save. **Append to existing notes** rather than replacing them — the system preserves previous context, so write new observations that add to the record. |

**Common mistakes:**
- Overwriting previous notes. Write additive notes that complement what was saved before.

---

### `write_estimate_summary_ai_tool`

**Purpose:** Write a professional markdown summary for the customer proposal. This text appears on the public proposal page that the customer receives. It should describe the scope of work in clear, professional, customer-facing language.

**When to use:** When the user asks you to "write a summary", "create the proposal description", or when you've finished building the estimate and want to add a professional description. Also useful when the user says "send this to the customer" — write the summary first so the proposal looks professional.

**Parameters:**
| Parameter | Required | Type | Description |
|---|---|---|---|
| `summary` | Yes | string | Markdown-formatted proposal summary for the customer. Include a scope overview, key details (number of rooms, paint products, special work), and maintain a professional tone. No internal jargon, no COGS references, no production rate details. |

**Common mistakes:**
- Including internal pricing details (COGS, margins, labor rates). The customer should not see these.
- Being too technical. Write for a homeowner, not a painting contractor.
- Forgetting to include the scope overview. The customer should understand exactly what work is included.
