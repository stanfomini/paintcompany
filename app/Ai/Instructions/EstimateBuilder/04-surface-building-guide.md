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

**The system already has 125+ production rates for every common surface type.** Almost never create a new rate.

1. Call `list_production_rates_ai_tool` filtered by scope + the category from Step 1
2. **You WILL find matching rates** for walls, ceiling, baseboard, trim, doors, windows, body, eaves, fascia, exterior_doors, wood_replacement, and prep. They are pre-loaded.
3. Pick the rate matching the job description (e.g., "Fascia on 1 story home" for a single-story fascia job)
4. **Only create a new rate** if the work is truly unusual AND no existing rate fits (e.g., venetian plaster, epoxy floor)

**Common mistake: creating a new rate instead of using an existing one.** Example: the system has "Fascia on 1 story home" at 40 lnft/hr under category `fascia`. Do NOT create "Fascia Painting" under category `trim` at a guessed rate. Search first, use what exists.

The rate's `surface_category` MUST match the surface's `surface_category` or it won't appear.

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
- **Count sources** (`door_count`, `window_count`): **MUST set `quantity_override` to actual count.** If the user says "5 windows", set override to 5. If they say "a door", set override to 1. If they say "a front door and a garage service door", that's 2 doors — set override to 2. **Never leave doors or windows without a quantity override** — the default is 1, which is wrong for multi-window/door exteriors.
- **`custom`**: MUST set override — no default, surface has zero quantity without it

**Exterior example:** "Front of house with 5 windows and 1 door" → Windows surface needs `quantity_override: 5`, Door surface needs `quantity_override: 1`.

## Step 5: Name the Surface

Surface names appear on the customer proposal. They must be professional, specific, and match what's being painted.

### Naming Rules

| Rule | Bad | Good |
|---|---|---|
| Title Case always | crown molding | Crown Molding |
| Be specific, not generic | Trim | Crown Molding |
| Differentiate multiples | Trim, Trim, Trim | Baseboards, Crown Molding, Window Casing |
| Include count when >1 | Doors | Interior Doors (3) |
| Include count for windows | Windows | Windows (8) |
| Name what it is | Other | Cabinet Door Faces |
| Customer-friendly | bath 2 | Guest Bathroom |
| Name surface, not scope | Exterior | Body/Siding |
| Match the production rate name | Painting | Fascia |
| Don't use category as name | trim | Exterior Trim |

### Standard Surface Names by Category

Use these exact names. The customer reads them on the proposal.

**Interior:**

| Category | Standard Name | When to Vary |
|---|---|---|
| `walls` | Walls | "Accent Wall" for single wall, "Garage Walls" for garage |
| `ceiling` | Ceiling | "Vaulted Ceiling" if vaulted, "Popcorn Ceiling" if textured |
| `baseboard` | Baseboards | — |
| `trim` | Crown Molding | Only if it's crown molding |
| `trim` | Chair Rail | Only if it's chair rail |
| `trim` | Window Casing | Only if it's window trim |
| `trim` | General Trim | Catchall for misc trim |
| `trim` | Cabinet Door Faces (N) | Include count |
| `trim` | Drawer Fronts (N) | Include count |
| `trim` | Stair Railing & Spindles | — |
| `trim` | Fireplace Mantel | — |
| `trim` | Built-In Bookcase | — |
| `doors` | Interior Doors (N) | Always include count |
| `doors` | Closet Doors (N) | Separate from regular doors |
| `windows` | Windows (N) | Always include count |
| `prep` | Masking & Prep | — |
| `prep` | Caulking | — |

**Exterior:**

| Category | Standard Name | When to Vary |
|---|---|---|
| `body` | Body/Siding | Default for main exterior |
| `body` | Stucco | If stucco |
| `body` | Masonry | If brick/block |
| `body` | Accent Siding | For accent sections |
| `fascia` | Fascia | Never call this "Trim" |
| `eaves` | Eaves/Soffits | Never call this "Ceiling" |
| `trim` | Exterior Trim | For downspouts, posts, misc trim |
| `trim` | Tudor Trim | If applicable |
| `exterior_doors` | Front Door | For front entry |
| `exterior_doors` | Garage Service Door | For garage side door |
| `exterior_doors` | Exterior Doors (N) | For multiple, include count |
| `windows` | Windows (N) | Always include count |
| `wood_replacement` | Wood Replacement | — |
| `prep` | Power Washing | — |
| `prep` | Caulking | — |
| `prep` | Scrape, Sand & Prime | For peeling areas |

### Naming Mistakes to Avoid

| Wrong | Why It's Wrong | Correct |
|---|---|---|
| "Trim" for fascia | Fascia is its own category, not trim | "Fascia" |
| "Ceiling" for soffits | Eaves are exterior, ceiling is interior | "Eaves/Soffits" |
| "Doors" for exterior | Interior and exterior doors are different categories | "Front Door" or "Exterior Doors (2)" |
| "Painting" | Too vague, means nothing to customer | Name the actual surface |
| "Surface 1" | Generic, customer can't understand | Name what's being painted |
| "Windows" without count | Customer needs to know how many | "Windows (8)" |
| "Doors" without count | Customer needs to know how many | "Interior Doors (3)" |
| "Other Trim" for everything | Be specific about what trim it is | "Crown Molding", "Window Casing", etc. |

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
A typical exterior has these surfaces, each with its OWN category:

