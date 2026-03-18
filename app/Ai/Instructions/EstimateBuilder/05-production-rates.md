# Production Rates — Everything You Need to Know

## What Production Rates Are

Production rates define how fast a professional painter works on a specific surface type. They are purely speed data — they tell you how many square feet per hour, linear feet per hour, or how many hours per unit a painter can complete. They do NOT contain pricing information. Pricing comes from two other sources:

- **Labor cost** comes from CompanySetting (effective hourly rate = base wage x payroll burden multiplier)
- **Material cost** comes from PaintProduct (price per gallon, coverage per gallon)

A production rate answers one question: "How long does this work take?" That's it. Everything else — what the painter earns, what the paint costs, what the customer pays — comes from other models.

## The 5 Rate Types

Every production rate has a `rate_type` that determines how labor hours are calculated from the surface's quantity.

| Rate Type | Meaning | Formula for labor hours | When to use | Example |
|---|---|---|---|---|
| `sqft_hr` | Square feet per hour | quantity / rate | Flat surfaces measured in square feet | Walls (200 sqft/hr), ceilings (100 sqft/hr) |
| `lnft_hr` | Linear feet per hour | quantity / rate | Long narrow surfaces measured in linear feet | Baseboards (40 lnft/hr), crown molding (20 lnft/hr) |
| `units_hr` | Units per hour | quantity / rate | Discrete items completed quickly (less than 1 hour each) | Vinyl windows (4/hr), outlet covers (20/hr) |
| `hours_per_unit` | Hours per unit | quantity x rate | Discrete items that take a long time (1 hour or more each) | Doors (1 hr/door), cabinet boxes (2 hr/each) |
| `cost_per_lnft` | Cost per linear foot | N/A (cost-based, not time-based) | Material + labor bundled together as a per-foot cost | Wood replacement ($6.84/lnft) |

### Decision Tree for Choosing Rate Type

When creating a new production rate, use this decision tree:

1. Is the surface measured in area (square feet)? → **sqft_hr**
2. Is the surface measured in length (linear feet)? → **lnft_hr**
3. Is the surface counted as individual items?
   - Can a painter finish one in less than 1 hour? → **units_hr**
   - Does one item take 1 hour or more? → **hours_per_unit**
4. Is it a bundled material + labor cost per foot? → **cost_per_lnft**

**Important distinction between `units_hr` and `hours_per_unit`:**
- `units_hr` = "a painter does 4 windows per hour" → 12 windows / 4 per hour = 3 hours
- `hours_per_unit` = "a door takes 1 hour each" → 3 doors x 1 hour each = 3 hours
- Both produce labor hours, but the formula is inverted. Getting this backwards will produce wildly wrong estimates.

## How Rates Connect to Surface Categories

Every production rate has a `surface_category` field. Every surface on an area also has a `surface_category` field. **These must match for the rate to be usable on that surface.** In the area builder UI, the production rate dropdown is filtered by the surface's category — only rates with a matching `surface_category` appear.

| Rate surface_category | Available for surfaces with category | Scope |
|---|---|---|
| `walls` | walls | interior |
| `ceiling` | ceiling | interior |
| `baseboard` | baseboard | interior |
| `trim` | trim (crown molding, chair rail, window casing, cabinets, shelving) | interior |
| `doors` | doors | interior |
| `windows` | windows | both interior and exterior |
| `prep` | prep | both interior and exterior |
| `body` | body (siding, stucco, masonry, house body) | exterior |
| `eaves` | eaves (soffits, overhangs) | exterior |
| `fascia` | fascia | exterior |
| `exterior_doors` | exterior_doors | exterior |
| `wood_replacement` | wood_replacement | exterior |

**This is the most common source of errors.** If you pick the wrong surface category, the wrong production rates appear in the dropdown. For example:
- You create a surface with category "walls" but you're trying to find a baseboard rate → no baseboard rates will appear because they have surface_category "baseboard", not "walls"
- You create a surface with category "trim" for exterior siding → no siding rates will appear because they have surface_category "body", not "trim"
- You create a surface with an invalid category like "crown" or "siding" → no rates will appear because those aren't valid categories

Always use the exact category values from the table above. There are no others.

## Surface Category Mapping for Non-Standard Items

When you encounter a surface type that isn't obviously one of the standard categories, use the closest matching category. Here's the mapping for common non-standard items:

| Non-Standard Item | Correct Category | Why |
|---|---|---|
| Cabinets (door faces, drawer fronts) | `trim` | Detailed work, uses hours_per_unit |
| Cabinet box interiors | `trim` | Detailed work, uses hours_per_unit |
| Shelving | `trim` | Linear/unit work similar to trim |
| Fireplace mantel | `trim` | Decorative woodwork |
| Stair railings | `trim` | Linear decorative woodwork |
| Stair spindles | `trim` | Unit-counted decorative work |
| Wainscoting | `walls` | Flat panel work measured in sqft |
| Accent wall | `walls` | Wall surface, just a subset |
| Closet shelving | `trim` | Unit/linear detailed work |
| Built-in bookcase | `trim` | Detailed woodwork, hours_per_unit |
| Garage floor (epoxy) | `prep` or `walls` | Area-based (sqft) work |
| Shutters (interior) | `trim` | Unit-counted woodwork |

The key principle: if it's detailed woodwork counted in units or linear feet, it's `trim`. If it's a flat area measured in square feet, it's `walls` (interior) or `body` (exterior). If it's overhead flat area, it's `ceiling` (interior) or `eaves` (exterior).

## Coat Multipliers

Every production rate has coat multipliers that adjust labor hours when multiple coats are applied. The default multipliers are:

| Coats | Multiplier | Meaning |
|---|---|---|
| 1 coat | 1.0x | Base time |
| 2 coats | 1.8x | Not double — the first coat is slower (prep, cutting in, masking), the second coat goes faster |
| 3 coats | 2.5x | Third coat is fastest (surface is already prepped and cut in) |

**Why 2 coats is NOT 2.0x:** The first coat includes all the prep work — moving furniture protection, masking, cutting in edges, and the initial application. The second coat only requires the application itself, plus maybe a light touch-up on the cut-in. So the second coat takes about 80% of the first coat's time, not 100%.

These multipliers are automatically applied when calculating labor hours. You set the number of coats on the surface, and the system multiplies labor hours by the appropriate multiplier.

When creating a new production rate with `create_production_rate_ai_tool`, coat multipliers are automatically set to the defaults above. You do not need to specify them unless you want non-standard multipliers.

## Reference Production Rates

**Always check `list_production_rates_ai_tool` first** for company-customized rates. The company may have adjusted rates to match their painters' actual speed, which could differ from industry standards. Only fall back to the reference ranges below when no existing rate matches what you need.

Use the MIDDLE of the range as your default value. Adjust upward (faster) for simple/same-color work. Adjust downward (slower) for detailed/color-change/stain-to-paint work.

### Interior Surfaces

| Surface | Rate | Type | Notes |
|---|---|---|---|
| Smooth walls (same color) | 180-250 sqft/hr | sqft_hr | Fastest interior work |
| Smooth walls (color change) | 100-150 sqft/hr | sqft_hr | Requires better coverage |
| Textured walls (same color) | 200-300 sqft/hr | sqft_hr | Texture holds paint well |
| Textured walls (color change) | 120-180 sqft/hr | sqft_hr | |
| Ceiling (same color) | 100-175 sqft/hr | sqft_hr | Overhead work = slower |
| Ceiling (color change) | 50-100 sqft/hr | sqft_hr | |
| Baseboards (same color) | 30-50 lnft/hr | lnft_hr | Detail work |
| Baseboards (color change) | 20-35 lnft/hr | lnft_hr | |
| Crown molding | 15-30 lnft/hr | lnft_hr | High detail, overhead |
| Doors — 1 side (same color) | 0.75-1.5 hrs/door | hours_per_unit | Depends on panel count |
| Doors — both sides | 1.5-2.5 hrs/door | hours_per_unit | |
| Windows (trim + sill) | 2-4 units/hr | units_hr | Standard residential windows |
| Cabinets (per door/drawer face) | 0.5-1 hrs/face | hours_per_unit | Detailed, often spray applied |
| Cabinet box interior | 1-2 hrs/box | hours_per_unit | Tight spaces, awkward angles |
| Full kitchen cabinets (10-15 doors) | 16-24 hrs total | hours_per_unit | Plan as a 2-3 day job |
| Bathroom vanity | 2-4 hrs/unit | hours_per_unit | Small cabinet set |
| Stair railing + spindles | 0.5-1 hrs/lnft | hours_per_unit | Very detailed work |
| Stair spindles only (per spindle) | 8-15 units/hr | units_hr | If just the spindles, not the railing |
| Fireplace mantel | 1-3 hrs/unit | hours_per_unit | Size varies greatly |
| Built-in bookcase | 2-6 hrs/unit | hours_per_unit | Depends on number of shelves |
| Wainscoting | 40-80 sqft/hr | sqft_hr | Panel detail slows work |
| Shutters (interior, per pair) | 0.5-1 hrs/pair | hours_per_unit | |
| Accent wall (solid color) | 150-200 sqft/hr | sqft_hr | Same as standard wall work |
| Accent wall (faux finish/texture) | 30-60 sqft/hr | sqft_hr | Specialty technique work |
| Closet shelving system | 1-2 hrs/closet | hours_per_unit | Cutting around shelves and rods |
| Garage walls | 150-250 sqft/hr | sqft_hr | Less precision needed than living areas |

