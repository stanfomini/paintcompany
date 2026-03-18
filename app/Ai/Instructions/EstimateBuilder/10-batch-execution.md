# Batch Execution — execute_estimate_plan_ai_tool

## When to Use

Use `execute_estimate_plan_ai_tool` when the request involves **any** of:
- 2+ areas to create
- Tier pricing (good/better/best)
- Full house estimates
- Complex requests that would require 20+ individual tool calls

For simple requests (add one room, change coats, update a name), use individual tools directly.

## How It Works

1. **You do discovery** — search templates, list rates, list paints (read-only tools)
2. **You build a JSON plan** — structured object describing everything to create
3. **You call `execute_estimate_plan_ai_tool` once** — PHP creates everything in a single DB transaction
4. **You verify** — `get_estimate_summary_ai_tool` to confirm results

The mega-tool replaces 30-60+ individual tool calls with a single call. All areas, surfaces, tier overrides, addons, and line items are created atomically — if anything fails, nothing is created.

## Plan Structure

```json
{
  "estimate_settings": {
    "title": "Smith Residence - Interior",
    "project_type": "interior"
  },
  "tier_config": {
    "good":   { "paint_product_id": 9,  "default_coats": 1 },
    "better": { "paint_product_id": 3,  "default_coats": 2 },
    "best":   { "paint_product_id": 45, "default_coats": 2 }
  },
  "areas": [
    {
      "source": "template",
      "template_id": 5,
      "size": "medium",
      "coats": 2,
      "addons": [{ "nested_template_id": 3, "quantity": 1 }]
    },
    {
      "source": "custom",
      "name": "Kitchen",
      "scope_type": "interior",
      "dimensions": { "length": 12, "width": 14, "height": 9 },
      "surfaces": [
        { "name": "Walls", "surface_category": "walls", "production_rate_id": 13, "coats": 2 },
        { "name": "Ceiling", "surface_category": "ceiling", "production_rate_id": 14, "coats": 2 },
        { "name": "Cabinet Faces", "surface_category": "trim", "production_rate_id": "ref:new_rate_0", "quantity_override": 14, "coats": 2 }
      ]
    }
  ],
  "create_first": {
    "production_rates": [
      { "ref": "new_rate_0", "name": "Cabinet Faces", "scope": "interior", "surface_category": "trim", "rate_type": "hours_per_unit", "production_rate": 0.75 }
    ]
  },
  "line_items": [
    { "description": "Dumpster rental", "quantity": 1, "unit_price": 350 }
  ]
}
```

## Key Features

- **Template areas**: Set `source: "template"` with `template_id` and `size`. Surfaces are auto-created from the template.
- **Custom areas**: Set `source: "custom"` with `name`, `dimensions`, and `surfaces` array. You must specify surfaces manually.
- **Rate references**: Use `create_first.production_rates` with a `ref` key, then reference as `"ref:new_rate_0"` in surface `production_rate_id`.
- **Auto tier overrides**: When `tier_config` is provided, tier snapshots are automatically created for every surface on every area. No need to call `update_surface_tier_overrides_ai_tool` separately.
- **Package tiers**: Set `package_tiers: ["better", "best"]` on an area to restrict it to specific tiers.
- **Transaction safety**: All-or-nothing. If area 3 fails, areas 1 and 2 are also rolled back.

## Example: "Paint my 3-bedroom house with good/better/best"

### Discovery Phase (you do this with individual tools):
```
get_estimate_state_ai_tool
search_templates_ai_tool("bedroom")        → template_id=2
search_templates_ai_tool("living room")    → template_id=5
search_templates_ai_tool("kitchen")        → template_id=7
list_paint_products_ai_tool(quality_tier="good")    → ProMar 200 (id=9)
list_paint_products_ai_tool(quality_tier="better")  → SuperPaint (id=3)
list_paint_products_ai_tool(quality_tier="best")    → Duration (id=45)
```

### Execution Phase (single tool call):
```json
{
  "estimate_settings": { "title": "Johnson Residence - Interior", "project_type": "interior" },
  "tier_config": {
    "good":   { "paint_product_id": 9,  "default_coats": 1 },
    "better": { "paint_product_id": 3,  "default_coats": 2 },
    "best":   { "paint_product_id": 45, "default_coats": 2 }
  },
  "areas": [
    { "source": "template", "template_id": 5, "size": "large", "name": "Living Room" },
    { "source": "template", "template_id": 7, "size": "medium", "name": "Kitchen" },
    { "source": "template", "template_id": 2, "size": "medium", "name": "Bedroom 1" },
    { "source": "template", "template_id": 2, "size": "medium", "name": "Bedroom 2" },
    { "source": "template", "template_id": 2, "size": "small", "name": "Bedroom 3" }
  ]
}
```