| Surface | Category | Rate from | Quantity | Override? |
|---|---|---|---|---|
| Body/Siding | `body` | "Siding Spray" (200 sqft/hr) | auto from wall_sqft | No |
| Fascia | `fascia` | "Fascia on 1 story home" (40 lnft/hr) | auto from perimeter_lnft | No |
| Eaves/Soffits | `eaves` | "Eaves Body Color" (75 lnft/hr) | auto from perimeter_lnft | No |
| Exterior Trim | `trim` | "Other trim on 1 story home" (40 lnft/hr) | auto from perimeter_lnft | No |
| Front Door | `exterior_doors` | "Door Body Color with Trim" (1.33 units/hr) | 1 | **YES: 1** |
| Garage Door | `exterior_doors` | "Door Body Color with no trim" (4 units/hr) | 1 | **YES: 1** |
| Windows (8) | `windows` | "Windows Vinyl with trim" (2 units/hr) | 8 | **YES: 8** |

**CRITICAL:** Fascia is category `fascia`, NOT `trim`. Eaves are `eaves`, NOT `ceiling`. Exterior doors are `exterior_doors`, NOT `doors`. Each has its own production rates in the system — use them.

**Example 4 — Single face ("Front of house, 45ft wide, 12ft tall"):**
`create_custom_area_ai_tool(name="Front of House", scope_type="exterior", length=0, width=45, height=12, wall_count=1)`.
length=0 + wall_count=1 = single face. Add front-specific surfaces only. Windows and doors still need `quantity_override`.

**Example 5 — Partial room ("3 walls, 4th is brick"):**
`create_custom_area_ai_tool(..., wall_count=3)`. wall_count=3 auto-adjusts wall_sqft/perimeter to 75%. ceiling_sqft is NOT affected by wall_count.

**Example 6 — Single accent wall ("14ft wide, 10ft tall"):**
`create_custom_area_ai_tool(name="Accent Wall", scope_type="interior", length=0, width=14, height=10, wall_count=1)`.
Add ONLY a walls surface. Do not add ceiling/baseboards/doors unless asked.

## Auto-Calculation Reference Table

When creating surfaces, quantities are auto-calculated from area dimensions. This table shows exactly how:

| Category | Auto-Calc Source | Formula | Unit | Default if no dimensions |
|---|---|---|---|---|
| `walls` / `body` | `wall_sqft` | `perimeter_lnft × height` | sqft | 0 |
| `ceiling` | `ceiling_sqft` | `length × width` | sqft | 0 |
| `eaves` | `ceiling_sqft` | `length × width` | sqft | 0 — **WARNING: most eaves rates are lnft_hr, not sqft_hr. Set `quantity_override` if using an lnft rate, or use/create a sqft_hr eaves rate for flat porch ceilings.** |
| `baseboard` / `trim` / `fascia` | `perimeter_lnft` | `2 × (length + width) × (wall_count / 4)` | lnft | 0 |
| `doors` / `exterior_doors` | count | Manual — set `quantity_override` | unit | 1 |
| `windows` | count | Manual — set `quantity_override` | unit | 1 |

**Key insight:** For template-based areas, the template already has dimensions for S/M/L sizes. You don't need to think about these formulas — just pick the template and size.

**For custom areas:** The AI service auto-calculates wall_sqft, ceiling_sqft, and perimeter_lnft from (length, width, height, wall_count). You only need to set `quantity_override` for doors and windows.

## Unit Mismatch — Think Before You Build

**Before adding ANY surface, compare the production rate's unit type against the auto-calc source unit.** If they don't match, auto-calc will produce 0 or a wrong number. You must set `quantity_override` with a value in the rate's actual unit.

Known mismatches to watch for:
- **Eaves**: Auto-calc gives `ceiling_sqft` (sqft), but most eaves rates use `lnft_hr`. For eaves measured in linear feet, set `quantity_override` to the perimeter or measured linear footage. For flat porch ceilings measured in sqft, look for a `sqft_hr` eaves rate or create one.
- **Trim on exteriors**: Some trim work is measured in lnft (railings, downspouts), some in units (shutters). Match the rate type to the actual measurement.

**The rule:** If the rate says `lnft_hr` but the surface auto-calculates in `sqft`, or vice versa — STOP. Either find a rate with the correct unit type, or set `quantity_override` with the value converted to the rate's unit.

**If you're unsure whether a surface should be measured in sqft or lnft**, think about what a painter would measure on the job:
- Flat expanses (walls, ceilings, siding) → sqft
- Linear runs (baseboards, fascia, railings, eaves along a roofline) → lnft
- Counted items (doors, windows, cabinet faces) → units
