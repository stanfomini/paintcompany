---
name: blueprint-estimator
description: Read architectural blueprint PDFs uploaded to estimates, extract rooms/dimensions, and auto-build estimate areas with substrates, wall counts, coats, and prep details. Triggered when user uploads blueprints and asks to build out rooms.
---

# Blueprint Estimator Skill

The ultimate painting estimate builder. Reads blueprint PDFs, examines uploaded photos, processes estimator/customer comments, and creates fully detailed estimates with per-surface breakdowns, substrates, prep add-ons, closets/pantries, trim work, and accurate pricing.

## When to Use

- User uploads a blueprint PDF and asks to "read it" or "build out the rooms"
- User asks to create estimate areas from floor plans
- User wants to build an estimate from photos + comments + blueprints
- User says "fill in the rooms", "build out this estimate", "read the blueprints"

## Information Sources (Use ALL Available)

1. **Blueprint PDFs** - Room names, dimensions, floor levels, construction conditions
2. **Property photos** - Visual condition assessment (damage, existing paint, textures)
3. **Estimate comments/notes** - Estimator observations ("empty house", "heavy furniture", "level 5 finish")
4. **Customer comments** - Customer preferences ("we want ceiling painted too", "skip the closets")
5. **Agent notes** - Referral context ("fire damage restoration", "new build")
6. **Property data** - Year built, sqft, construction type from property record

---

## PHASE 1: Gather All Information

### Step 1.1: Load Estimate Context

```sql
-- Get estimate details
SELECT e.id, e.status, e.notes, e.customer_notes, e.project_type,
       p.address, p.city, p.state, p.year_built, p.square_footage,
       c.name as customer_name
FROM estimates e
JOIN deals d ON e.deal_id = d.id
JOIN properties p ON d.property_id = p.id
LEFT JOIN users c ON d.customer_id = c.id
WHERE e.id = {ESTIMATE_ID}
```

### Step 1.2: Find Blueprint PDFs

```sql
SELECT pp.id, pp.filename, pp.file_path, pp.mime_type, pp.caption, pp.category
FROM property_photos pp
JOIN deals d ON pp.property_id = d.property_id
JOIN estimates e ON e.deal_id = d.id
WHERE e.id = {ESTIMATE_ID}
AND pp.mime_type = 'application/pdf'
ORDER BY pp.created_at DESC
```

File location: `storage/app/public/{file_path}`

### Step 1.3: Extract Text from Blueprint PDF

**CRITICAL**: Use pdfplumber (lightweight). Do NOT use Ghostscript/image conversion - too resource-heavy.

```bash
/home/forge/apexnegotiations.com/python/venv/bin/python3 -c "
import pdfplumber
import json

pdf = pdfplumber.open('/home/forge/apexnegotiations.com/storage/app/public/{FILE_PATH}')
results = []
for i, page in enumerate(pdf.pages):
    text = page.extract_text() or ''
    results.append({'page': i+1, 'text': text})
    tables = page.extract_tables()
    if tables:
        results[-1]['tables'] = tables
pdf.close()
print(json.dumps(results, indent=2))
"
```

If pdfplumber is not installed:
```bash
/home/forge/apexnegotiations.com/python/venv/bin/pip install pdfplumber
```

### Step 1.4: Review Photos for Condition Assessment

```sql
SELECT pp.id, pp.filename, pp.file_path, pp.category, pp.caption,
       pp.estimate_area_id
FROM property_photos pp
JOIN deals d ON pp.property_id = d.property_id
JOIN estimates e ON e.deal_id = d.id
WHERE e.id = {ESTIMATE_ID}
AND pp.mime_type LIKE 'image/%'
ORDER BY pp.category, pp.created_at
```

**Photo categories to look for**:
- `before` - Current condition photos - informs substrate selection
- `reference` - Reference photos (what customer wants)
- `general` - Any relevant photos

Read photo files using the Read tool to visually assess:
- Paint condition (peeling, cracking, good, new)
- Wall texture (smooth, orange peel, knockdown, popcorn ceiling)
- Trim type (wood, MDF, painted, stained)
- Damage (water stains, smoke damage, holes)
- Furniture level (empty, light, heavy)

### Step 1.5: Read Comments and Notes

```sql
-- Estimate activity/comments
SELECT ea.action, ea.description, ea.created_at, u.name as by_user, u.type as user_type
FROM estimate_activities ea
LEFT JOIN users u ON ea.user_id = u.id
WHERE ea.estimate_id = {ESTIMATE_ID}
ORDER BY ea.created_at DESC

-- Photo comments
SELECT pc.body, pc.created_at, u.name, u.type as user_type, pp.filename
FROM photo_comments pc
JOIN users u ON pc.user_id = u.id
JOIN property_photos pp ON pc.property_photo_id = pp.id
JOIN deals d ON pp.property_id = d.property_id
JOIN estimates e ON e.deal_id = d.id
WHERE e.id = {ESTIMATE_ID}
ORDER BY pc.created_at DESC
```

**What to extract from comments**:
- "empty house" / "vacant" - No furniture moving add-on needed
- "full of furniture" / "occupied" - Add furniture moving per room
- "level 5 finish" - New Drywall substrate, note level 5
- "skip closets" / "don't paint closets" - Exclude closets
- "paint ceilings" / "ceilings too" - Include ceiling surfaces
- "just walls" - Walls only, skip ceiling/trim
- "heavy trim work" - Add crown molding, chair rail
- "cabinets need painting" - Add kitchen cabinet item
- Specific paint preferences - Note for paint selection
- "peeling in bathroom" - Peeling substrate for that room
- "water damage in ceiling" - Smoke/Water Damaged substrate

### Step 1.6: Get Company Settings (Hourly Rate)

