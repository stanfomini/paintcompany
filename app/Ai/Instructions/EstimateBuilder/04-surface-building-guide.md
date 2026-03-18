# Surface Building Guide — The Complete Decision Framework

## Why Surfaces Matter

Every surface you add to an area represents a line item of work. The surface is the fundamental unit of pricing in this system. Getting surfaces right is the single most important thing you do.

Here is what each surface field controls:

- **Surface category** determines which production rates appear in the dropdown AND how quantity is auto-calculated from room dimensions. If the category is wrong, everything downstream is wrong.
- **Production rate** determines how long the work takes (labor hours). Wrong rate = wrong hours = wrong price.
- **Quantity source** determines how much surface area is being painted. Wrong source = wrong quantity = wrong price.
- **Name** is what the customer sees on their proposal. A generic or incorrect name looks unprofessional and confuses the customer about what they are paying for.
- **Coats** affects labor hours via the coat multiplier. More coats = more time = higher price.

If any of these are wrong, the price is wrong and the estimate looks unprofessional. Follow the steps below every time you add a surface.

---

## Step 1: Determine the Surface Category

The surface category is the foundation of everything. It controls which production rates appear in the dropdown, how the quantity is auto-calculated, and what unit type the surface uses. You must choose the correct category FIRST, before doing anything else.

Use this decision table. Find what you are painting in the left column, then use the correct category for the scope:

| What you are painting | Category (interior) | Category (exterior) |
|---|---|---|
| Walls, accent walls, wainscoting, garage walls | `walls` | — |
| Siding, stucco, masonry, house body, brick (painting over) | — | `body` |
| Ceiling, vaulted ceiling, popcorn ceiling | `ceiling` | — |
| Soffits, eaves, overhangs | — | `eaves` |
| Baseboards | `baseboard` | — |
| Crown molding, chair rail, window casing, general trim | `trim` | `trim` |
| Fascia boards | — | `fascia` |
| Interior doors (standard, closet, french, bi-fold) | `doors` | — |
| Exterior doors (front door, garage service door, storm door) | — | `exterior_doors` |
| Windows (vinyl, wood, bay, casement) | `windows` | `windows` |
| Cabinets, shelving, fireplace mantel, stair railing, spindles, built-in bookcase | `trim` | — |
| Prep work (masking, caulking, sanding, priming, patching) | `prep` | `prep` |
| Wood replacement (rot repair, board replacement) | — | `wood_replacement` |

**CRITICAL — These are NOT valid surface categories:**
- `"crown"` — Use `"trim"` instead. Crown molding is a type of trim.
- `"molding"` — Use `"trim"` instead. All moldings are categorized as trim.
- `"siding"` — Use `"body"` instead. Siding is the exterior body surface.
- `"baseboard"` is only valid for interior. Exterior does not have baseboards.
- `"soffit"` — Use `"eaves"` instead.

If the item you are painting does not appear in this table, find the closest match. Cabinets, shelving, fireplace mantels, stair railings, and spindles all use `"trim"` because they are detailed work similar to trim. Wainscoting uses `"walls"` because it is a wall-like surface measured in sqft.

---

## Step 2: Find the Right Production Rate

Once you know the surface category, you need a production rate that matches. The production rate defines how fast a painter works on this specific surface type.

**How to find the right rate:**

1. Call `list_production_rates_ai_tool` filtered by scope and the surface_category you chose in Step 1.

2. Review the results and pick the rate that best matches the job description.

3. If no matching rate exists, follow the Lookup-or-Create Pattern: research the correct rate value (use web search for "painting production rate [surface type]"), create it with `create_production_rate_ai_tool`, explain your reasoning to the user, then use the new rate.

**Example — adding baseboards to an interior room:**

1. Call: `list_production_rates_ai_tool(scope_type="interior", category="baseboard")`
2. You might see results like:
   - "Baseboards same color 1x2" — 40 lnft/hr
   - "Baseboards change color" — 25 lnft/hr
   - "Baseboards stain-to-paint" — 15 lnft/hr
3. If the customer wants same-color baseboards, pick "Baseboards same color 1x2" at 40 lnft/hr.
4. If no baseboard rate exists at all, create one: research shows standard baseboard painting is 30-50 lnft/hr for same color. Create at 40 lnft/hr as a reasonable middle value.

