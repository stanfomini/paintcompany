# Production Rates

Production rates define painter speed only. Labor cost comes from CompanySetting (effective hourly rate). Material cost comes from PaintProduct.

**IMPORTANT: The system has 125+ pre-loaded production rates covering every common surface type for both interior and exterior work.** Always call `list_production_rates_ai_tool` first. You will almost always find an existing rate that fits. Only create new rates for truly unusual work (venetian plaster, specialty epoxy, etc.).

## The 5 Rate Types

| Rate Type | Meaning | Labor Hours Formula | Use When |
|---|---|---|---|
| `sqft_hr` | sqft per hour | quantity / rate | Flat surfaces in sqft |
| `lnft_hr` | lnft per hour | quantity / rate | Linear surfaces in lnft |
| `units_hr` | units per hour | quantity / rate | Items done in <1 hr each |
| `hours_per_unit` | hours per unit | quantity × rate | Items taking 1+ hr each |
| `cost_per_lnft` | cost per lnft | N/A (cost-based) | Bundled material+labor per foot |

**`units_hr` vs `hours_per_unit`:** Formula is inverted. 4 windows/hr → 12 windows = 3 hrs. 1 hr/door → 3 doors = 3 hrs. Getting this backwards produces wildly wrong estimates.

## Rate-to-Category Mapping

| Rate surface_category | Available for | Scope |
|---|---|---|
| `walls` | walls | interior |
| `ceiling` | ceiling | interior |
| `baseboard` | baseboard | interior |
| `trim` | crown, chair rail, casing, cabinets, shelving | interior |
| `doors` | doors | interior |
| `windows` | windows | both |
| `prep` | prep | both |
| `body` | siding, stucco, masonry | exterior |
| `eaves` | soffits, overhangs | exterior |
| `fascia` | fascia | exterior |
| `exterior_doors` | exterior doors | exterior |
| `wood_replacement` | wood replacement | exterior |

Rate `surface_category` MUST match the surface's `surface_category` or it won't appear in the dropdown.

## Non-Standard Items Category Mapping

| Item | Category | Why |
|---|---|---|
| Cabinets (door faces, drawer fronts, box interiors) | `trim` | Detailed unit work |
| Shelving, closet shelving | `trim` | Linear/unit work |
| Fireplace mantel, built-in bookcase | `trim` | Decorative woodwork |
| Stair railings, spindles | `trim` | Decorative woodwork |
| Wainscoting, accent wall | `walls` | Flat sqft surface |
| Shutters (interior) | `trim` | Unit-counted woodwork |
| Garage floor (epoxy) | `prep` or `walls` | Area-based sqft work |

Rule: detailed woodwork in units/lnft → `trim`. Flat area in sqft → `walls`/`body`/`ceiling`/`eaves`.

## Coat Multipliers

1 coat = 1.0x, 2 coats = 1.8x, 3 coats = 2.5x. Auto-applied. Defaults set automatically on new rates.

## Reference Rates

**Always check `list_production_rates_ai_tool` first** for company-customized rates. Use mid-range as default. Adjust up for simple/same-color, down for detailed/color-change.

### Interior

| Surface | Rate | Type |
|---|---|---|
| Smooth walls (same color) | 180-250 sqft/hr | sqft_hr |
| Smooth walls (color change) | 100-150 sqft/hr | sqft_hr |
| Textured walls (same color) | 200-300 sqft/hr | sqft_hr |
| Textured walls (color change) | 120-180 sqft/hr | sqft_hr |
| Ceiling (same color) | 100-175 sqft/hr | sqft_hr |
| Ceiling (color change) | 50-100 sqft/hr | sqft_hr |
| Baseboards (same color) | 30-50 lnft/hr | lnft_hr |
| Baseboards (color change) | 20-35 lnft/hr | lnft_hr |
| Crown molding | 15-30 lnft/hr | lnft_hr |
| Doors — 1 side (same color) | 0.75-1.5 hrs/door | hours_per_unit |
| Doors — both sides | 1.5-2.5 hrs/door | hours_per_unit |
| Windows (trim + sill) | 2-4 units/hr | units_hr |
| Cabinets (per door/drawer face) | 0.5-1 hrs/face | hours_per_unit |
| Cabinet box interior | 1-2 hrs/box | hours_per_unit |
| Full kitchen cabinets (10-15 doors) | 16-24 hrs total | hours_per_unit |
| Bathroom vanity | 2-4 hrs/unit | hours_per_unit |
| Stair railing + spindles | 0.5-1 hrs/lnft | hours_per_unit |
| Stair spindles only | 8-15 units/hr | units_hr |
| Fireplace mantel | 1-3 hrs/unit | hours_per_unit |
| Built-in bookcase | 2-6 hrs/unit | hours_per_unit |
| Wainscoting | 40-80 sqft/hr | sqft_hr |
| Shutters (interior, per pair) | 0.5-1 hrs/pair | hours_per_unit |
| Accent wall (solid) | 150-200 sqft/hr | sqft_hr |
| Accent wall (faux finish) | 30-60 sqft/hr | sqft_hr |
| Closet shelving system | 1-2 hrs/closet | hours_per_unit |
| Garage walls | 150-250 sqft/hr | sqft_hr |

### Exterior