```php
// In tinker:
$settings = \App\Models\CompanySetting::first();
$effectiveRate = $settings->getEffectiveHourlyRateForScope('interior');
// This is: base_wage * payroll_burden_multiplier
// Currently: $35 * 1.35 = $47.25
```

**ALWAYS use `getEffectiveHourlyRateForScope()` for `hourly_labor_rate` on surfaces.** Interior and exterior may have different rates.

---

## PHASE 2: Parse and Map Blueprint Data

### Step 2.1: Identify Rooms and Dimensions

From extracted text, identify:

1. **Room names** - Kitchen, Bedroom, Bathroom, Hallway, Closet, etc.
2. **Dimensions** - Patterns: `12'-6" x 14'-0"`, `12'6" x 14'`, `12.5 x 14`
3. **Floor levels** - Basement, First Floor, Second Floor, Attic
4. **Construction condition** - Architectural notations:
   - **(N)** = New Construction
   - **(E)** = Existing (to remain)
   - **(R)** = Remodeled / Renovated

**Dimension conversion** (architectural to decimal feet):
- `12'-6"` = 12.5 ft
- `14'-0"` = 14.0 ft
- `9'-4"` = 9.33 ft
- `10'-8"` = 10.67 ft

### Step 2.2: Determine Wall Count per Room

| Scenario | Wall Count | Examples |
|----------|------------|---------|
| Fully enclosed | 4 | Bedrooms, bathrooms, closets, offices |
| Open on one side | 3 | Kitchen open to dining, living open to hall |
| Open on two sides | 2 | Pass-through, open galley kitchen |
| Single wall | 1 | Accent walls, fireplace feature walls |
| Hallways | 2 | Two long walls |
| Stairwells | 2 | Two long walls (open stair side) |
| L-shaped rooms | 4 | Treat as rectangle, note irregular shape |

### Step 2.3: Assign Substrates

**ALWAYS query for current substrate IDs**:
```sql
SELECT id, name, code FROM substrates WHERE is_active = 1 ORDER BY id
```

**Interior substrate selection logic**:

| Condition | Substrate Name | Code | ID* |
|-----------|---------------|------|-----|
| New construction (N) | New Drywall | NEW-DW | 3 |
| Existing - good (E) | Previously Painted - Good | PREV-GOOD | 1 |
| Existing - worn | Previously Painted - Fair | PREV-FAIR | 2 |
| Repaired/remodeled (R) | Repaired Drywall | REPAIR-DW | 4 |
| Peeling/failing | Peeling/Damaged Paint | PEEL | 5 |
| Fire/smoke/water | Smoke/Water Damaged | DAMAGE | 6 |
| Textured (orange peel) | Textured Walls - Light | INT-TEXT-LIGHT | 7 |
| Textured (knockdown) | Textured Walls - Heavy | INT-TEXT-HEAVY | 8 |
| Bare wood trim | Bare Wood - Interior | BARE-WOOD-INT | 9 |
| Wallpaper present | Wallpaper - Removal Needed | INT-WALLPAPER | 10 |

*IDs may change - always verify with SQL query above.

---

## PHASE 3: Production Rate Reference (CRITICAL)

**This is the core of accurate surface pricing.** Each surface on an area must reference the correct production rate ID.

### Surface Type Constants

These are the valid `surface_type` values for `EstimateAreaSurface`:
- `'walls'` - Wall surfaces (sqft)
- `'ceiling'` - Ceiling surfaces (sqft)
- `'trim'` - Baseboards, crown molding, chair rail (lnft)
- `'doors'` - Interior/exterior doors (unit count)
- `'windows'` - Window casings (unit count)
- `'floor'` - Floor surfaces (sqft)
- `'other'` - Prep work, specialty items

### Interior Production Rates - WALLS (surface_type: 'walls')

| ID | Name | Rate | Unit | When to Use |
|----|------|------|------|-------------|
| 10 | Textured Walls same color | 265 sqft/hr | sqft | Textured walls, same color repaint |
| 11 | Textured Walls change color | 150 sqft/hr | sqft | Textured walls, different color |
| 12 | Smooth Walls same color | 210 sqft/hr | sqft | Smooth walls, same color repaint |
| 13 | Smooth Walls change color | 120 sqft/hr | sqft | Smooth walls, different color. Also (E) existing or (R) remodeled rooms |
| 14 | Prime and paint new textured drywall | 95.78 sqft/hr | sqft | (N) New construction with textured finish |
| 15 | Prime and paint new smooth drywall | 76.36 sqft/hr | sqft | (N) New construction with smooth/level 5 finish |

**Wall Rate Selection Matrix**:
| Room Condition | Texture | Color Change? | Use Rate ID |
|----------------|---------|---------------|-------------|
| (N) New | Smooth/Level 5 | N/A (first paint) | **15** |
| (N) New | Textured | N/A (first paint) | **14** |
| (E) Existing | Smooth | Same color | **12** |
| (E) Existing | Smooth | Change color | **13** |
| (E) Existing | Textured | Same color | **10** |
| (E) Existing | Textured | Change color | **11** |
| (R) Remodeled | Smooth | Always "change" | **13** |
| (R) Remodeled | Textured | Always "change" | **11** |
| Smoke/fire damage | Any | Always "change" | **13** (smooth) or **11** (textured) |

**Default assumption**: Smooth walls, change color (ID 13) unless evidence of texture.

### Interior Production Rates - CEILING (surface_type: 'ceiling')