**The most common error is a category mismatch.** If you set the surface category to `"walls"` but then search for baseboard rates, you will not find any because baseboard rates have `surface_category="baseboard"`. The rate's `surface_category` MUST match the surface's `surface_category`. Always search with the same category you assigned to the surface.

---

## Step 3: Determine the Quantity Source

The quantity source tells the system WHERE to get the measurement for this surface. Most surfaces derive their quantity automatically from the area's dimensions. Some require a manual count.

| Surface Category | Default Quantity Source | Unit | How the quantity is calculated |
|---|---|---|---|
| `walls` | `wall_sqft` | sqft | Auto from area dimensions: `perimeter_lnft * height`. Adjusted by wall_count. |
| `body` | `wall_sqft` | sqft | Auto from area dimensions: `perimeter_lnft * height`. Adjusted by wall_count. |
| `ceiling` | `ceiling_sqft` | sqft | Auto from area dimensions: `length * width`. |
| `eaves` | `ceiling_sqft` | sqft | Auto from area dimensions: `length * width`. Eaves/soffits cover the overhead area matching the ceiling footprint. |
| `baseboard` | `perimeter_lnft` | lnft | Auto from area dimensions: `2 * (length + width) * (wall_count / 4)`. |
| `trim` | `perimeter_lnft` | lnft | Auto from area perimeter. For linear trim items like crown molding, chair rail, window casing. |
| `fascia` | `perimeter_lnft` | lnft | Auto from area perimeter. |
| `doors` | `door_count` | units | Manual count — you must set `quantity_override`. Default is 1. |
| `exterior_doors` | `door_count` | units | Manual count — you must set `quantity_override`. Default is 1. |
| `windows` | `window_count` | units | Manual count — you must set `quantity_override`. Default is 1. |

**When to use `custom` quantity source:**

Use `custom` when the surface does not derive its quantity from room dimensions. You MUST set `quantity_override` when using custom. Examples:

- **Cabinet door faces** — 14 faces is not a dimension of the room. Use custom, quantity_override=14.
- **Stair spindles** — 22 spindles is a count of individual items. Use custom, quantity_override=22.
- **Fireplace mantel** — 1 unit. Use custom, quantity_override=1.
- **Customer-provided measurements** — If the customer says "I measured the walls at 380 sqft" and that does not match the calculated wall_sqft from dimensions, use custom with their measurement.
- **Any counted item that is not a standard door or window** — Outlet covers, light fixtures, shelves, drawer fronts, etc.

**Note for trim-category surfaces:** When trim is measured in linear feet (crown molding, chair rail), use `perimeter_lnft` as the quantity source. When trim refers to a counted item (cabinet faces, spindles), use `custom` with a quantity_override. The surface_category is `"trim"` in both cases — only the quantity source differs.

---

## Step 4: Set the Quantity Override (when needed)

- **If quantity source is `wall_sqft`, `ceiling_sqft`, or `perimeter_lnft`:** The system auto-calculates the quantity from area dimensions. You do NOT need to set quantity_override unless the user provides a specific measurement that differs from the calculated value.

- **If quantity source is `door_count` or `window_count`:** You MUST set `quantity_override` to the actual count. Example: 3 doors = quantity_override of 3. If you do not set it, it defaults to 1.

- **If quantity source is `custom`:** You MUST set `quantity_override` to the measurement or count. Example: 14 cabinet door faces = quantity_override of 14. There is no default — the surface will have zero quantity if you forget this.

---

## Step 5: Give It a Descriptive Name

The surface name appears on the area card, in the expanded cost breakdown, and on the customer proposal that the homeowner reads. It must be clear, professional, and specific enough that the customer understands exactly what they are paying for.

**Naming Rules:**

1. **Title Case always.** "Crown Molding" not "crown molding". "Cabinet Door Faces" not "cabinet door faces".

2. **Use the production rate name as a starting point.** If you selected the rate "Baseboards same color 1x2", name the surface "Baseboards". You can simplify the rate name for the customer — they do not need to know "1x2" or "same color".

3. **Add detail when there are multiple surfaces of the same category.** If an area has two `trim` category surfaces, do NOT name them both "Trim". Name them specifically: "Crown Molding" and "Chair Rail". Or "Baseboards" and "Window Casing". Each surface must have a unique, descriptive name within its area.

