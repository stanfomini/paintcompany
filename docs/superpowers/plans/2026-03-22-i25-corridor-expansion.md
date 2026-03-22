# I-25 Corridor Service Area Expansion Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add 11 new cities (10 I-25 corridor from Erie to Fort Collins + Highlands Ranch) as active service areas with coordinates, zip codes, hero text, and map markers — expanding the coverage map northward and filling a gap south.

**Architecture:** Add new city records to the existing `ServiceAreaSeeder`, populate coordinates, and run the seeder. No schema changes needed — the `service_areas` table already has all required columns (`city`, `slug`, `zip_codes`, `latitude`, `longitude`, `hero_text`, `specialties_text`). The existing `⚡area-page.blade.php`, map partial, sitemap generator, and schema all dynamically pull from `ServiceArea::active()` so new cities will automatically appear everywhere.

**Tech Stack:** Laravel 12, Livewire 4, Leaflet.js maps, ServiceArea model with `updateOrCreate` seeder pattern.

---

## Scope

### I-25 Corridor Cities to Add (North of Existing Coverage)

These cities sit along or near I-25 between Thornton (already exists) and Fort Collins. Ordered south to north:

| # | City | Slug | Latitude | Longitude | Approx Population |
|---|------|------|----------|-----------|-------------------|
| 1 | Erie | `erie-co` | 40.0503 | -105.0500 | ~30,000 |
| 2 | Firestone | `firestone-co` | 40.1125 | -104.9367 | ~17,000 |
| 3 | Frederick | `frederick-co` | 40.0992 | -104.9372 | ~15,000 |
| 4 | Dacono | `dacono-co` | 40.0847 | -104.9394 | ~6,500 |
| 5 | Mead | `mead-co` | 40.2328 | -104.9983 | ~5,500 |
| 6 | Berthoud | `berthoud-co` | 40.3083 | -105.0811 | ~10,000 |
| 7 | Johnstown | `johnstown-co` | 40.3369 | -104.9117 | ~18,000 |
| 8 | Loveland | `loveland-co` | 40.3978 | -105.0750 | ~78,000 |
| 9 | Windsor | `windsor-co` | 40.4775 | -104.9014 | ~36,000 |
| 10 | Fort Collins | `fort-collins-co` | 40.5853 | -105.0844 | ~170,000 |

**Note:** Thornton, Brighton, Longmont, and Broomfield already exist in the database and already have coordinates. They form the southern anchor of this expansion.

### What Already Works Automatically

Once new ServiceArea records exist with `is_active: true` and coordinates:
- **Area pages** render at `/areas/{slug}` (route + `⚡area-page.blade.php` use dynamic lookup)
- **Area index** at `/areas` lists all active areas alphabetically
- **Landing page map** shows all cities with markers (pulls `ServiceArea::active()`)
- **Landing page footer** links to all area pages
- **Sitemap** includes all active area slugs (`GenerateSitemapCommand`)
- **Schema.org `areaServed`** on landing + service pages lists all active cities
- **Area page maps** show zoomed-in view with neighboring markers

### Files to Modify

| # | File | Changes |
|---|------|---------|
| 1 | `database/seeders/ServiceAreaSeeder.php` | Add 10 new city entries with zip codes, hero text, specialties, and coordinates |
| 2 | `tests/Feature/SEO/SchemaAndSitemapTest.php` | Add test for I-25 corridor cities in sitemap |
| 3 | `tests/Feature/LandingPage/ServiceAreaTest.php` | Add test for new area pages rendering |
| 4 | `resources/views/components/public/⚡landing-page.blade.php` | Adjust map zoom/center and heading copy for expanded I-25 coverage |

### Files NOT Modified (they handle new cities automatically)
- `⚡area-page.blade.php` — dynamic, no changes needed
- `⚡areas-index.blade.php` — queries `ServiceArea::active()`
- `partials/service-area-map.blade.php` — renders all areas with coordinates
- `GenerateSitemapCommand.php` — iterates all active areas
- `ServiceArea.php` model — no schema changes
- `⚡service-page.blade.php` — areaServed already dynamic