| ID | Name | Rate | Unit | When to Use |
|----|------|------|------|-------------|
| 16 | Textured Ceiling same color | 175 sqft/hr | sqft | Standard textured, same color |
| 17 | Textured Ceiling change color | 100 sqft/hr | sqft | Standard textured, different color |
| 18 | Textured Vaulted or > 18ft same color | 100 sqft/hr | sqft | Vaulted/high textured, same |
| 19 | Textured Vaulted or > 18ft change color | 50 sqft/hr | sqft | Vaulted/high textured, change |
| 20 | Smooth Ceiling same color | 100 sqft/hr | sqft | Smooth ceiling, same color |
| 21 | Smooth Ceiling change color | 50 sqft/hr | sqft | Smooth ceiling, different color. DEFAULT for new/remodel |
| 22 | Smooth Vaulted or over 18ft same color | 75 sqft/hr | sqft | High smooth, same |
| 23 | Smooth Vaulted or over 18ft change color | 50 sqft/hr | sqft | High smooth, change |
| 24 | Popcorn Ceiling same color | 100 sqft/hr | sqft | Popcorn, same |
| 25 | Popcorn Ceiling change color | 65 sqft/hr | sqft | Popcorn, change |
| 26 | Popcorn Vaulted or over 18ft same color | 75 sqft/hr | sqft | High popcorn, same |
| 27 | Popcorn Vaulted or over 18ft change color | 40 sqft/hr | sqft | High popcorn, change |
| 28 | Prime and paint new drywall textured Ceilings | 63.64 sqft/hr | sqft | (N) New textured ceiling |

**Ceiling Rate Selection Matrix**:
| Room Condition | Texture | Height | Color | Use Rate ID |
|----------------|---------|--------|-------|-------------|
| (N) New | Smooth | Standard | First paint | **21** |
| (N) New | Textured | Standard | First paint | **28** |
| (N) New | Smooth | Vaulted/18ft+ | First paint | **23** |
| (E) Existing | Smooth | Standard | Same | **20** |
| (E) Existing | Smooth | Standard | Change | **21** |
| (E) Existing | Textured | Standard | Same | **16** |
| (E) Existing | Textured | Standard | Change | **17** |
| (E) Existing | Popcorn | Standard | Same | **24** |
| (E) Existing | Popcorn | Standard | Change | **25** |
| (R) Remodeled | Smooth | Standard | Always "change" | **21** |
| Stairwell ceiling | Smooth | 18ft+ | Any | **23** |

**Default assumption**: Smooth Ceiling change color (ID 21).

### Interior Production Rates - BASEBOARDS/TRIM (surface_type: 'trim')

| ID | Name | Rate | Unit | When to Use |
|----|------|------|------|-------------|
| 29 | Baseboards same color | 40 lnft/hr | lnft | Existing baseboards, same color |
| 30 | Baseboards change color | 30 lnft/hr | lnft | Existing baseboards, different color |
| 31 | Baseboards change stain to paint | 12 lnft/hr | lnft | Converting stained wood to painted |
| 32 | New Baseboards (never been painted) | 18.75 lnft/hr | lnft | Brand new/never painted baseboards |
| 33 | Prime & 2 coats Baseboards | 25 lnft/hr | lnft | Full prime + 2 coat treatment |
| 34 | Prime & 2 coats Baseboards & caulk in | 21.43 lnft/hr | lnft | Full treatment with caulking |

**Baseboard Rate Selection Matrix**:
| Trim Condition | Use Rate ID |
|----------------|-------------|
| Brand new trim (never painted) | **32** |
| New trim with caulking needed | **34** |
| Existing trim, same color | **29** |
| Existing trim, change color | **30** |
| Stained wood converting to paint | **31** |
| Repaint with full prime | **33** |

**Default**: If user says "all trim is new" use **32**. For repaints use **30**.

### Interior Production Rates - DOORS (surface_type: 'doors')

| ID | Name | Rate | Unit | When to Use |
|----|------|------|------|-------------|
| 35 | Doors 1 side | 1.00 hr/unit | unit | Standard door, one side only |
| 36 | Doors both sides | 2.00 hr/unit | unit | Standard door, both sides (DEFAULT) |
| 37 | Closet Doors 1 side | 2.00 hr/unit | unit | Bifold/sliding closet door, 1 side |
| 38 | Closet Doors both sides | 4.00 hr/unit | unit | Bifold/sliding closet door, both sides |
| 39 | Commercial Steel Doors 1 side | 1.38 hr/unit | unit | Steel door, 1 side |
| 40 | Commercial Steel Doors both sides | 2.75 hr/unit | unit | Steel door, both sides |
| 41 | Same Color Doors 1 side | 0.75 hr/unit | unit | Repaint same color, 1 side |
| 42 | Same Color Doors both sides | 1.50 hr/unit | unit | Repaint same color, both sides |
| 43 | Same Color Closet Doors 1 side | 1.00 hr/unit | unit | Closet door same color, 1 side |
| 44 | Same Color Closet Doors both sides | 3.00 hr/unit | unit | Closet door same color, both sides |
| 45 | Same Color Commercial Steel 1 side | 1.03 hr/unit | unit | Steel same color, 1 side |
| 46 | Same Color Commercial Steel both sides | 2.06 hr/unit | unit | Steel same color, both sides |
| 47 | Stain to paint (full sand) 1 side | 2.50 hr/unit | unit | Converting stained door |

**Door Rate Selection Matrix**:
| Door Type | Sides | Color | Use Rate ID |
|-----------|-------|-------|-------------|
| Standard (new or change color) | Both | Change/New | **36** |
| Standard (new or change color) | 1 side | Change/New | **35** |
| Standard (same color repaint) | Both | Same | **42** |
| Closet bifold/sliding (change) | Both | Change/New | **38** |
| Closet bifold/sliding (same) | Both | Same | **44** |
| Steel/commercial (change) | Both | Change/New | **40** |
| Stained to paint conversion | 1 side | N/A | **47** |