This creates 5 areas with ~20 surfaces and 60 tier overrides in **one step** instead of 67+.

### Verification Phase:
```
get_estimate_summary_ai_tool
```

## Example: Exterior House with Custom Surfaces

### Discovery Phase:
```
get_estimate_state_ai_tool
list_production_rates_ai_tool(scope="exterior")       → body rate (id=20), fascia (id=21), eaves (id=22), trim (id=23), ext doors (id=24), windows (id=25)
list_paint_products_ai_tool(scope="exterior")          → Duration Exterior (id=12)
```

### Execution Phase:
```json
{
  "estimate_settings": { "title": "Martinez Residence - Exterior", "project_type": "exterior" },
  "paint_config": { "mode": "single", "paint_product_id": 12 },
  "areas": [
    {
      "source": "custom",
      "name": "House Exterior",
      "scope_type": "exterior",
      "dimensions": { "length": 50, "width": 30, "height": 12 },
      "surfaces": [
        { "name": "Body/Siding", "surface_category": "body", "production_rate_id": 20, "coats": 2 },
        { "name": "Fascia", "surface_category": "fascia", "production_rate_id": 21, "coats": 2 },
        { "name": "Eaves/Soffits", "surface_category": "eaves", "production_rate_id": 22, "coats": 2 },
        { "name": "Exterior Trim", "surface_category": "trim", "production_rate_id": 23, "coats": 2 },
        { "name": "Front Door", "surface_category": "exterior_doors", "production_rate_id": 24, "coats": 2, "quantity_override": 1 },
        { "name": "Windows (8)", "surface_category": "windows", "production_rate_id": 25, "coats": 2, "quantity_override": 8 }
      ]
    }
  ]
}
```

Note: Body/siding, fascia, eaves, and trim quantities auto-calculate from dimensions. Doors and windows need `quantity_override` because they're count-based.

## Example: Kitchen Cabinets with New Production Rate

### Discovery Phase:
```
get_estimate_state_ai_tool
list_production_rates_ai_tool(scope="interior", category="trim")  → check for cabinet rates
list_production_rates_ai_tool(scope="interior", category="walls") → wall rate (id=13)
list_production_rates_ai_tool(scope="interior", category="ceiling") → ceiling rate (id=14)
```

No cabinet face rate found, so we create one via `create_first`:

### Execution Phase:
```json
{
  "create_first": {
    "production_rates": [
      { "ref": "cab_faces", "name": "Cabinet Door Faces", "scope": "interior", "surface_category": "trim", "rate_type": "hours_per_unit", "production_rate": 0.75, "unit_type": "unit", "description": "Per cabinet door or drawer front" }
    ]
  },
  "areas": [
    {
      "source": "custom",
      "name": "Kitchen",
      "scope_type": "interior",
      "dimensions": { "length": 12, "width": 14, "height": 9 },
      "surfaces": [
        { "name": "Walls", "surface_category": "walls", "production_rate_id": 13, "coats": 2 },
        { "name": "Ceiling", "surface_category": "ceiling", "production_rate_id": 14, "coats": 2 },
        { "name": "Cabinet Door Faces (18)", "surface_category": "trim", "production_rate_id": "ref:cab_faces", "quantity_override": 18, "coats": 2 },
        { "name": "Drawer Fronts (12)", "surface_category": "trim", "production_rate_id": "ref:cab_faces", "quantity_override": 12, "coats": 2 }
      ],
      "cost_items": [
        { "name": "Cabinet degreaser & sandpaper", "cost_type": "material", "quantity": 1, "unit_cost": 45 },
        { "name": "Spray tips & masking", "cost_type": "material", "quantity": 1, "unit_cost": 65 }
      ]
    }
  ]
}
```

## Deciding Template vs Custom

| Situation | Use | Why |
|---|---|---|
| Standard room (bedroom, bathroom, living room) | `source: "template"` | Template has pre-configured surfaces, rates, and S/M/L dimensions |
| Room with extra surfaces (kitchen + cabinets) | `source: "custom"` | Need surfaces not in standard template |
| Exterior work | `source: "custom"` | Exterior areas need specific surface combinations per house |
| Room with known dimensions ("my kitchen is 12x14") | `source: "custom"` | User gave exact dimensions, don't use template sizes |
| Room type exists as template but user wants different surfaces | `source: "template"` + modify after | Start from template, then add/remove surfaces with individual tools |
| "3 bedrooms" | `source: "template"` ×3 | Same template, different names: "Bedroom 1", "Bedroom 2", "Bedroom 3" |