---

## Tasks

### Task 1: Add I-25 Corridor Cities to Seeder

**Files:**
- Modify: `database/seeders/ServiceAreaSeeder.php` (append to the `$areas` array)

- [ ] **Step 1: Read the current seeder to see the exact format**

Read the full file to understand the entry structure. Each entry has: `city`, `slug`, `zip_codes` (array), `hero_text`, `specialties_text`. Note that coordinates are NOT in the seeder — they were seeded separately via tinker. We'll add `latitude` and `longitude` to each entry in the seeder so future runs set them too.

- [ ] **Step 2: Update the seeder's `run()` method to also set coordinates**

Currently the seeder does:
```php
foreach ($areas as $area) {
    ServiceArea::updateOrCreate(
        ['slug' => $area['slug']],
        array_merge($area, ['state' => 'CO', 'is_active' => true])
    );
}
```

This merges all array keys into the update. So adding `latitude` and `longitude` to each entry array will automatically persist them. No structural change needed to the loop — just add the keys to each city's data array.

- [ ] **Step 3: Add latitude/longitude to ALL existing 20 city entries**

Add `'latitude' => X.XXXX, 'longitude' => -XXX.XXXX` to each of the 20 existing city arrays so the seeder becomes the single source of truth for coordinates. Use the coordinates already seeded via tinker:

```
Denver:          39.7392, -104.9903
Aurora:          39.7294, -104.8319
Lakewood:        39.7047, -105.0814
Arvada:          39.8028, -105.0875
Westminster:     39.8367, -105.0372
Thornton:        39.8680, -104.9719
Centennial:      39.5791, -104.8769
Boulder:         40.0150, -105.2705
Broomfield:      39.9205, -105.0867
Longmont:        40.1672, -105.1019
Castle Rock:     39.3722, -104.8561
Parker:          39.5186, -104.7614
Littleton:       39.6133, -105.0166
Englewood:       39.6480, -104.9878
Golden:          39.7555, -105.2211
Commerce City:   39.8083, -104.9339
Brighton:        39.9853, -104.8206
Northglenn:      39.8853, -104.9872
Federal Heights: 39.8614, -105.0161
Wheat Ridge:     39.7664, -105.0772
```

**Note:** Highlands Ranch (39.5419, -104.9708) was in the original coordinate list but is NOT in the seeder or database. Add it as a new city entry alongside the I-25 corridor cities in Step 4 (making 11 new cities total).

- [ ] **Step 4: Add the 11 new city entries (10 I-25 corridor + Highlands Ranch)**

Append these 11 entries to the `$areas` array, after the existing 20. Each needs: `city`, `slug`, `zip_codes`, `hero_text`, `specialties_text`, `latitude`, `longitude`.