**Default**: Doors both sides (ID 36) for new construction or color change.

### Interior Production Rates - PREP (surface_type: 'other')

| ID | Name | Rate | Unit | When to Use |
|----|------|------|------|-------------|
| 1 | Masking flooring | 250 sqft/hr | sqft | Floor protection |
| 2 | Tape line with Caulking | 30 lnft/hr | lnft | Tape + caulk at ceiling line |
| 3 | Tape line | 40 lnft/hr | lnft | Tape at ceiling line (no caulk) |
| 4 | Prep for newly installed baseboards | 50 lnft/hr | lnft | Puttying & caulking new baseboards |
| 5 | Smooth Walls prime new drywall | 150 sqft/hr | sqft | Priming only (separate from painting) |
| 6 | Textured prime new drywall | 100 sqft/hr | sqft | Priming textured drywall |
| 7 | Hand cut line same color | 60 lnft/hr | lnft | Cutting in same color at ceiling |
| 8 | Hand cut line change color | 40 lnft/hr | lnft | Cutting in different color at ceiling |
| 9 | Caulking in baseboards | 75 lnft/hr | lnft | Caulking between baseboard and wall |

### Exterior Production Rates - BODY (surface_type: 'walls')

| ID | Name | Rate | Unit | When to Use |
|----|------|------|------|-------------|
| 65 | Siding Spray | 200 sqft/hr | sqft | Spray siding only |
| 66 | Siding Spray w/light backroll | 150 sqft/hr | sqft | Spray + light backroll (most common) |
| 67 | Siding Roll | 125 sqft/hr | sqft | Roller application |
| 68 | Masonry Spray | 150 sqft/hr | sqft | Brick/stucco spray |
| 69 | Masonry Spray w/light backroll | 125 sqft/hr | sqft | Brick/stucco spray + backroll |
| 70 | Masonry Roll | 100 sqft/hr | sqft | Brick/stucco roller |
| 71 | Siding Brush | 100 sqft/hr | sqft | Brush application |
| 72 | Masonry Brush | 75 sqft/hr | sqft | Brick/stucco brush |
| 73 | Accent siding Spray | 200 sqft/hr | sqft | Accent color spray |
| 74 | Accent siding Spray w/backroll | 150 sqft/hr | sqft | Accent spray + backroll |
| 75 | Accent siding Roll | 125 sqft/hr | sqft | Accent roller |
| 76 | Accent Masonry Spray | 150 sqft/hr | sqft | Accent masonry spray |

### Exterior Production Rates - EAVES (surface_type: 'trim')

| ID | Name | Rate | Unit |
|----|------|------|------|
| 77 | Eaves Body Color | 75 lnft/hr | lnft |
| 78 | Eaves Trim Color | 20 lnft/hr | lnft |
| 79 | Eaves Separate Color | 15 lnft/hr | lnft |
| 80 | 3rd Story Eaves body color | 50 lnft/hr | lnft |
| 81 | 3rd Story eaves trim color | 10 lnft/hr | lnft |
| 82 | 3rd story eaves separate color | 5 lnft/hr | lnft |

### Exterior Production Rates - FASCIA (surface_type: 'trim')

| ID | Name | Rate | Unit |
|----|------|------|------|
| 83 | Fascia on 1 story home | 40 lnft/hr | lnft |
| 84 | Fascia on 2 story home | 35 lnft/hr | lnft |
| 85 | 3rd Story Fascia | 15 lnft/hr | lnft |

### Exterior Production Rates - WINDOWS (surface_type: 'windows')

| ID | Name | Rate | Unit |
|----|------|------|------|
| 86 | Window Vinyl with no trim | 6 units/hr | unit |
| 87 | Windows Wood with no trim (Body Color) | 2 units/hr | unit |
| 88 | Windows Vinyl with trim | 2 units/hr | unit |
| 89 | Windows Wood that don't open | 2 units/hr | unit |
| 90 | Windows Wood that open | 0.67 units/hr | unit |
| 91 | Windows 3D Vinyl | 1 unit/hr | unit |
| 92 | Windows 3D Wood that don't open | 0.80 units/hr | unit |
| 93 | Windows 3D Wood that open | 0.57 units/hr | unit |
| 94-97 | 2-tone wood variants | varies | unit |

### Exterior Production Rates - TRIM (surface_type: 'trim')

| ID | Name | Rate | Unit |
|----|------|------|------|
| 98 | Other trim on 1 story home | 40 lnft/hr | lnft |
| 99 | Other trim on 2+ story home | 35 lnft/hr | lnft |
| 100 | Downspouts or Posts 3-4 sides | 20 lnft/hr | lnft |
| 101 | 3D trim | 20 lnft/hr | lnft |
| 102 | Tudor trim facing | 50 lnft/hr | lnft |
| 103 | Tudor trim 3D | 30 lnft/hr | lnft |
| 104 | Remove & Reinstall Downspouts | 20 lnft/hr | lnft |
| 105 | Just remove Downspouts | 60 lnft/hr | lnft |

### Exterior Production Rates - DOORS (surface_type: 'doors')

| ID | Name | Rate | Unit |
|----|------|------|------|
| 106 | Door Body Color no trim | 4 units/hr | unit |
| 107 | Door Body Color with Trim | 1.33 units/hr | unit |
| 108 | Door Trim Color no trim | 1.33 units/hr | unit |
| 109 | Door Trim Color with Trim | 1 unit/hr | unit |
| 110 | Door Accent Color no trim | 1.33 units/hr | unit |
| 111 | Door Accent Color with Trim | 0.80 units/hr | unit |
| 112 | Door Stained to painted no trim | 0.57 units/hr | unit |
| 113 | Door Stained to painted with trim | 0.36 units/hr | unit |

