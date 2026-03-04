# Full Paint Color Catalog Seeder

**Date**: 2026-03-04
**Status**: Approved

## Problem

Color visualizer uses `PaintColorLookupTool` to find hex values by name/code. Only 60 colors in DB, but manufacturers have ~6,000+. Colors not in DB get approximated by AI — inaccurate.

## Solution

Compile full color catalogs into JSON data files. Refactor seeder to read from JSON with chunked inserts. Add indexes and caching for heavy usage.

## Data Files

```
database/data/colors/
├── sherwin-williams.json   (~1,700 colors)
├── benjamin-moore.json     (~3,500 colors)
├── behr.json               (~1,500 colors)
└── ppg.json                (~500 colors)
```

Format per entry:
```json
{"name": "Alabaster", "code": "SW 7008", "hex": "#F0EDE3", "family": "white", "popular": true}
```

## Color Families (standardized)

white, off-white, gray, beige, brown, blue, green, red, orange, yellow, purple, pink, black, neutral

## Seeder Changes

- Read from JSON files instead of hardcoded arrays
- Process in chunks of 100 via `Collection::chunk()`
- `updateOrCreate` on `[paint_brand_id, code]` (idempotent)
- `is_popular` curated for top ~30-50 per brand

## Performance: Indexes & Caching

### New Migration — Additional Indexes
- Composite: `[is_active, paint_brand_id, color_family]` — brand + family filtered queries
- Index on `name` — search by name performance

### Query Caching (in PaintColor model)
- `popularColors()` cached 24hrs (rarely changes)
- `colorFamilies()` cached 24hrs
- Cache invalidated on seeder run

## No Changes Required
- PaintColor model (schema fits)
- PaintColorLookupTool (already searches name/code)
- Visualizer components (work with any DB color)