**Each exterior surface type has its own `surface_category`. Do NOT put fascia under `trim` or doors under `body`.**

| Surface | Category | Rate | Type | Quantity |
|---|---|---|---|---|
| Siding — spray | `body` | 150-250 sqft/hr | sqft_hr | auto (wall_sqft) |
| Siding — brush/roll | `body` | 75-125 sqft/hr | sqft_hr | auto (wall_sqft) |
| Fascia | `fascia` | 30-50 lnft/hr | lnft_hr | auto (perimeter_lnft) |
| Eaves/soffits (linear) | `eaves` | 15-75 lnft/hr | lnft_hr | **SET quantity_override** — auto-calc is ceiling_sqft which is sqft, not lnft |
| Porch ceiling (flat) | `eaves` | 100-175 sqft/hr | sqft_hr | auto (ceiling_sqft) — create this rate if needed |
| Exterior trim (downspouts, posts) | `trim` | 20-50 lnft/hr | lnft_hr | auto (perimeter_lnft) |
| Exterior windows | `windows` | 1-6 units/hr | units_hr | **OVERRIDE required** |
| Exterior doors | `exterior_doors` | 0.4-4 units/hr | units_hr | **OVERRIDE required** |
| Deck staining (horizontal) | `body` | 75-150 sqft/hr | sqft_hr | auto |
| Deck railing | `trim` | 15-30 lnft/hr | lnft_hr | auto |
| Fence (solid panel) | `body` | 100-200 sqft/hr | sqft_hr | auto |
| Fence (picket) | `body` | 50-100 sqft/hr | sqft_hr | auto |
| Pergola/arbor | `body` | 30-60 sqft/hr | sqft_hr | auto |
| Wood replacement | `wood_replacement` | varies | cost_per_lnft | **OVERRIDE required** |

### Exterior Category Mapping — Do Not Confuse

| Item | Correct Category | Wrong Category |
|---|---|---|
| Fascia boards | `fascia` | ~~`trim`~~ |
| Eaves, soffits, overhangs | `eaves` | ~~`ceiling`~~ |
| Front door, garage service door | `exterior_doors` | ~~`doors`~~ |
| Siding, stucco, masonry | `body` | ~~`walls`~~ |
| Window frames/trim | `windows` | ~~`trim`~~ |
| General exterior trim, downspouts | `trim` | (correct) |

## Intelligent Rate Research — Think Like an Estimator

When you need a production rate, don't just grab the first match. **Think about what the painter is actually doing**, then find or build the right rate.

### Research Hierarchy

1. **Search the correct category first** — `list_production_rates_ai_tool(scope, category)`. Most common surfaces are already in the system.
2. **If no exact match, scan ALL rates for the scope** — `list_production_rates_ai_tool(scope)` with no category filter. The rate might exist under a different category name than you expected.
3. **If still no match, think about the work** — What is the painter physically doing? Is it closer to linear-foot trim work or square-foot surface work? How fast can a painter reasonably do this? Check the Reference Rates table above for comparable work.
4. **Then web search** — Research production rates for this specific task. Cross-reference multiple sources.
5. **Create the rate** — with the correct category, unit type, and a researched value. Explain your reasoning.

### Verify Before Using

**When you select a rate, verify it makes sense for the surface:**
- Does the rate's **unit type** match how this surface should be measured? (See Unit Mismatch section in `04-surface-building-guide.md`)
- Does the rate's **speed** produce reasonable labor hours? Quick sanity check: quantity ÷ rate = hours. Does that feel right for the job?
- Does the rate's **category** match the surface category you need? If not, the system won't connect them.

**If something looks wrong — a surface shows $0, or the hours seem way off — stop and diagnose.** Don't deliver a broken estimate. Check: is the quantity 0? Is the rate type mismatched? Is the production rate unreasonably fast or slow?

### When the User Directs You to a Rate

If a user says "use the eaves rate for this" or "put it under trim", follow their direction BUT verify:
- Does the rate's unit type work for this surface? If not, explain: "That rate measures in lnft but this surface calculates in sqft — I'll set a quantity override so the math works."
- Does the category assignment make sense? If it's a stretch, mention it: "I'm using the trim category for railings since there's no dedicated railings category."
- **Never silently apply a rate that produces 0 quantity or wrong units.** Always catch and fix it.

### Common Surfaces That May Need New Rates

If a user requests one of these and no exact match exists, research and create:

| Surface | Category | Rate Type | Typical Range | Notes |
|---|---|---|---|---|
| Porch Railings (same color) | `trim` | `lnft_hr` | 30-40 lnft/hr | Spindle-style wood railings |
| Porch Railings (separate color) | `trim` | `lnft_hr` | 15-25 lnft/hr | Different color from body |
| Porch Ceiling / Beadboard | `eaves` | `sqft_hr` | 100-175 sqft/hr | Flat overhead porch ceiling |
| Deck Railing | `trim` | `lnft_hr` | 25-35 lnft/hr | Exterior deck railing |
| Metal Railing | `trim` | `lnft_hr` | 20-30 lnft/hr | Iron/aluminum railing |
| Garage Floor (epoxy) | `walls` | `sqft_hr` | 40-80 sqft/hr | Specialty coating |

Don't wait for the user to notice a missing rate — if the work clearly needs one and none exists, create it proactively using researched values.