### Exterior Production Rates - PREP (surface_type: 'other')

| ID | Name | Rate | Unit |
|----|------|------|------|
| 48 | Power Wash | 1500 sqft/hr | sqft |
| 49 | Scrape 1 Side of Board | 40 lnft/hr | lnft |
| 50 | Scrape Surface | 100 sqft/hr | sqft |
| 51 | Scuff Sand | 100 sqft/hr | sqft |
| 52 | Light Sand | 50 sqft/hr | sqft |
| 53 | Heavy Sand | 25 sqft/hr | sqft |
| 54 | Priming w/ brush (SqFt) | 150 sqft/hr | sqft |
| 55 | Priming w/ brush (LnFt) | 60 lnft/hr | lnft |
| 56 | Scrape, Sand, & Prime (LnFt) | 25 lnft/hr | lnft |
| 57 | Scrape, Sand, & Prime 75-100% Peeling | 10 sqft/hr | sqft |
| 58-60 | Scrape, Sand, & Prime various peeling % | varies | sqft |
| 61 | Scrape, Sand, & Prime Localized Failure | 6 units/hr | unit |
| 62 | Caulking 1 Story home | 100 lnft/hr | lnft |
| 63 | Caulking 2 Story home | 75 lnft/hr | lnft |
| 64 | Caulking 3rd story | 35 lnft/hr | lnft |

### Exterior Production Rates - WOOD REPLACEMENT (surface_type: 'other')

| ID | Name | Cost/lnft |
|----|------|-----------|
| 114 | 1st story trim replacement | $6.84/lnft |
| 115 | 2nd story trim replacement | $7.57/lnft |
| 116-119 | Siding replacement variants | $5.08-$6.85/lnft |
| 120 | Door frame replacement | $113.48/lnft |
| 121-127 | Fascia, railing, eave/soffit replacement | varies |

---

## PHASE 4: Build the Estimate - Surface Creation

### CRITICAL RULE: No Double-Counting

**NEVER create something as both a standalone area AND as a nested area addon.** Every paintable space should exist in ONE place only - as a surface area with its own surfaces (walls, ceiling, trim, doors).

- **Closets**: Create as their own `EstimateArea` with surfaces. Do NOT also add a `CLOSET-SM/MD/LG` addon on the parent room.
- **Pantries**: Create as their own `EstimateArea` with surfaces. Do NOT also add a `UTIL-PANT` addon on the kitchen.
- **Half Baths**: Create as their own `EstimateArea` with surfaces. Do NOT also add a `BATH-HALF` addon.

**Why**: Addons are fixed-price and opaque to the customer. Surface areas are itemized and editable. The customer can see exactly what they're paying for, and estimators can adjust individual surfaces. Use addons ONLY for prep services (furniture moving, drywall repair, lead testing) that don't have paintable surfaces.

### CRITICAL: Use EstimateAreaSurface::create() Directly

**DO NOT use `EstimateTemplateService::applyTemplateToEstimate()` for individual surfaces.** That method creates surfaces named after the production rate template and picks default rates, leading to inaccurate results.

Instead, create each surface directly with the correct production rate ID:

```php
use App\Models\EstimateAreaSurface;

EstimateAreaSurface::create([
    'area_id' => $area->id,
    'production_rate_id' => 15,  // Prime and paint new smooth drywall
    'name' => 'Prime and paint new smooth drywall',
    'surface_type' => 'walls',   // MUST be: walls, ceiling, trim, doors, windows, floor, other
    'quantity' => $area->wall_sqft,
    'unit' => 'sqft',            // sqft, lnft, or unit
    'coats' => 2,
    'production_rate_value' => 76.36,
    'hourly_labor_rate' => $effectiveRate, // From CompanySetting::getEffectiveHourlyRateForScope()
    'is_included' => true,
    'sort_order' => 1,
]);
```

### Standard Interior Room Surface Pattern

For each area, create surfaces in this order:

1. **Walls** (surface_type: 'walls', unit: 'sqft')
   - Quantity: `$area->wall_sqft`
   - Rate: Select from wall rate matrix above

2. **Ceiling** (surface_type: 'ceiling', unit: 'sqft')
   - Quantity: `$area->ceiling_sqft`
   - Rate: Select from ceiling rate matrix above

3. **Baseboards** (surface_type: 'trim', unit: 'lnft')
   - Quantity: `$area->baseboard_lnft` (or `$area->perimeter_lnft`)
   - Rate: Select from baseboard rate matrix above

4. **Doors** (surface_type: 'doors', unit: 'unit')
   - Quantity: door count (usually 1 per enclosed room, 0 for open areas)
   - Rate: Select from door rate matrix above

### Door Count Logic

| Room Type | Doors | Notes |
|-----------|-------|-------|
| Enclosed room (bedroom, office, bathroom) | 1 | Standard interior door |
| Kitchen (open concept) | 0-1 | 0 if fully open, 1 if has door to hallway |
| Pantry | 1 | Usually has a door |
| Hallway | 0 | Open ends, no doors on hallway itself |
| Stairwell | 0 | Open stairwell |
| Open living/dining | 0 | Open concept areas |
| Closet (walk-in with door) | 1 | Use closet door rate |
| Closet (open entry, 3 walls) | 0 | No door on open entry |
| Utility/Laundry | 1 | Has a door |
| Storage/Mechanical | 1 | Has a door |

### Batch Surface Creation Code