4. **Name specialty surfaces by what they are.** Do not use the generic category name. "Cabinet Door Faces" not "Trim". "Stair Spindles" not "Trim". "Deck Railing" not "Trim". "Fireplace Mantel" not "Trim". The customer should immediately understand what surface is being painted.

5. **Include application method when relevant.** If an area has the same surface type applied two different ways, differentiate: "Walls - Spray" vs "Walls - Brush & Roll". Only do this when the area actually has both methods on the same surface type.

6. **Include count when greater than 1 for counted items.** "Interior Doors (3)" not just "Interior Doors". This helps the customer verify the count is correct.

7. **Never use internal codes, database IDs, or generic fallbacks.** "Walls" is fine for a single wall surface. "sqft_surface_1" or "Surface #3" is never acceptable.

**Good vs Bad Naming Examples:**

| Bad | Good | Why |
|---|---|---|
| Walls, Walls | Walls - Brush & Roll, Accent Wall | Two wall surfaces in one area need differentiation |
| Trim | Crown Molding | Be specific about what type of trim |
| Trim | Baseboards | Be specific about what type of trim |
| Trim, Trim, Trim | Baseboards, Crown Molding, Window Casing | Each trim type deserves its own descriptive name |
| Doors | Interior Doors (3) | Include count when more than 1 so the customer can verify |
| Other | Cabinet Door Faces | Name exactly what you are painting |
| hallway | Main Floor Hallway | Use Title Case and be specific about location |
| bath 2 | Guest Bathroom | Use descriptive, customer-friendly names — not abbreviations or numbers |
| Exterior | Body/Siding | Name the actual surface, not the scope |
| Walls | Garage Walls | When an area might be ambiguous, add context |

---

## Step 6: Set Coats

Default to **2 coats** unless the user specifies otherwise or the production rate has a different default_coats value.

**Coat multipliers adjust labor hours automatically:**
- 1 coat = 1.0x base time
- 2 coats = 1.8x base time (NOT 2.0x — the first coat includes prep and cutting in, so the second coat is faster)
- 3 coats = 2.5x base time

These multipliers are stored on the ProductionRate's `coat_multipliers` JSON field and are applied automatically when calculating labor hours.

**When to use different coat counts:**
- **1 coat:** Touch-ups, same-color refreshes, primer-only, some prep work
- **2 coats:** Standard painting (the default for nearly all jobs)
- **3 coats:** Dark-to-light color changes, covering bold colors (red, deep blue), new drywall that needs extra coverage

**Note on application_method:** Each surface has an `application_method` field — `"brush_roll"` (default) or `"spray"`. This does NOT affect labor hours directly (that is handled by choosing the right production rate). It DOES affect material cost calculations because spray application uses more paint per sqft than brush/roll. Set this to `"spray"` for exterior body spray jobs, cabinet spray jobs, or any surface where the painter will spray instead of brush/roll.

---

## Complete Examples

These examples show the full decision process from user request to surface creation. Follow this same pattern for every surface you build.

### Example 1: Standard Interior Room — "Add a medium living room"

This is the simplest case. Use a template when one exists.

1. **Search templates:** Call `search_templates_ai_tool("living room")`
2. **Create from template:** Call `create_area_from_template_ai_tool(template_id=X, size="medium")`
3. **Template auto-creates surfaces** with correct categories, rates, and quantity sources:
   - "Walls" — category: `walls`, rate: Textured Walls same color (200 sqft/hr), quantity_source: `wall_sqft`, coats: 2
   - "Ceiling" — category: `ceiling`, rate: Ceilings textured same color (175 sqft/hr), quantity_source: `ceiling_sqft`, coats: 2
   - "Baseboards" — category: `baseboard`, rate: Baseboards same color 1x2 (40 lnft/hr), quantity_source: `perimeter_lnft`, coats: 2
   - "Doors" — category: `doors`, rate: Doors same color 1 side (1 hr/unit), quantity_source: `door_count`, quantity_override: 1, coats: 2
4. **Tell the user:** "I added a medium Living Room (14x12x9) with walls, ceiling, baseboards, and 1 door. Total: $XXX."

### Example 2: Kitchen with Custom Surfaces — "Add a kitchen, 12x14, include cabinets"

This mixes standard surfaces with custom cabinet surfaces.

