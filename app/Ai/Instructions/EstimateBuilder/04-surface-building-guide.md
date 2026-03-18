# Surface Building Guide

## Step 1: Choose Surface Category

| What you're painting | Interior category | Exterior category |
|---|---|---|
| Walls, accent walls, wainscoting, garage walls | `walls` | — |
| Siding, stucco, masonry, brick | — | `body` |
| Ceiling, vaulted, popcorn | `ceiling` | — |
| Soffits, eaves, overhangs | — | `eaves` |
| Baseboards | `baseboard` | — |
| Crown molding, chair rail, window casing, general trim | `trim` | `trim` |
| Fascia boards | — | `fascia` |
| Interior doors (standard, closet, french, bi-fold) | `doors` | — |
| Exterior doors (front, garage service, storm) | — | `exterior_doors` |
| Windows | `windows` | `windows` |
| Cabinets, shelving, mantel, railing, spindles, bookcase | `trim` | — |
| Prep work (masking, caulking, sanding, priming) | `prep` | `prep` |
| Wood replacement / rot repair | — | `wood_replacement` |

**Invalid categories:** `crown` → use `trim`. `molding` → use `trim`. `siding` → use `body`. `soffit` → use `eaves`. `baseboard` is interior only.

## Step 2: Find the Production Rate

1. Call `list_production_rates_ai_tool` filtered by scope + the category from Step 1
2. Pick the rate matching the job description
3. If none exists, follow Lookup-or-Create: research rate via web search, create with `create_production_rate_ai_tool`, explain reasoning, then use it

The rate's `surface_category` MUST match the surface's `surface_category` or the rate won't appear.

## Step 3: Set Quantity Source

| Category | Default Source | Unit | Calculation |
|---|---|---|---|
| `walls` / `body` | `wall_sqft` | sqft | `perimeter_lnft * height`, adjusted by wall_count |
| `ceiling` / `eaves` | `ceiling_sqft` | sqft | `length * width` |
| `baseboard` / `trim` / `fascia` | `perimeter_lnft` | lnft | `2 * (length + width) * (wall_count / 4)` |
| `doors` / `exterior_doors` | `door_count` | units | Manual — must set `quantity_override` |
| `windows` | `window_count` | units | Manual — must set `quantity_override` |

Use `custom` source (with `quantity_override`) for items not derived from room dimensions: cabinet faces, spindles, mantel, customer-provided measurements, outlet covers, shelves.

Trim category uses `perimeter_lnft` for linear items (crown, chair rail) and `custom` for counted items (cabinet faces, spindles).

## Step 4: Set Quantity Override

- **Auto-calc sources** (`wall_sqft`, `ceiling_sqft`, `perimeter_lnft`): no override needed unless user provides a specific measurement
- **Count sources** (`door_count`, `window_count`): MUST set override to actual count (default=1)
- **`custom`**: MUST set override — no default, surface has zero quantity without it

## Step 5: Name the Surface

| Rule | Bad | Good |
|---|---|---|
| Title Case always | crown molding | Crown Molding |
| Be specific, not generic | Trim | Crown Molding |
| Differentiate multiples | Trim, Trim, Trim | Baseboards, Crown Molding, Window Casing |
| Include count >1 | Doors | Interior Doors (3) |
| Name what it is | Other | Cabinet Door Faces |
| Customer-friendly | bath 2 | Guest Bathroom |
| Name surface, not scope | Exterior | Body/Siding |

## Step 6: Set Coats

Default: **2 coats**. Coat multipliers (auto-applied): 1 coat=1.0x, 2 coats=1.8x, 3 coats=2.5x.

- 1 coat: touch-ups, same-color refresh, primer-only
- 2 coats: standard (default for nearly all jobs)
- 3 coats: dark-to-light color changes, bold colors, new drywall

Set `application_method` to `spray` for spray jobs (exterior body, cabinets). Affects material cost, not labor hours.

## Examples

**Example 1 — Standard room ("Add a medium living room"):**
`search_templates_ai_tool("living room")` → `create_area_from_template_ai_tool(template_id=X, size="medium")`.
Template auto-creates: Walls (walls/wall_sqft), Ceiling (ceiling/ceiling_sqft), Baseboards (baseboard/perimeter_lnft), Doors (doors/door_count/override=1).

**Example 2 — Kitchen with cabinets ("Add kitchen 12x14, include cabinets"):**
`create_custom_area_ai_tool(name="Kitchen", scope_type="interior", length=12, width=14, height=9)`.
Add standard surfaces (walls, ceiling, baseboards). For cabinets: find/create trim-category rate (e.g., 0.75 hrs/unit), add "Cabinet Door Faces" with category=`trim`, source=`custom`, override=14.

**Example 3 — Full house exterior:**
Surfaces needed: Body/Siding (`body`/`wall_sqft`/spray), Fascia (`fascia`/`perimeter_lnft`), Eaves/Soffits (`eaves`/`ceiling_sqft`), Exterior Trim (`trim`/`perimeter_lnft`), Front Door (`exterior_doors`/override=1), Windows (`windows`/override=count).

**Example 4 — Single face ("Front of house, 45ft wide, 12ft tall"):**
`create_custom_area_ai_tool(name="Front of House", scope_type="exterior", length=0, width=45, height=12, wall_count=1)`.
length=0 + wall_count=1 = single face. Add front-specific surfaces only.

**Example 5 — Partial room ("3 walls, 4th is brick"):**
`create_custom_area_ai_tool(..., wall_count=3)`. wall_count=3 auto-adjusts wall_sqft/perimeter to 75%. ceiling_sqft is NOT affected by wall_count.

**Example 6 — Single accent wall ("14ft wide, 10ft tall"):**
`create_custom_area_ai_tool(name="Accent Wall", scope_type="interior", length=0, width=14, height=10, wall_count=1)`.
Add ONLY a walls surface. Do not add ceiling/baseboards/doors unless asked.