```php
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\CompanySetting;

$settings = CompanySetting::first();
$effectiveRate = $settings->getEffectiveHourlyRateForScope('interior');

// Get all areas for the estimate
$areas = EstimateArea::where('estimate_id', {ESTIMATE_ID})->get();

// Define production rate selections per area
$areaConfig = [
    // [area_id, condition, wall_rate_id, wall_rate_val, ceil_rate_id, ceil_rate_val, bb_rate_id, bb_rate_val, door_rate_id, door_rate_val, door_count, coats]
    [129, 'E', 13, 120.00, 21, 50.00, 32, 18.75, 36, 2.00, 1, 2],
    [130, 'E', 13, 120.00, 21, 50.00, 32, 18.75, 36, 2.00, 0, 2],
    // ... etc
];

foreach ($areaConfig as $config) {
    [$areaId, $cond, $wRateId, $wRateVal, $cRateId, $cRateVal, $bRateId, $bRateVal, $dRateId, $dRateVal, $doorCount, $coats] = $config;

    $area = EstimateArea::find($areaId);
    $sort = 1;

    // Get rate name from DB for accuracy
    $wallRate = \App\Models\ProductionRate::find($wRateId);
    $ceilRate = \App\Models\ProductionRate::find($cRateId);
    $bbRate = \App\Models\ProductionRate::find($bRateId);
    $doorRate = \App\Models\ProductionRate::find($dRateId);

    // Walls
    if ($area->wall_sqft > 0) {
        EstimateAreaSurface::create([
            'area_id' => $areaId,
            'production_rate_id' => $wRateId,
            'name' => $wallRate->name,
            'surface_type' => 'walls',
            'quantity' => $area->wall_sqft,
            'unit' => 'sqft',
            'coats' => $coats,
            'production_rate_value' => $wRateVal,
            'hourly_labor_rate' => $effectiveRate,
            'is_included' => true,
            'sort_order' => $sort++,
        ]);
    }

    // Ceiling
    if ($area->ceiling_sqft > 0) {
        EstimateAreaSurface::create([
            'area_id' => $areaId,
            'production_rate_id' => $cRateId,
            'name' => $ceilRate->name,
            'surface_type' => 'ceiling',
            'quantity' => $area->ceiling_sqft,
            'unit' => 'sqft',
            'coats' => $coats,
            'production_rate_value' => $cRateVal,
            'hourly_labor_rate' => $effectiveRate,
            'is_included' => true,
            'sort_order' => $sort++,
        ]);
    }

    // Baseboards
    if ($area->baseboard_lnft > 0) {
        EstimateAreaSurface::create([
            'area_id' => $areaId,
            'production_rate_id' => $bRateId,
            'name' => $bbRate->name,
            'surface_type' => 'trim',
            'quantity' => $area->baseboard_lnft,
            'unit' => 'lnft',
            'coats' => $coats,
            'production_rate_value' => $bRateVal,
            'hourly_labor_rate' => $effectiveRate,
            'is_included' => true,
            'sort_order' => $sort++,
        ]);
    }

    // Doors
    if ($doorCount > 0) {
        EstimateAreaSurface::create([
            'area_id' => $areaId,
            'production_rate_id' => $dRateId,
            'name' => $doorRate->name,
            'surface_type' => 'doors',
            'quantity' => $doorCount,
            'unit' => 'unit',
            'coats' => $coats,
            'production_rate_value' => $dRateVal,
            'hourly_labor_rate' => $effectiveRate,
            'is_included' => true,
            'sort_order' => $sort++,
        ]);
    }
}
```

---

## PHASE 5: Areas - Create or Update

### Creating Areas via Template Service

Use `EstimateTemplateService::applyTemplateToEstimate()` to CREATE the area (room) itself. This correctly creates the area record with wall_sqft and ceiling_sqft auto-calculated.

```php
use App\Models\Estimate;
use App\Models\EstimateTemplate;
use App\Services\EstimateTemplateService;

$estimate = Estimate::find({ESTIMATE_ID});
$service = app(EstimateTemplateService::class);

// Create the area
$template = EstimateTemplate::find($templateId); // e.g., 13 for Kitchen
$sqft = $length * $width;
$usage = $service->applyTemplateToEstimate(
    estimate: $estimate,
    template: $template,
    quantity: $sqft,
    coats: 2
);

$area = $usage->resultable;

// Update with blueprint-specific details
$area->update([
    'name' => 'Kitchen (1st Floor)',
    'length' => 14.5,
    'width' => 12.0,
    'height' => 9.0,
    'wall_count' => 3,
    'substrate_id' => 3, // NEW-DW
    'notes' => '(N) New construction. Open to dining. Level 5 smooth. Sheet A110.',
    'sort_order' => 1,
]);
```

**THEN** add surfaces using `EstimateAreaSurface::create()` directly (Phase 4 pattern).

**IMPORTANT**: After creating the area via template service, DELETE any surfaces it auto-created before adding your own:
```php
EstimateAreaSurface::where('area_id', $area->id)->delete();
```

### Interior Template Mapping

**Always verify IDs**: `SELECT id, name FROM estimate_templates WHERE is_active = 1 ORDER BY scope_type, name`

| Blueprint Room | Template Name |
|----------------|---------------|
| Living Room | Living Room |
| Family Room | Family Room |
| Great Room | Great Room |
| Dining Room | Dining Room |
| Sunroom | Sunroom |
| Bonus Room / Rec Room | Bonus Room |
| Bedroom | Bedroom |
| Master Bedroom / Primary | Master Bedroom |
| Guest Room | Guest Room |
| Nursery | Nursery |
| Bathroom / Half Bath | Bathroom |
| Full Bath / Master Bath | Full Bathroom |
| Kitchen | Kitchen |
| Laundry / Utility | Laundry |
| Hallway (ground floor) | Hallway |
| Upper Hallway (2nd floor) | Upper Hallway |
| Entry / Foyer | Entry Foyer |
| 2-Story Foyer | Entry Foyer 2-Story |
| Stairwell / Stairs | Stairwell |
| Mudroom | Mudroom |
| Closet / WIC | Closet |
| Office / Study / Den | Office |
| Basement | Basement |