1. **Create custom area:** `create_custom_area_ai_tool(name="Kitchen", scope_type="interior", length=12, width=14, height=9)`
   - System auto-calculates: wall_sqft = 468, ceiling_sqft = 168, perimeter_lnft = 52

2. **Add standard surfaces:**
   - Walls: category `walls`, rate: Textured Walls same color, quantity_source: `wall_sqft` (auto = 468 sqft), coats: 2. Name: "Walls"
   - Ceiling: category `ceiling`, rate: Ceilings textured same color, quantity_source: `ceiling_sqft` (auto = 168 sqft), coats: 2. Name: "Ceiling"
   - Baseboards: category `baseboard`, rate: Baseboards same color, quantity_source: `perimeter_lnft` (auto = 52 lnft), coats: 2. Name: "Baseboards"

3. **Add custom surfaces (cabinets):**
   - First, find a cabinet rate: `list_production_rates_ai_tool(scope_type="interior", category="trim")`. Look for cabinet-specific rates.
   - If a "Cabinet Door Faces" rate exists (e.g., 0.75 hrs/face), use it.
   - If no cabinet rate exists, create one: `create_production_rate_ai_tool(name="Cabinet Door Faces", scope="interior", surface_category="trim", production_rate=0.75, rate_type="hours_per_unit", unit_type="unit", default_coats=2)`. Explain: "I created a cabinet door face rate at 0.75 hrs/face based on industry standards for brush/roll cabinet work."
   - Cabinet Door Faces: category `trim`, rate: Cabinet Door Faces (0.75 hrs/unit), quantity_source: `custom`, quantity_override: 14, coats: 2. Name: "Cabinet Door Faces"
   - Cabinet Drawer Fronts: category `trim`, rate: Cabinet Drawer Fronts (0.5 hrs/unit), quantity_source: `custom`, quantity_override: 8, coats: 2. Name: "Cabinet Drawer Fronts"

4. **Tell the user:** "I added a Kitchen (12x14x9) with walls, ceiling, baseboards, 14 cabinet door faces, and 8 drawer fronts. Total: $XXX."

### Example 3: Full House Exterior — "Add the house exterior"

Exterior estimates use different categories and have more surface types.

1. **Search templates:** `search_templates_ai_tool("house exterior")`
2. **Create from template or custom area.** Either way, the area needs these surfaces:
   - "Body/Siding" — category: `body`, rate: Body spray (200 sqft/hr), quantity_source: `wall_sqft`, coats: 2, application_method: `spray`. Name: "Body/Siding"
   - "Fascia" — category: `fascia`, rate: Fascia 1 story (40 lnft/hr), quantity_source: `perimeter_lnft`, coats: 2. Name: "Fascia"
   - "Eaves/Soffits" — category: `eaves`, rate: Eaves body color (50 lnft/hr), quantity_source: `ceiling_sqft`, coats: 2. Name: "Eaves/Soffits"
     - **Note on eaves quantity source:** Eaves use `ceiling_sqft` even though the production rate is in lnft/hr. This is because soffits cover the overhead area that matches the ceiling/roof footprint. The system handles the unit conversion.
   - "Exterior Trim" — category: `trim`, rate: Trim 1 story (25 lnft/hr), quantity_source: `perimeter_lnft`, coats: 2. Name: "Exterior Trim"
   - "Front Door" — category: `exterior_doors`, rate: Ext Door body color 1 side (1 hr/unit), quantity_source: `door_count`, quantity_override: 1, coats: 2. Name: "Front Door"
   - "Windows" — category: `windows`, rate: Vinyl windows (4 units/hr), quantity_source: `window_count`, quantity_override: 12, coats: 2. Name: "Windows (12)"

3. **Tell the user:** "I added the full house exterior with body/siding, fascia, eaves, trim, front door, and 12 windows. Total: $XXX."

### Example 4: Front of House Only — "Paint just the front, 45ft wide, 12ft tall"

This is a single-face estimate. The customer only wants the front of the house painted, not the entire exterior.

1. **Create custom area:** `create_custom_area_ai_tool(name="Front of House", scope_type="exterior", length=0, width=45, height=12, wall_count=1)`
   - `length=0` because there is no depth — this is a single flat face.
   - `wall_count=1` tells the system this is one face, not a full-perimeter estimate.
   - System calculates: wall_sqft based on the single face (~540 sqft), perimeter_lnft reduced to 25% of full perimeter.