```php
[
    'city' => 'Erie',
    'slug' => 'erie-co',
    'zip_codes' => ['80516'],
    'latitude' => 40.0503,
    'longitude' => -105.0500,
    'hero_text' => 'Erie is one of Colorado\'s fastest-growing towns, and we\'re growing with it. From new builds in Flatiron Meadows to established homes in Old Town Erie, we deliver painting that matches the quality of your community.',
    'specialties_text' => 'New construction painting, builder touch-ups, modern farmhouse exteriors, HOA-compliant color schemes',
],
[
    'city' => 'Firestone',
    'slug' => 'firestone-co',
    'zip_codes' => ['80520'],
    'latitude' => 40.1125,
    'longitude' => -104.9367,
    'hero_text' => 'Firestone homeowners love our hassle-free approach. We bring Denver-level quality to your doorstep with real-time project tracking and daily photo updates.',
    'specialties_text' => 'New community exteriors, stucco homes, basement finishing, garage floor coatings',
],
[
    'city' => 'Frederick',
    'slug' => 'frederick-co',
    'zip_codes' => ['80530'],
    'latitude' => 40.0992,
    'longitude' => -104.9372,
    'hero_text' => 'Frederick families deserve a painting experience that\'s stress-free from start to finish. Our technology keeps you in the loop while our crews deliver flawless results.',
    'specialties_text' => 'Family home interiors, exterior repaints, deck staining, accent walls',
],
[
    'city' => 'Dacono',
    'slug' => 'dacono-co',
    'zip_codes' => ['80514'],
    'latitude' => 40.0847,
    'longitude' => -104.9394,
    'hero_text' => 'Dacono\'s growing community deserves premium painting services. We bring our full-service approach to every home, big or small.',
    'specialties_text' => 'Ranch home exteriors, interior repaints, new construction touch-ups',
],
[
    'city' => 'Mead',
    'slug' => 'mead-co',
    'zip_codes' => ['80542'],
    'latitude' => 40.2328,
    'longitude' => -104.9983,
    'hero_text' => 'Mead homeowners enjoy small-town living with big-city painting quality. Our crews treat your home like their own, backed by technology that keeps you informed.',
    'specialties_text' => 'Rural property painting, barn and outbuilding staining, large exterior projects',
],
[
    'city' => 'Berthoud',
    'slug' => 'berthoud-co',
    'zip_codes' => ['80513'],
    'latitude' => 40.3083,
    'longitude' => -105.0811,
    'hero_text' => 'Berthoud sits where the plains meet the foothills, and our painting services are built for both. From mountain-view homes to downtown storefronts, we deliver quality that lasts.',
    'specialties_text' => 'Mountain-exposure exteriors, historic downtown properties, custom color consultation',
],
[
    'city' => 'Johnstown',
    'slug' => 'johnstown-co',
    'zip_codes' => ['80534'],
    'latitude' => 40.3369,
    'longitude' => -104.9117,
    'hero_text' => 'Johnstown is booming with new homes and families. We bring the same precision and technology-driven approach that Denver metro homeowners love to every Johnstown project.',
    'specialties_text' => 'New development painting, builder upgrades, exterior staining, commercial spaces',
],
[
    'city' => 'Loveland',
    'slug' => 'loveland-co',
    'zip_codes' => ['80537', '80538', '80539'],
    'latitude' => 40.3978,
    'longitude' => -105.0750,
    'hero_text' => 'The Sweetheart City deserves painters who care as much about your home as you do. Our Loveland team delivers meticulous prep, premium paints, and a customer experience you\'ll love.',
    'specialties_text' => 'Mountain-view home exteriors, art district commercial, cabin staining, large residential interiors',
],
[
    'city' => 'Windsor',
    'slug' => 'windsor-co',
    'zip_codes' => ['80550', '80551'],
    'latitude' => 40.4775,
    'longitude' => -104.9014,
    'hero_text' => 'Windsor homeowners appreciate quality craftsmanship. From Water Valley to the neighborhoods along the Poudre Trail, we bring professional painting with zero hassle.',
    'specialties_text' => 'Upscale residential interiors, stucco and stone exteriors, HOA communities, deck refinishing',
],
[
    'city' => 'Fort Collins',
    'slug' => 'fort-collins-co',
    'zip_codes' => ['80521', '80524', '80525', '80526', '80528'],
    'latitude' => 40.5853,
    'longitude' => -105.0844,
    'hero_text' => 'Fort Collins is Colorado\'s craft capital, and we bring that same attention to craft to every painting project. From Old Town Victorians to new builds in Timnath, our technology-driven approach delivers exceptional results.',
    'specialties_text' => 'Historic home restoration, Victorian trim work, university area properties, commercial storefronts, high-altitude exterior coatings',
],
[
    'city' => 'Highlands Ranch',
    'slug' => 'highlands-ranch-co',
    'zip_codes' => ['80126', '80129', '80130', '80163'],
    'latitude' => 39.5419,
    'longitude' => -104.9708,
    'hero_text' => 'Highlands Ranch homeowners expect excellence, and that\'s exactly what we deliver. From the Backcountry to Eastridge, our technology-driven approach means transparent pricing, real-time updates, and a flawless finish.',
    'specialties_text' => 'Upscale residential interiors, large home exteriors, HOA-compliant painting, cabinet refinishing, deck staining',
],
```