---

## PHASE 6: Add-ons (Nested Area Templates) - PREP SERVICES ONLY

**IMPORTANT: Addons are for prep/service items ONLY, not for paintable spaces.**

Closets, pantries, half baths, etc. should be created as their own EstimateArea with surfaces (see Phase 4 no-double-counting rule). Addons are for non-paintable services like furniture moving, drywall repair, and lead testing.

```php
use App\Models\EstimateAreaAddon;
use App\Models\NestedAreaTemplate;

$nested = NestedAreaTemplate::where('code', 'SVC-FURN-MOVE')->first();
EstimateAreaAddon::create([
    'estimate_area_id' => $area->id,
    'nested_area_template_id' => $nested->id,
    'quantity' => 1,
    'unit_price' => $nested->fixed_price,
    'total_price' => $nested->fixed_price * 1,
]);
```

### Add-on Reference (Prep/Service Only)

| Add-on | Code | When to Add |
|--------|------|-------------|
| **Prep Work** | | |
| Furniture Moving | SVC-FURN-MOVE | Per room when house is occupied |
| Drywall Repair - Minor | PREP-DRYWALL-SM | Nail holes, small cracks |
| Drywall Repair - Major | PREP-DRYWALL-LG | Large holes, extensive damage |
| Caulking - Per Room | PREP-CAULK-ROOM | Rooms needing recaulk |
| Ceiling Texture Removal | PREP-TEXTURE-REMOVE | Popcorn ceiling removal |
| Wood Priming - Bare Wood | PREP-PRIME-WOOD | Bare wood trim |
| Lead Paint Testing | PREP-LEAD-TEST | Pre-1978 homes |
| **Exterior Prep** | | |
| Pressure Washing - Small | PREP-PRESSURE-SM | Small area |
| Pressure Washing - Medium | PREP-PRESSURE-MD | Medium area |
| Pressure Washing - Large | PREP-PRESSURE-LG | Large area |

### DO NOT Use Addons For (create as separate areas instead):

| Space | Create As | NOT As Addon |
|-------|-----------|-------------|
| Closets (reach-in, walk-in) | EstimateArea with Closet template + surfaces | ~~CLOSET-SM/MD/LG~~ |
| Pantries | EstimateArea with custom template + surfaces | ~~UTIL-PANT~~ |
| Half Baths | EstimateArea with Bathroom template + surfaces | ~~BATH-HALF~~ |
| Entry Doors | Surface on parent area (doors surface_type) | ~~EXT-ENTRY-STD~~ |
| Garage Doors | Surface on parent area (doors surface_type) | ~~EXT-GARAGE-1/2~~ |
| Porch Ceilings | EstimateArea with custom template + surfaces | ~~EXT-PORCH-SM/MD/LG~~ |

---

## PHASE 7: Context-Driven Decisions

### Occupied vs Empty House

| Signal | Action |
|--------|--------|
| "empty", "vacant", "no furniture" | Skip furniture moving add-on |
| "occupied", "full", "heavy furniture" | Add `SVC-FURN-MOVE` per room |
| "some furniture", "light" | Add to main rooms only |
| No information | Ask user or default to occupied |

### Trim Work Assessment

| Signal | Action |
|--------|--------|
| "all trim is new" / "new baseboards" | Use rate ID 32 (New Baseboards never painted) |
| "existing trim, same color" | Use rate ID 29 (Baseboards same color) |
| "existing trim, change color" | Use rate ID 30 (Baseboards change color) |
| "stained wood" / "converting from stain" | Use rate ID 31 (stain to paint) |
| "just walls" / "walls only" | Skip all trim surfaces |
| "walls and ceilings" | Include ceiling, skip trim |
| No specification | Default: include baseboards + doors |

### Wall Texture Assessment

| Signal | Action |
|--------|--------|
| "smooth" / "level 5" / "no texture" | Use smooth wall rates (12/13/15) |
| "textured" / "orange peel" / "knockdown" | Use textured wall rates (10/11/14) |
| "popcorn ceiling" | Use popcorn ceiling rates (24-27) |
| New construction | Usually smooth (15) unless specified textured (14) |
| No specification | Default: smooth walls |

### Per-Room Surface Logic

**Standard room** (bedroom, office, guest room):
- Walls: wall_sqft
- Ceiling: ceiling_sqft
- Baseboards: baseboard_lnft (perimeter)
- 1 door (both sides)

**Kitchen**:
- Walls: wall_sqft (3 walls if open concept)
- Ceiling: ceiling_sqft
- Baseboards: baseboard_lnft
- 0-1 doors
- Optional: Pantry addon, Cabinet items

**Bathroom**:
- Walls: wall_sqft (reduced for tile areas)
- Ceiling: ceiling_sqft
- Baseboards: baseboard_lnft
- 1 door (both sides)

**Hallway**:
- Walls: wall_sqft (2 walls)
- Ceiling: ceiling_sqft
- Baseboards: baseboard_lnft
- 0 doors on hallway itself

**Stairwell**:
- Walls: wall_sqft (2-3 walls, height is double)
- Ceiling: ceiling_sqft (at top only)
- Baseboards: baseboard_lnft
- 0 doors

**Closet (open entry)**:
- Walls: wall_sqft (3 walls)
- Ceiling: ceiling_sqft
- Baseboards: baseboard_lnft
- 0 doors

---

## PHASE 8: Save Draft - Generate Packages

After all areas and surfaces are created, the estimate needs its **good/better/best packages** generated. This happens via the **Save Draft** button in the template builder UI.

### How It Works