2. **Add surfaces for just the front face:**
   - Body/Siding: category `body`, quantity_source: `wall_sqft`, coats: 2. Name: "Front Siding"
   - Fascia: category `fascia`, quantity_source: `perimeter_lnft`. Name: "Front Fascia"
   - Front Door: category `exterior_doors`, quantity_override: 1. Name: "Front Door"
   - Windows: category `windows`, quantity_override based on how many windows are on the front. Name: "Front Windows (6)"

3. **Tell the user:** "I created a front-of-house estimate at 45ft wide by 12ft tall with siding, fascia, front door, and 6 windows. Total: $XXX."

### Example 5: Partial Room — "Paint 3 walls, the 4th is brick"

The customer has a room where one wall should not be painted. Use wall_count to handle this automatically.

1. **Create custom area:** `create_custom_area_ai_tool(name="Living Room", scope_type="interior", length=18, width=14, height=9, wall_count=3)`
   - `wall_count=3` means 75% of the full room's perimeter and wall area are calculated.
   - System auto-adjusts: perimeter_lnft = 75% of `2*(18+14)` = 48 lnft. wall_sqft = 48 * 9 = 432 sqft. ceiling_sqft = 18*14 = 252 sqft (ceiling is NOT affected by wall_count).

2. **Add surfaces normally — quantities auto-adjust to the 3-wall values:**
   - Walls: category `walls`, quantity_source: `wall_sqft` (auto = 432 sqft). Name: "Walls"
   - Ceiling: category `ceiling`, quantity_source: `ceiling_sqft` (auto = 252 sqft). Name: "Ceiling"
   - Baseboards: category `baseboard`, quantity_source: `perimeter_lnft` (auto = 48 lnft). Name: "Baseboards"
   - Door: category `doors`, quantity_override: 1. Name: "Interior Door"

3. **Tell the user:** "I added the Living Room with 3 walls (the brick wall is excluded). Wall area is 432 sqft, ceiling is 252 sqft, 48 lnft of baseboards, and 1 door. Total: $XXX."

### Example 6: Single Accent Wall — "I need to paint an accent wall, it's 14 feet wide and 10 feet tall"

The simplest possible estimate — a single wall with no ceiling, no baseboards, no doors.

1. **Create custom area:** `create_custom_area_ai_tool(name="Accent Wall", scope_type="interior", length=0, width=14, height=10, wall_count=1)`
   - `length=0` because there is no room depth — this is a single flat surface.
   - `wall_count=1` for a single wall.
   - System calculates: wall_sqft = 14 * 10 = 140 sqft. ceiling_sqft = 0 (no length, so no ceiling area).

2. **Add just the walls surface — do NOT add ceiling, baseboards, or doors unless the user specifically asks for them:**
   - Walls: category `walls`, quantity_source: `wall_sqft` (auto = 140 sqft), coats: 2. Name: "Accent Wall"

3. **Tell the user:** "I added a 14x10 accent wall at 140 sqft with 2 coats. Total: $XXX."

---

## Quick Reference — Surface Category Cheat Sheet

When you are in the middle of building and need a fast lookup:

**Interior surfaces:**
- Walls, accent walls, wainscoting → `walls` → `wall_sqft`
- Ceiling → `ceiling` → `ceiling_sqft`
- Baseboards → `baseboard` → `perimeter_lnft`
- Crown molding, chair rail, window casing → `trim` → `perimeter_lnft`
- Cabinets, shelving, fireplace, spindles → `trim` → `custom` (with quantity_override)
- Standard doors → `doors` → `door_count` (with quantity_override)
- Windows → `windows` → `window_count` (with quantity_override)
- Prep work → `prep` → varies

**Exterior surfaces:**
- Siding, stucco, masonry → `body` → `wall_sqft`
- Soffits, eaves → `eaves` → `ceiling_sqft`
- Fascia → `fascia` → `perimeter_lnft`
- Exterior trim → `trim` → `perimeter_lnft`
- Front/garage/storm doors → `exterior_doors` → `door_count` (with quantity_override)
- Windows → `windows` → `window_count` (with quantity_override)
- Wood replacement → `wood_replacement` → `perimeter_lnft`
- Prep work → `prep` → varies

**Never valid as surface_category:** `crown`, `molding`, `siding`, `soffit`, `baseboard` (exterior). Use the correct category from the tables above.
