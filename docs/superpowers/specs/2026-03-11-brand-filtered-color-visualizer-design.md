# Brand-Filtered Color Visualizer

## Problem

The color visualizer shows all paint colors from all brands in a flat list. This makes it hard for customers to find colors — especially older users on mobile. Popular colors are small and cluttered. The focus should be on the AI visualization, with color selection being a quick, convenient step.

## Solution

Add brand-based filtering, interior/exterior popular color carousels, and larger color cards to both the public and estimates color visualizers.

## Database Change

Add `intended_use` string column to `paint_colors` table:
- Values: `interior`, `exterior`, `both`
- Default: `both` (backward compatible)
- Use `string` column (not enum) for SQLite compatibility in tests
- Validate at application level in the model
- New composite index: `[is_active, is_popular, intended_use, paint_brand_id]`
- Drop existing `[is_active, is_popular]` index (superseded by the new composite)

### Tagging Existing Popular Colors

The migration sets all existing colors to `both` by default. A separate data migration/seeder tags popular colors with appropriate `intended_use` values using this heuristic:

- **Exterior-only colors**: Colors commonly used for house body/trim exteriors (deep accent colors, weather-resistant trending colors). Identified by `color_family` in (`red`, `deep blue`, `forest green`) or names containing exterior keywords.
- **Interior-only colors**: Soft neutrals, accent wall colors, pastels primarily used indoors.
- **Both**: Whites, light grays, and versatile neutrals that work everywhere. This is the default — only override when a color clearly skews one way.

The seeder JSON files (`database/data/colors/*.json`) gain an optional `intended_use` field. `PaintColorSeeder` reads it when present, defaults to `both` otherwise.

## Model Changes

- `app/Models/PaintColor.php`:
  - Add `intended_use` to `$fillable`
  - Add `intended_use` cast (string)
  - Add `scopeForIntendedUse(Builder $query, string $use)` scope
  - Update `getCachedPopularColors(?int $brandId, ?string $intendedUse)` — cache key includes intended use
  - Update `clearCache()` to flush new cache keys
- `database/factories/PaintColorFactory.php`:
  - Add `intended_use` default (`both`)
  - Add `->interior()`, `->exterior()` factory states

## UI Components

### Brand Selector
- Horizontal row of pill/buttons at the top of the color section
- One brand active at a time, visually highlighted (e.g., filled primary style)
- No brand selected by default — shows message: "Select a brand to see popular colors"
- Loads active brands from `paint_brands` table, respects `sort_order`

### Popular Color Carousels
- Two rows: "Interior Popular" and "Exterior Popular"
- 2 cards visible at a time, horizontal scroll/carousel for the rest
- Desktop: left/right arrow buttons on carousel edges
- Mobile: native swipe gesture on carousel container; tap on card adds to prompt
- 10 colors per row max, filtered by selected brand + `intended_use` (interior/both for interior row, exterior/both for exterior row)
- Ordered alphabetically by name within each row
- Cards: large color swatch (~60% of card height), color name, color code below
- Tap feedback: brief border highlight, color name+code inserted into prompt at cursor position

### Search
- Filters to selected brand only; placeholder updates dynamically (e.g., "Search SW colors...")
- If no brand selected, searches across all brands (fallback)
- Results display as same large card format as popular colors
- When brand is selected via pills, the UI adds a `forBrand()` constraint. The `search()` scope's own brand-prefix extraction still runs — if they conflict, the UI pill's brand takes precedence (apply `forBrand()` after `search()`)
- Empty results with brand selected: show "No colors found for '[query]' in [Brand Name]"

### Prompt Helper
- Single-line tip above the prompt textarea
- Text: `Tip: "Paint [surface] [color name]" - e.g. "Paint walls Agreeable Gray"`
- Compact — does not clutter mobile views

## Livewire State

Both components gain these properties/actions:

```
public ?int $selectedBrandId = null;

public function selectBrand(int $brandId): void  // toggle brand on/off
#[Computed] interiorPopularColors(): Collection   // filtered by brand + interior/both
#[Computed] exteriorPopularColors(): Collection   // filtered by brand + exterior/both
#[Computed] availableBrands(): Collection          // active brands, ordered
```

Existing `colorSearchResults` computed property gains `forBrand($this->selectedBrandId)` constraint when set.

## Files Modified

1. `resources/views/components/public/⚡color-visualizer.blade.php` — public/guest visualizer
2. `resources/views/components/estimates/⚡color-visualizer.blade.php` — internal estimates visualizer
3. `app/Models/PaintColor.php` — add `intended_use` to fillable/casts, new scope, update cache methods
4. `database/factories/PaintColorFactory.php` — add `intended_use` default + states
5. `database/migrations/xxxx_add_intended_use_to_paint_colors.php` — new migration
6. `database/seeders/PaintColorSeeder.php` — read `intended_use` from JSON, tag popular colors
7. `database/data/colors/*.json` — add optional `intended_use` field to popular colors

## What Stays the Same

- AI visualization flow (upload, prompt, visualize)
- Lead capture flow on public version
- Add-to-estimate flow on internal version
- `PaintColor::search()` scope logic (brand extraction, grey/gray, hyphen normalization)
- Color search debounce timing
- Existing test coverage for search

## Edge Cases

- **No brand selected**: Show "Select a brand to see popular colors" message. Search still works across all brands.
- **Brand has no popular colors for a use**: Hide that carousel row entirely for that brand.
- **Empty search results with brand**: Show "No colors found for '[query]' in [Brand]" with suggestion to try a different term.
- **Brand has fewer than 10 popular colors for a use**: Show all available, carousel arrows hidden if 2 or fewer.
- **Brand selected + brand typed in search**: UI pill brand takes precedence; `forBrand()` applied after `search()`.

## Accessibility

- Large tap targets on color cards (whole card is clickable)
- Visual confirmation feedback on tap (border highlight)
- Swipe only for carousel navigation, never accidentally adds colors
- Arrow buttons on desktop for users who don't drag-scroll
- Short instructional tip so users understand the prompt format

## Test Plan

- Popular colors filtered by brand + intended_use returns correct subsets
- Search with brand selected constrains results to that brand
- Search without brand returns all brands
- Cache keys vary by brand + intended_use; clearCache flushes all
- Factory states (`->interior()`, `->exterior()`) produce correct values
- Carousel renders correct number of colors per row
- Brand pill click updates selectedBrandId and refreshes carousels