The template builder (`resources/views/components/estimates/⚡template-builder.blade.php`) has a "Save Draft" button that triggers `saveAsDraft()` which calls `save(false)`. This method:

1. Deletes existing areas and recreates them from the builder's internal state
2. Calls `EstimatePackageGeneratorService::generateFromTemplates()` to create good/better/best packages
3. Updates estimate totals

### Important: Template Builder Loads Existing Areas

When the template builder page loads, `loadExistingTemplateUsages()` reads all existing areas from the database back into its internal state. So areas created directly via the DB will be picked up correctly.

### What to Tell the User

After building all areas and surfaces, instruct the user:

> "All areas and surfaces have been created. To generate the good/better/best packages, please:
> 1. Go to the estimate template builder: `/estimates/{ID}/edit/template`
> 2. Review the areas (they should all be loaded)
> 3. Click **Save Draft** to generate the pricing packages"

### Alternatively: Trigger Programmatically

If the user prefers not to use the UI, packages can be generated via tinker:

```php
use App\Models\Estimate;
use App\Services\EstimatePackageGeneratorService;

$estimate = Estimate::find({ESTIMATE_ID});
$generator = app(EstimatePackageGeneratorService::class);
$generator->regenerateFromTemplates($estimate);
```

This creates packages from `PackageTemplate` records (Good/Better/Best tiers) with pricing based on the estimate's `areas_subtotal`.

---

## PHASE 9: Verify and Report

### Verification Query
```sql
SELECT ea.name, ea.wall_sqft, ea.ceiling_sqft, ea.baseboard_lnft,
       s.name as substrate,
       GROUP_CONCAT(
           CONCAT(eas.surface_type, ':', eas.name, ' (', eas.quantity, ' ', eas.unit, ', ', eas.coats, 'c)')
           ORDER BY eas.sort_order SEPARATOR ' | '
       ) as surfaces,
       (SELECT COUNT(*) FROM estimate_area_addons WHERE estimate_area_id = ea.id) as addon_count
FROM estimate_areas ea
LEFT JOIN substrates s ON ea.substrate_id = s.id
LEFT JOIN estimate_area_surfaces eas ON eas.area_id = ea.id
WHERE ea.estimate_id = {ESTIMATE_ID}
GROUP BY ea.id
ORDER BY ea.sort_order
```

### Report to User

Provide a summary table:
1. **Total areas** created, grouped by floor level
2. **Surface breakdown**: walls, ceilings, trim, doors per area with production rate used
3. **Total add-ons** (closets, pantries, prep work)
4. **Substrate breakdown** (how many rooms per substrate type)
5. **Link** to template builder: `/estimates/{ID}/edit/template`
6. **Items to review**: Any rooms where dimensions were estimated, walls were guessed, or conditions were assumed

---

## Quality Checklist

Before reporting completion:

- [ ] EVERY room from blueprint has an area (don't skip small rooms)
- [ ] ALL hallways, stairwells, closets, pantries are their OWN areas with surfaces
- [ ] **NO double-counting**: Nothing exists as both an area AND an addon
- [ ] Addons used ONLY for prep services (furniture moving, drywall repair, etc.)
- [ ] Dimensions converted correctly to decimal feet
- [ ] Wall counts reflect actual room openness (not all 4)
- [ ] Substrates match construction condition (photos + blueprints + notes)
- [ ] Each surface has the CORRECT production_rate_id for room condition
- [ ] surface_type uses valid values: walls, ceiling, trim, doors, windows, other
- [ ] Wall rates match texture (smooth vs textured) and condition (new vs existing vs change color)
- [ ] Ceiling rates match texture and height (standard vs vaulted)
- [ ] Baseboard rates match condition (new, same color, change color, stain to paint)
- [ ] Door rates match type (standard, closet, steel) and sides (1 or both)
- [ ] Coats appropriate for each surface
- [ ] hourly_labor_rate from CompanySetting::getEffectiveHourlyRateForScope()
- [ ] Trim surfaces added (baseboards, doors) per room
- [ ] Prep add-ons added based on condition (not paintable spaces)
- [ ] Notes include: floor level, sheet reference, construction type
- [ ] Sort order groups by floor (basement first, then 1st, then 2nd)
- [ ] User instructed to click **Save Draft** to generate good/better/best packages

## Common Blueprint Notations

| Notation | Meaning |
|----------|---------|
| (N) | New Construction |
| (E) | Existing (to remain) |
| (R) | Remodeled / Renovated |
| WIC | Walk-In Closet |
| W/D | Washer/Dryer (Laundry) |
| MBR / Primary | Master Bedroom |
| GR | Great Room |
| FR | Family Room |
| DR | Dining Room |
| KIT | Kitchen |
| MECH | Mechanical Room (skip unless asked) |
| UNFIN | Unfinished (skip unless asked) |
| CLG | Ceiling height notation |
| T.O.W. | Top of Wall |
| F.F. | Finish Floor |
| A.F.F. | Above Finish Floor |

## Edge Cases

- **No dimensions on blueprint**: Use template default sqft. Note "Verify dimensions on site"
- **Irregular rooms**: Best-fit rectangle. Note "Irregular shape - sqft approximate"
- **Vaulted ceilings**: Average height. Use vaulted ceiling rates (18, 19, 22, 23, 26, 27)
- **2-story spaces**: Entry Foyer 2-Story template or height=18ft
- **Garage interior**: Skip unless asked. If included, use Basement template
- **Mechanical rooms**: Skip unless explicitly asked
- **Open floor plans**: Can be one Great Room or separate areas with wall_count=3
- **Missing photos**: Note "No photos available - condition estimated from blueprints only"
- **Conflicting info**: Blueprint says (N) but photos show existing paint - ask user to clarify
- **Storage/utility rooms**: May only need primer (1 coat) - check notes