- [ ] **Step 5: Run the seeder**

```bash
php artisan db:seed --class=ServiceAreaSeeder
```

This uses `updateOrCreate` so existing cities get coordinates added, and new cities get created. Verify:

```bash
php artisan tinker --execute="echo App\Models\ServiceArea::count() . ' areas, ' . App\Models\ServiceArea::whereNotNull('latitude')->count() . ' with coordinates';"
```

Expected: `31 areas, 31 with coordinates` (20 existing + 11 new: 10 I-25 corridor + Highlands Ranch).

- [ ] **Step 6: Regenerate the sitemap**

```bash
php artisan sitemap:generate
```

Expected output: `Sitemap generated with ~60 URLs.` (was 50, +10 new area pages).

- [ ] **Step 7: Commit**

```bash
git add database/seeders/ServiceAreaSeeder.php
git commit -m "feat(seo): add 10 I-25 corridor cities from Erie to Fort Collins

Expands service area coverage northward along I-25. Also adds
latitude/longitude to all existing seeder entries as single source
of truth for coordinates."
```

---

### Task 2: Write Tests for New Cities

**Files:**
- Modify: `tests/Feature/SEO/SchemaAndSitemapTest.php`
- Modify: `tests/Feature/LandingPage/ServiceAreaTest.php`

- [ ] **Step 1: Read existing test files**

Read both test files to understand patterns and avoid duplication.

- [ ] **Step 2: Add test for I-25 corridor cities loading area pages**

In `tests/Feature/LandingPage/ServiceAreaTest.php`, add:

```php
it('renders area pages for I-25 corridor expansion cities', function () {
    $corridorCities = [
        ['city' => 'Fort Collins', 'slug' => 'fort-collins-co'],
        ['city' => 'Loveland', 'slug' => 'loveland-co'],
        ['city' => 'Windsor', 'slug' => 'windsor-co'],
    ];

    foreach ($corridorCities as $data) {
        ServiceArea::factory()->create([
            'city' => $data['city'],
            'slug' => $data['slug'],
            'is_active' => true,
            'latitude' => 40.0,
            'longitude' => -105.0,
        ]);
    }

    foreach ($corridorCities as $data) {
        $this->get('/areas/' . $data['slug'])
            ->assertSuccessful()
            ->assertSee($data['city']);
    }
});
```

- [ ] **Step 3: Add test for new cities appearing on landing page map**

In `tests/Feature/SEO/SchemaAndSitemapTest.php`, in the 'Landing page schema' describe block, add:

```php
it('includes I-25 corridor cities in areaServed', function () {
    ServiceArea::factory()->create(['city' => 'Fort Collins', 'slug' => 'fort-collins-co', 'is_active' => true]);
    ServiceArea::factory()->create(['city' => 'Loveland', 'slug' => 'loveland-co', 'is_active' => true]);

    $response = $this->get('/');
    $response->assertSuccessful();

    $response->assertSee('Fort Collins', false);
    $response->assertSee('Loveland', false);
});
```

- [ ] **Step 4: Add test for new cities in sitemap**

In `tests/Feature/SEO/SchemaAndSitemapTest.php`, in the 'Sitemap' describe block, add:

```php
it('includes I-25 corridor service area pages', function () {
    ServiceArea::factory()->create(['city' => 'Fort Collins', 'slug' => 'fort-collins-co', 'is_active' => true]);
    ServiceArea::factory()->create(['city' => 'Loveland', 'slug' => 'loveland-co', 'is_active' => true]);

    $this->artisan('sitemap:generate')->assertSuccessful();
    $content = File::get(public_path('sitemap.xml'));

    expect($content)
        ->toContain(url('/areas/fort-collins-co'))
        ->toContain(url('/areas/loveland-co'));
});
```