### Exterior Surfaces

| Surface | Rate | Type | Notes |
|---|---|---|---|
| Siding — spray | 150-250 sqft/hr | sqft_hr | Fastest exterior method |
| Siding — brush/roll | 75-125 sqft/hr | sqft_hr | |
| Fascia | 30-50 lnft/hr | lnft_hr | |
| Eaves/soffits | 15-75 lnft/hr | lnft_hr | Overhead work = much slower |
| Exterior windows | 1-6 units/hr | units_hr | Varies widely by window type and condition |
| Exterior doors | 0.8-1.3 hrs/door | hours_per_unit | |
| Deck staining (horizontal surface) | 75-150 sqft/hr | sqft_hr | Floor surface = faster application |
| Deck railing | 15-30 lnft/hr | lnft_hr | Spindles slow it down significantly |
| Fence (solid panel) | 100-200 sqft/hr | sqft_hr | Spray is 200+, brush is 100 |
| Fence (picket) | 50-100 sqft/hr | sqft_hr | Detail work between pickets |
| Pergola/arbor | 30-60 sqft/hr | sqft_hr | 3D structure, many angles |

## Creating New Production Rates

When you search for a production rate and nothing matches, follow these 5 steps to create a new one:

### Step 1: Research

Use web search to find industry-standard production rates for the surface type. Search for queries like:
- "painting production rate [surface type] sqft per hour"
- "how long does it take to paint [surface type]"
- "professional painter [surface type] time estimate"

### Step 2: Cross-Reference

Compare what you found with the reference tables above. If the web results say "cabinet doors take 30-45 minutes each" and the reference table says "0.5-1 hrs/face", those are consistent — use a value in that range.

If the web results and reference table disagree significantly, favor the reference table (it's calibrated for professional residential painters) but mention the discrepancy to the user.

### Step 3: Create

Call `create_production_rate_ai_tool` with these required parameters:
- **`name`**: Descriptive name that identifies the specific surface and condition. Examples: "Cabinet Door Faces - Spray", "Stair Spindles - Stain to Paint", "Deck Railing - Cedar". The name should be specific enough that someone browsing a list of rates can immediately understand what it's for.
- **`scope`**: "interior" or "exterior"
- **`surface_category`**: Must match the surface category you'll assign to the surface (see the mapping tables above). This is critical — if the categories don't match, the rate won't appear in the UI dropdown.
- **`rate_type`**: One of the 5 types (sqft_hr, lnft_hr, units_hr, hours_per_unit, cost_per_lnft). Use the decision tree above.
- **`production_rate`**: The rate value. Use the middle of the reference range as your default.
- **`unit_type`**: "sqft", "lnft", "unit", or "hour" — must match the rate_type logically (sqft_hr → "sqft", lnft_hr → "lnft", units_hr → "unit", hours_per_unit → "hour", cost_per_lnft → "lnft")

### Step 4: Explain

Tell the user what you researched and why you chose those values. This builds trust and lets them correct you if their painters work at different speeds.

Example: "I researched cabinet door painting rates. Professional painters typically complete 1 cabinet door face in 30-45 minutes when spraying. I created a rate called 'Cabinet Door Faces - Spray' at 0.67 hours per unit (about 40 minutes per face). Let me know if your painters are faster or slower."

### Step 5: Use the New Rate

Now add the surface to the area using the rate ID returned from `create_production_rate_ai_tool`. The rate is permanently saved and reusable for future estimates — you only need to create it once.

## Creating Templates from New Rates

After creating production rates for something specialized, consider also creating a template so the work is reusable next time someone requests it. This saves time on future estimates and ensures consistency.

**Kitchen Cabinet Refinishing Template:**
After creating rates for cabinet door faces, drawer fronts, and cabinet box interiors, create a template that bundles all three surfaces. Next time someone says "add kitchen cabinets", you can use the template instead of building surfaces from scratch.

**Staircase Template:**
After creating rates for railing, spindles, treads, and risers, create a template. Staircases are common requests and always need the same set of surfaces.

**Deck Template:**
After creating rates for deck floor, railing, and posts, create a template with all three. Deck projects are requested frequently and benefit from a pre-built configuration.

Use `create_template_ai_tool` to save these reusable configurations. Include appropriate surfaces with correct categories, rates, and default coats.