## Deciding When to Set `quantity_override`

| Surface Category | Override needed? | Why |
|---|---|---|
| `walls` / `body` | No | Auto-calculated from wall_sqft |
| `ceiling` / `eaves` | No | Auto-calculated from ceiling_sqft |
| `baseboard` / `trim` / `fascia` | No | Auto-calculated from perimeter_lnft |
| `doors` / `exterior_doors` | **YES** — set to door count | Default is 1; set actual count |
| `windows` | **YES** — set to window count | Default is 1; set actual count |
| Cabinet faces, spindles, shelves | **YES** — set to item count | `trim` category with `custom` source |

## Updating Existing Areas and Surfaces

Use `update_areas` to modify areas that already exist on the estimate. Get area and surface IDs from `get_estimate_state_ai_tool`.

### Example: "Rename the surfaces on my exterior"
```json
{
  "update_areas": [
    {
      "area_id": 5,
      "update_surfaces": [
        { "surface_id": 22, "name": "Body/Siding" },
        { "surface_id": 23, "name": "Fascia" },
        { "surface_id": 24, "name": "Eaves/Soffits" },
        { "surface_id": 25, "name": "Exterior Trim" },
        { "surface_id": 26, "name": "Front Door" },
        { "surface_id": 27, "name": "Windows (8)" }
      ]
    }
  ]
}
```

### Example: "Change everything to 3 coats"
```json
{
  "update_areas": [
    {
      "area_id": 5,
      "update_surfaces": [
        { "surface_id": 22, "coats": 3 },
        { "surface_id": 23, "coats": 3 },
        { "surface_id": 24, "coats": 3 }
      ]
    },
    {
      "area_id": 6,
      "update_surfaces": [
        { "surface_id": 30, "coats": 3 },
        { "surface_id": 31, "coats": 3 }
      ]
    }
  ]
}
```

### Example: "Fix the fascia — it should use the fascia category, not trim"
```json
{
  "update_areas": [
    {
      "area_id": 5,
      "update_surfaces": [
        { "surface_id": 23, "name": "Fascia", "surface_category": "fascia", "production_rate_id": 83 }
      ]
    }
  ]
}
```

### Example: "Add crown molding to every room"
```json
{
  "update_areas": [
    {
      "area_id": 5,
      "add_surfaces": [
        { "name": "Crown Molding", "surface_category": "trim", "production_rate_id": 128, "coats": 2 }
      ]
    },
    {
      "area_id": 6,
      "add_surfaces": [
        { "name": "Crown Molding", "surface_category": "trim", "production_rate_id": 128, "coats": 2 }
      ]
    }
  ]
}
```

### Example: "Remove windows from the kitchen and add 5 windows to the exterior"
```json
{
  "update_areas": [
    { "area_id": 5, "remove_surface_ids": [27] },
    {
      "area_id": 8,
      "add_surfaces": [
        { "name": "Windows (5)", "surface_category": "windows", "production_rate_id": 88, "coats": 2, "quantity_override": 5 }
      ]
    }
  ]
}
```

### Example: "Now add good/better/best to my existing estimate"
```json
{
  "tier_config": {
    "good": { "paint_product_id": 9, "default_coats": 1 },
    "better": { "paint_product_id": 3, "default_coats": 2 },
    "best": { "paint_product_id": 45, "default_coats": 2 }
  },
  "apply_tiers_to_existing": true
}
```
This applies tier snapshots to ALL existing surfaces that don't already have them.

### Example: Mixed — Create new area + update existing
```json
{
  "areas": [
    { "source": "template", "template_id": 2, "size": "medium", "name": "Guest Bedroom" }
  ],
  "update_areas": [
    {
      "area_id": 5,
      "name": "Master Bedroom",
      "update_surfaces": [
        { "surface_id": 22, "coats": 3 }
      ],
      "add_surfaces": [
        { "name": "Crown Molding", "surface_category": "trim", "production_rate_id": 128, "coats": 2 }
      ]
    }
  ]
}
```

## What NOT to Put in the Plan

- Don't put existing areas in `areas` — use `update_areas` to modify existing, `areas` to create new
- Don't set `paint_product_id` on individual surfaces unless the user explicitly wants different paint on specific surfaces
- Don't include `recalculate_estimate_ai_tool` — the plan auto-recalculates at the end
- Don't forget `paint_config` or `tier_config` — without paint, material cost is $0 and the estimate is underpriced
- Don't use `source: "custom"` without surfaces — custom areas start empty; you must define surfaces or the area has $0 price