- [ ] **Step 5: Run all tests**

```bash
php artisan test --compact tests/Feature/SEO/SchemaAndSitemapTest.php tests/Feature/LandingPage/ServiceAreaTest.php
```

Expected: All pass.

- [ ] **Step 6: Run Pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 7: Commit**

```bash
git add tests/Feature/SEO/SchemaAndSitemapTest.php tests/Feature/LandingPage/ServiceAreaTest.php
git commit -m "test: add coverage for I-25 corridor service area expansion"
```

---

### Task 3: Adjust Map and Copy for Expanded Coverage

Update the landing page map zoom/center and heading copy for the new I-25 corridor range.

**Files:**
- Modify: `resources/views/components/public/⚡landing-page.blade.php`

- [ ] **Step 1: Verify all cities have coordinates**

```bash
php artisan tinker --execute="App\Models\ServiceArea::active()->orderBy('city')->get(['city','latitude','longitude'])->each(fn(\$a) => print(\$a->city . ': ' . \$a->latitude . ', ' . \$a->longitude . PHP_EOL));"
```

Confirm all 31 cities print with coordinates. No nulls.

- [ ] **Step 2: Check the landing page map zoom level**

The landing page map is centered at `[39.75, -104.99]` with zoom `9`. Fort Collins is at `40.5853` — that's almost a full degree north. Zoom 9 may not show Fort Collins. Consider adjusting zoom to `8` in the landing page include.

Modify `/home/forge/apexnegotiations.com/resources/views/components/public/⚡landing-page.blade.php` — find the map include and change zoom from 9 to 8 and center slightly north:

```blade
@include('partials.service-area-map', [
    'areas' => $footerAreas,
    'center' => [39.95, -104.99],
    'zoom' => 8,
])
```

This recenters the map between Denver and Fort Collins and zooms out slightly so all markers are visible.

- [ ] **Step 3: Update the heading copy**

In the same map section on the landing page, update the text to reflect the expanded coverage:

Change: `'We Serve the Entire Denver Metro'` → `'Denver to Fort Collins — We\'ve Got You Covered'`
Change: `'20+ cities across the Front Range'` → `'30 cities along the Front Range and I-25 corridor'`

- [ ] **Step 4: Commit**

```bash
git add resources/views/components/public/⚡landing-page.blade.php
git commit -m "feat(map): adjust zoom and copy for I-25 corridor coverage"
```

---

### Task 4: Final Verification

- [ ] **Step 1: Run full SEO and service area test suites**

```bash
php artisan test --compact tests/Feature/SEO/ tests/Feature/LandingPage/ServiceAreaTest.php tests/Feature/SitemapTest.php
```

All must pass.

- [ ] **Step 2: Verify sitemap URL count**

```bash
php artisan sitemap:generate
grep -c '<url>' public/sitemap.xml
```

Expected: ~60 URLs.

- [ ] **Step 3: Spot-check new area pages render**

```bash
php artisan tinker --execute="\$r = app()->handle(Illuminate\Http\Request::create('/areas/fort-collins-co')); echo \$r->getStatusCode();"
php artisan tinker --execute="\$r = app()->handle(Illuminate\Http\Request::create('/areas/loveland-co')); echo \$r->getStatusCode();"
php artisan tinker --execute="\$r = app()->handle(Illuminate\Http\Request::create('/areas/erie-co')); echo \$r->getStatusCode();"
```

Expected: `200` for each.

- [ ] **Step 4: Run Pint one final time**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 5: Final commit if any formatting changes**

---

## Summary

| What | Count |
|------|-------|
| New cities added | 11 (10 I-25 corridor + Highlands Ranch) |
| Total service areas after | 31 |
| Files modified | 4 (seeder, 2 test files, landing page) |
| Files created | 0 |
| Schema changes | 0 |
| Map coverage | Castle Rock (south) to Fort Collins (north) |
| Automatically updated | Sitemap, landing page map, footer links, schema areaServed, area index |
