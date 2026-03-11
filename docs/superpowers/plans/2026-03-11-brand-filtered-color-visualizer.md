# Brand-Filtered Color Visualizer Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add brand selection, interior/exterior popular color carousels, larger color cards, and brand-filtered search to both color visualizer components.

**Architecture:** Database migration adds `intended_use` column to `paint_colors`. Model gets new scope + updated cache methods. Both Livewire visualizer components get brand selector pills, two carousel rows (interior/exterior popular), larger cards, brand-filtered search, and a prompt helper tip. Alpine.js handles carousel scrolling client-side.

**Tech Stack:** Laravel 12, Livewire 4, Alpine.js, Tailwind CSS v4, Pest

**Spec:** `docs/superpowers/specs/2026-03-11-brand-filtered-color-visualizer-design.md`

---

## Chunk 1: Database + Model Layer

### Task 1: Migration — Add `intended_use` column

**Files:**
- Create: `database/migrations/xxxx_add_intended_use_to_paint_colors_table.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration add_intended_use_to_paint_colors_table --table=paint_colors --no-interaction
```

- [ ] **Step 2: Write migration code**

```php
public function up(): void
{
    Schema::table('paint_colors', function (Blueprint $table) {
        $table->string('intended_use', 20)->default('both')->after('color_family');
        $table->index(['is_active', 'is_popular', 'intended_use', 'paint_brand_id'], 'paint_colors_popular_use_brand_index');
    });

    // Drop the old index that's superseded
    Schema::table('paint_colors', function (Blueprint $table) {
        if (DB::getDriverName() !== 'sqlite') {
            $table->dropIndex(['is_active', 'is_popular']);
        }
    });
}

public function down(): void
{
    Schema::table('paint_colors', function (Blueprint $table) {
        $table->dropIndex('paint_colors_popular_use_brand_index');
        $table->dropColumn('intended_use');
        $table->index(['is_active', 'is_popular']);
    });
}
```

Note: Add `use Illuminate\Support\Facades\DB;` to the migration imports. Use `DB::getDriverName()` check for SQLite compat (cannot drop indexes on SQLite easily). Use named index to avoid auto-name length issues on the 4-column composite.

- [ ] **Step 3: Run migration**

```bash
php artisan migrate
```

Expected: Migration runs successfully, `intended_use` column added with default `both`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*add_intended_use*
git commit -m "feat: add intended_use column to paint_colors table"
```

---

### Task 2: Update PaintColor model

**Files:**
- Modify: `app/Models/PaintColor.php`
- Test: `tests/Feature/Models/PaintColorSearchTest.php` (existing, add new tests)

- [ ] **Step 1: Write failing tests for new functionality**

Add these tests to `tests/Feature/Models/PaintColorSearchTest.php`:

```php
it('filters by intended use with forIntendedUse scope', function () {
    $brand = PaintBrand::factory()->sherwinWilliams()->create();
    $interior = PaintColor::factory()->forBrand($brand)->popular()->create([
        'name' => 'Agreeable Gray',
        'intended_use' => 'interior',
    ]);
    $exterior = PaintColor::factory()->forBrand($brand)->popular()->create([
        'name' => 'Iron Ore',
        'intended_use' => 'exterior',
    ]);
    $both = PaintColor::factory()->forBrand($brand)->popular()->create([
        'name' => 'Alabaster',
        'intended_use' => 'both',
    ]);

    $interiorResults = PaintColor::active()->forIntendedUse('interior')->pluck('name');
    expect($interiorResults)->toContain('Agreeable Gray', 'Alabaster')
        ->not->toContain('Iron Ore');

    $exteriorResults = PaintColor::active()->forIntendedUse('exterior')->pluck('name');
    expect($exteriorResults)->toContain('Iron Ore', 'Alabaster')
        ->not->toContain('Agreeable Gray');
});

it('gets cached popular colors filtered by intended use', function () {
    $brand = PaintBrand::factory()->sherwinWilliams()->create();
    PaintColor::factory()->forBrand($brand)->popular()->create([
        'name' => 'Interior Only',
        'intended_use' => 'interior',
    ]);
    PaintColor::factory()->forBrand($brand)->popular()->create([
        'name' => 'Exterior Only',
        'intended_use' => 'exterior',
    ]);
    PaintColor::factory()->forBrand($brand)->popular()->create([
        'name' => 'Works Everywhere',
        'intended_use' => 'both',
    ]);

    $interiorColors = PaintColor::getCachedPopularColors($brand->id, 'interior');
    expect($interiorColors->pluck('name')->toArray())
        ->toContain('Interior Only', 'Works Everywhere')
        ->not->toContain('Exterior Only');

    $exteriorColors = PaintColor::getCachedPopularColors($brand->id, 'exterior');
    expect($exteriorColors->pluck('name')->toArray())
        ->toContain('Exterior Only', 'Works Everywhere')
        ->not->toContain('Interior Only');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter="filters by intended use|gets cached popular colors filtered" tests/Feature/Models/PaintColorSearchTest.php
```

Expected: FAIL — `forIntendedUse` scope not found, `getCachedPopularColors` doesn't accept second parameter.

- [ ] **Step 3: Update PaintColor model**

In `app/Models/PaintColor.php`:

1. Add `'intended_use'` to `$fillable` array (after `'color_family'`).

2. Add `'intended_use' => 'string'` to the `casts()` method return array.

3. Add new scope after `scopeInFamily`:

```php
public function scopeForIntendedUse(Builder $query, string $use): Builder
{
    return $query->whereIn('intended_use', [$use, 'both']);
}
```

4. Update `getCachedPopularColors` signature and logic:

```php
public static function getCachedPopularColors(?int $brandId = null, ?string $intendedUse = null): Collection
{
    $key = 'paint_colors:popular:' . ($brandId ?? 'all') . ':' . ($intendedUse ?? 'all');

    return Cache::remember($key, 86400, function () use ($brandId, $intendedUse) {
        $query = static::active()->popular()->with('brand')->orderBy('name');

        if ($brandId) {
            $query->where('paint_brand_id', $brandId);
        }

        if ($intendedUse) {
            $query->forIntendedUse($intendedUse);
        }

        return $query->limit(10)->get();
    });
}
```

5. Update `clearCache` to flush new key patterns:

```php
public static function clearCache(): void
{
    $uses = ['all', 'interior', 'exterior'];

    Cache::forget('paint_colors:families');

    $brandIds = PaintBrand::pluck('id')->push('all');

    foreach ($brandIds as $brandId) {
        foreach ($uses as $use) {
            Cache::forget("paint_colors:popular:{$brandId}:{$use}");
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/Models/PaintColorSearchTest.php
```

Expected: All tests pass including the 2 new ones.

- [ ] **Step 5: Commit**

```bash
git add app/Models/PaintColor.php tests/Feature/Models/PaintColorSearchTest.php
git commit -m "feat: add intended_use scope and cache filtering to PaintColor"
```

---

### Task 3: Update PaintColor factory

**Files:**
- Modify: `database/factories/PaintColorFactory.php`

- [ ] **Step 1: Add intended_use to factory definition and add states**

In `database/factories/PaintColorFactory.php`:

1. Add to `definition()` return array:
```php
'intended_use' => 'both',
```

2. Add factory states after the existing `withHex` method:

```php
public function interior(): static
{
    return $this->state(fn (array $attributes) => ['intended_use' => 'interior']);
}

public function exterior(): static
{
    return $this->state(fn (array $attributes) => ['intended_use' => 'exterior']);
}
```

- [ ] **Step 2: Run existing tests to verify nothing broke**

```bash
php artisan test --compact tests/Feature/Models/PaintColorSearchTest.php tests/Feature/Ai/PaintColorLookupToolTest.php
```

Expected: All pass.

- [ ] **Step 3: Commit**

```bash
git add database/factories/PaintColorFactory.php
git commit -m "feat: add intended_use default and factory states to PaintColorFactory"
```

---

### Task 4: Update seeder to read intended_use from JSON

**Files:**
- Modify: `database/seeders/PaintColorSeeder.php`

- [ ] **Step 1: Update seeder to read `intended_use` from JSON data**

In `database/seeders/PaintColorSeeder.php`, in the `updateOrCreate` call inside `seedFromFile`, add:

```php
'intended_use' => $color['intended_use'] ?? 'both',
```

Add it to the second array (the attributes to update), after `'is_active' => true,`.

- [ ] **Step 2: Commit**

```bash
git add database/seeders/PaintColorSeeder.php
git commit -m "feat: seeder reads intended_use from color JSON data"
```

---

### Task 4b: Tag existing popular colors with intended_use via data migration

**Files:**
- Create: `database/migrations/xxxx_tag_popular_paint_colors_intended_use.php`

Without this step, all popular colors have `intended_use = 'both'` and the interior/exterior carousels show identical content.

- [ ] **Step 1: Create data migration**

```bash
php artisan make:migration tag_popular_paint_colors_intended_use --no-interaction
```

- [ ] **Step 2: Write migration with heuristic tagging**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Exterior-only: colors commonly used for house body/trim/accents
        // These are identified by color_family or specific well-known exterior colors
        DB::table('paint_colors')
            ->where('is_popular', true)
            ->where('intended_use', 'both')
            ->where(function ($q) {
                $q->whereIn('color_family', ['red', 'deep blue', 'forest green', 'navy', 'dark green'])
                    ->orWhere('name', 'like', '%Exterior%')
                    ->orWhere('name', 'like', '%Siding%');
            })
            ->update(['intended_use' => 'exterior']);

        // Interior-only: soft pastels, accent wall colors, light warm tones
        DB::table('paint_colors')
            ->where('is_popular', true)
            ->where('intended_use', 'both')
            ->where(function ($q) {
                $q->whereIn('color_family', ['pink', 'lavender', 'peach', 'lilac', 'mauve'])
                    ->orWhere('name', 'like', '%Nursery%')
                    ->orWhere('name', 'like', '%Accent%');
            })
            ->update(['intended_use' => 'interior']);

        // Everything else stays 'both' (whites, grays, beiges, neutrals)
        // These are versatile and appropriate for both interior and exterior use
    }

    public function down(): void
    {
        DB::table('paint_colors')
            ->where('is_popular', true)
            ->whereIn('intended_use', ['interior', 'exterior'])
            ->update(['intended_use' => 'both']);
    }
};
```

Note: This heuristic is intentionally conservative — most popular colors stay `both` (whites, grays, neutrals work everywhere). The team can fine-tune via JSON files or admin UI later. The important thing is that *some* colors get tagged so the carousels aren't identical.

- [ ] **Step 3: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*tag_popular_paint_colors*
git commit -m "feat: tag popular paint colors with intended_use via data migration"
```

---

## Chunk 2: Estimates Color Visualizer (Internal)

### Task 5: Add brand selector and carousel state to estimates visualizer

**Files:**
- Modify: `resources/views/components/estimates/⚡color-visualizer.blade.php` (PHP section, lines 1-197)

- [ ] **Step 1: Write failing test for brand selection**

Create `tests/Feature/Livewire/Estimates/ColorVisualizerBrandFilterTest.php`:

```bash
php artisan make:test --pest Livewire/Estimates/ColorVisualizerBrandFilterTest --no-interaction
```

```php
<?php

use App\Models\Estimate;
use App\Models\Deal;
use App\Models\PaintBrand;
use App\Models\PaintColor;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();

    $this->sw = PaintBrand::factory()->sherwinWilliams()->create();
    $this->bm = PaintBrand::factory()->benjaminMoore()->create();

    // SW popular colors
    PaintColor::factory()->forBrand($this->sw)->popular()->create([
        'name' => 'Agreeable Gray', 'intended_use' => 'interior',
    ]);
    PaintColor::factory()->forBrand($this->sw)->popular()->create([
        'name' => 'Iron Ore', 'intended_use' => 'exterior',
    ]);
    PaintColor::factory()->forBrand($this->sw)->popular()->create([
        'name' => 'Alabaster', 'intended_use' => 'both',
    ]);

    // BM popular colors
    PaintColor::factory()->forBrand($this->bm)->popular()->create([
        'name' => 'White Dove', 'intended_use' => 'interior',
    ]);
});

it('renders available brands', function () {
    Livewire::actingAs($this->user)
        ->test('estimates.color-visualizer')
        ->assertSee($this->sw->name)
        ->assertSee($this->bm->name);
});

it('shows no popular colors until a brand is selected', function () {
    Livewire::actingAs($this->user)
        ->test('estimates.color-visualizer')
        ->assertDontSee('Agreeable Gray')
        ->assertDontSee('White Dove')
        ->assertSee('Select a brand');
});

it('shows interior popular colors for selected brand', function () {
    Livewire::actingAs($this->user)
        ->test('estimates.color-visualizer')
        ->call('selectBrand', $this->sw->id)
        ->assertSee('Agreeable Gray')  // interior
        ->assertSee('Alabaster')       // both
        ->assertDontSee('White Dove'); // wrong brand
});

it('shows exterior popular colors for selected brand', function () {
    Livewire::actingAs($this->user)
        ->test('estimates.color-visualizer')
        ->call('selectBrand', $this->sw->id)
        ->assertSee('Iron Ore')         // exterior
        ->assertSee('Alabaster');        // both (shows in both rows)
});

it('toggles brand off when clicking same brand again', function () {
    Livewire::actingAs($this->user)
        ->test('estimates.color-visualizer')
        ->call('selectBrand', $this->sw->id)
        ->assertSet('selectedBrandId', $this->sw->id)
        ->call('selectBrand', $this->sw->id)
        ->assertSet('selectedBrandId', null);
});

it('filters search results by selected brand', function () {
    // Non-popular searchable colors
    PaintColor::factory()->forBrand($this->sw)->create(['name' => 'Snowbound']);
    PaintColor::factory()->forBrand($this->bm)->create(['name' => 'Snow White']);

    Livewire::actingAs($this->user)
        ->test('estimates.color-visualizer')
        ->call('selectBrand', $this->sw->id)
        ->set('colorSearch', 'Snow')
        ->assertSee('Snowbound')
        ->assertDontSee('Snow White');
});

it('searches all brands when no brand selected', function () {
    PaintColor::factory()->forBrand($this->sw)->create(['name' => 'Snowbound']);
    PaintColor::factory()->forBrand($this->bm)->create(['name' => 'Snow White']);

    Livewire::actingAs($this->user)
        ->test('estimates.color-visualizer')
        ->set('colorSearch', 'Snow')
        ->assertSee('Snowbound')
        ->assertSee('Snow White');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Livewire/Estimates/ColorVisualizerBrandFilterTest.php
```

Expected: FAIL — `selectBrand` method not found, `selectedBrandId` property not found.

- [ ] **Step 3: Add Livewire properties and methods to estimates visualizer PHP section**

In `resources/views/components/estimates/⚡color-visualizer.blade.php`, add these imports at the top (after existing `use` statements):

```php
use App\Models\PaintBrand;
```

Add these properties after `public string $colorSearch = '';` (line 30):

```php
public ?int $selectedBrandId = null;
```

Add `selectBrand` method after `selectSearchColor`:

```php
public function selectBrand(int $brandId): void
{
    $this->selectedBrandId = $this->selectedBrandId === $brandId ? null : $brandId;
    unset($this->interiorPopularColors, $this->exteriorPopularColors, $this->colorSearchResults);
}
```

Replace the existing `popularColors` computed property with two new computed properties:

```php
#[Computed]
public function availableBrands()
{
    return PaintBrand::active()->ordered()->get();
}

#[Computed]
public function interiorPopularColors()
{
    if (! $this->selectedBrandId) {
        return collect();
    }

    return PaintColor::getCachedPopularColors($this->selectedBrandId, 'interior');
}

#[Computed]
public function exteriorPopularColors()
{
    if (! $this->selectedBrandId) {
        return collect();
    }

    return PaintColor::getCachedPopularColors($this->selectedBrandId, 'exterior');
}
```

Update `colorSearchResults` to filter by selected brand:

```php
#[Computed]
public function colorSearchResults()
{
    if (strlen($this->colorSearch) < 2) {
        return collect();
    }

    $query = PaintColor::query()
        ->with('brand')
        ->active()
        ->search($this->colorSearch);

    if ($this->selectedBrandId) {
        $query->where('paint_brand_id', $this->selectedBrandId);
    }

    return $query
        ->orderByRaw('CASE WHEN name LIKE ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END', [$this->colorSearch . '%', '%' . $this->colorSearch . '%'])
        ->limit(12)
        ->get();
}
```

- [ ] **Step 4: Fix existing test that references old `popularColors` computed**

The existing test `loads popular colors` (line 39-46 of `ColorVisualizerTest.php`) expects popular colors to show without brand selection. Update it to call `selectBrand` first:

```php
it('loads popular colors after brand selection', function () {
    $brand = PaintBrand::factory()->create(['name' => 'Sherwin-Williams']);
    PaintColor::factory()->forBrand($brand)->popular()->create([
        'name' => 'Alabaster',
        'intended_use' => 'both',
    ]);

    Livewire::actingAs($this->internalUser)
        ->test('estimates.color-visualizer')
        ->call('selectBrand', $brand->id)
        ->assertSee('Alabaster');
});
```

- [ ] **Step 5: Run all tests to verify they pass**

```bash
php artisan test --compact tests/Feature/Livewire/Estimates/ColorVisualizerBrandFilterTest.php tests/Feature/Livewire/Estimates/ColorVisualizerTest.php tests/Feature/Livewire/Estimates/ColorVisualizerAddToEstimateTest.php
```

Expected: All pass.

- [ ] **Step 6: Commit**

```bash
git add "resources/views/components/estimates/⚡color-visualizer.blade.php" tests/Feature/Livewire/Estimates/ColorVisualizerBrandFilterTest.php tests/Feature/Livewire/Estimates/ColorVisualizerTest.php
git commit -m "feat: add brand selection and filtered popular colors to estimates visualizer"
```

---

### Task 6: Update estimates visualizer Blade template

**Files:**
- Modify: `resources/views/components/estimates/⚡color-visualizer.blade.php` (Blade section, lines 198-496)

- [ ] **Step 1: Replace Popular Colors section with brand selector + carousels**

Replace the entire block from `{{-- Popular Colors --}}` through the closing `@endif` (lines 347-369) with:

```blade
{{-- Brand Selector --}}
<div>
    <flux:heading size="sm" class="mb-3">{{ __('Paint Brand') }}</flux:heading>
    <div class="flex flex-wrap gap-2">
        @foreach ($this->availableBrands as $brand)
            <button
                wire:key="brand-{{ $brand->id }}"
                wire:click="selectBrand({{ $brand->id }})"
                class="px-4 py-2 rounded-lg text-sm font-medium transition-all
                    {{ $selectedBrandId === $brand->id
                        ? 'bg-purple-600 text-white shadow-md'
                        : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-700' }}"
            >
                {{ $brand->name }}
            </button>
        @endforeach
    </div>
</div>

{{-- Popular Colors Carousels --}}
@if ($selectedBrandId)
    @foreach (['interior' => 'Interior Popular', 'exterior' => 'Exterior Popular'] as $useType => $label)
        @php $colors = $useType === 'interior' ? $this->interiorPopularColors : $this->exteriorPopularColors; @endphp
        @if ($colors->isNotEmpty())
            <div>
                <flux:heading size="sm" class="mb-3">{{ __($label) }}</flux:heading>
                <div
                    x-data="{ scrollContainer: null }"
                    x-init="scrollContainer = $refs.carousel_{{ $useType }}"
                    class="relative group"
                >
                    {{-- Left arrow --}}
                    <button
                        x-on:click="scrollContainer.scrollBy({ left: -280, behavior: 'smooth' })"
                        class="hidden sm:flex absolute -left-3 top-1/2 -translate-y-1/2 z-10 items-center justify-center size-8 rounded-full bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-600 shadow-md opacity-0 group-hover:opacity-100 transition-opacity"
                    >
                        <flux:icon.chevron-left class="size-4" />
                    </button>

                    {{-- Carousel --}}
                    <div
                        x-ref="carousel_{{ $useType }}"
                        class="flex gap-3 overflow-x-auto scroll-smooth snap-x snap-mandatory scrollbar-hide pb-1"
                        style="-ms-overflow-style: none; scrollbar-width: none;"
                    >
                        @foreach ($colors as $color)
                            <button
                                wire:key="popular-{{ $useType }}-{{ $color->id }}"
                                wire:click="insertColorName({{ $color->id }})"
                                x-data="{ tapped: false }"
                                x-on:click="tapped = true; setTimeout(() => tapped = false, 600)"
                                class="snap-start shrink-0 w-32 rounded-lg border-2 overflow-hidden transition-all hover:shadow-md cursor-pointer"
                                :class="tapped ? 'border-green-500 shadow-lg' : 'border-zinc-200 dark:border-zinc-700'"
                                title="{{ $color->brand->name }} {{ $color->name }} ({{ $color->code }})"
                            >
                                <div class="h-20 w-full" style="background-color: {{ $color->hex_value }}"></div>
                                <div class="p-2 bg-white dark:bg-zinc-800">
                                    <div class="text-xs font-medium text-zinc-900 dark:text-zinc-100 truncate">{{ $color->name }}</div>
                                    <div class="text-[10px] text-zinc-500 dark:text-zinc-400">{{ $color->code }}</div>
                                </div>
                            </button>
                        @endforeach
                    </div>

                    {{-- Right arrow --}}
                    <button
                        x-on:click="scrollContainer.scrollBy({ left: 280, behavior: 'smooth' })"
                        class="hidden sm:flex absolute -right-3 top-1/2 -translate-y-1/2 z-10 items-center justify-center size-8 rounded-full bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-600 shadow-md opacity-0 group-hover:opacity-100 transition-opacity"
                    >
                        <flux:icon.chevron-right class="size-4" />
                    </button>
                </div>
            </div>
        @endif
    @endforeach
@else
    <div class="text-center py-4 text-sm text-zinc-500 dark:text-zinc-400">
        {{ __('Select a brand to see popular colors') }}
    </div>
@endif
```

- [ ] **Step 2: Update search placeholder to show selected brand**

Replace the search input placeholder (line ~299):

```blade
placeholder="{{ $selectedBrandId ? __('Search :brand colors...', ['brand' => $this->availableBrands->firstWhere('id', $selectedBrandId)?->code ?? '']) : __('Search 12,000+ colors by name or code...') }}"
```

- [ ] **Step 3: Add prompt helper tip above the textarea**

Before the `flux:textarea` for the prompt (line ~337), add:

```blade
<p class="text-xs text-zinc-500 dark:text-zinc-400 mb-2">
    {{ __('Tip: "Paint [surface] [color name]" — e.g. "Paint walls Agreeable Gray"') }}
</p>
```

- [ ] **Step 4: Add empty search state for brand-filtered searches**

In the search results dropdown section, after the `@foreach` loop and its closing `</div>`, add an empty state when search has text but no results with a brand selected:

```blade
@if ($this->colorSearchResults->isEmpty() && strlen($colorSearch) >= 2)
    <div
        x-show="open"
        class="absolute z-50 mt-1 w-full bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700 shadow-lg p-4 text-center"
    >
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            @if ($selectedBrandId)
                {{ __('No colors found for ":query" in :brand', ['query' => $colorSearch, 'brand' => $this->availableBrands->firstWhere('id', $selectedBrandId)?->name ?? '']) }}
            @else
                {{ __('No colors found for ":query"', ['query' => $colorSearch]) }}
            @endif
        </p>
    </div>
@endif
```

- [ ] **Step 5: Run all estimates visualizer tests**

```bash
php artisan test --compact tests/Feature/Livewire/Estimates/ColorVisualizerTest.php tests/Feature/Livewire/Estimates/ColorVisualizerAddToEstimateTest.php tests/Feature/Livewire/Estimates/ColorVisualizerBrandFilterTest.php
```

Expected: All pass.

- [ ] **Step 6: Commit**

```bash
git add "resources/views/components/estimates/⚡color-visualizer.blade.php"
git commit -m "feat: brand selector, carousel UI, and prompt tip in estimates visualizer"
```

---

## Chunk 3: Public Color Visualizer

### Task 7: Add brand selector and carousel state to public visualizer

**Files:**
- Modify: `resources/views/components/public/⚡color-visualizer.blade.php` (PHP section, lines 1-189)

- [ ] **Step 1: Write failing test for public visualizer brand selection**

Create `tests/Feature/Livewire/Public/ColorVisualizerBrandFilterTest.php`:

```bash
php artisan make:test --pest Livewire/Public/ColorVisualizerBrandFilterTest --no-interaction
```

```php
<?php

use App\Models\PaintBrand;
use App\Models\PaintColor;
use Livewire\Livewire;

beforeEach(function () {
    $this->sw = PaintBrand::factory()->sherwinWilliams()->create();
    $this->bm = PaintBrand::factory()->benjaminMoore()->create();

    PaintColor::factory()->forBrand($this->sw)->popular()->create([
        'name' => 'Agreeable Gray', 'intended_use' => 'interior',
    ]);
    PaintColor::factory()->forBrand($this->sw)->popular()->create([
        'name' => 'Iron Ore', 'intended_use' => 'exterior',
    ]);
    PaintColor::factory()->forBrand($this->bm)->popular()->create([
        'name' => 'White Dove', 'intended_use' => 'both',
    ]);
});

it('renders brand selector on public visualizer', function () {
    Livewire::test('public.color-visualizer')
        ->assertSee($this->sw->name)
        ->assertSee($this->bm->name);
});

it('shows no popular colors until brand is selected on public visualizer', function () {
    Livewire::test('public.color-visualizer')
        ->assertSee('Select a brand')
        ->assertDontSee('Agreeable Gray');
});

it('shows interior and exterior popular colors for selected brand on public visualizer', function () {
    Livewire::test('public.color-visualizer')
        ->call('selectBrand', $this->sw->id)
        ->assertSee('Agreeable Gray')
        ->assertSee('Iron Ore');
});

it('filters search by brand on public visualizer', function () {
    PaintColor::factory()->forBrand($this->sw)->create(['name' => 'Snowbound']);
    PaintColor::factory()->forBrand($this->bm)->create(['name' => 'Snow White']);

    Livewire::test('public.color-visualizer')
        ->call('selectBrand', $this->sw->id)
        ->set('colorSearch', 'Snow')
        ->assertSee('Snowbound')
        ->assertDontSee('Snow White');
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Livewire/Public/ColorVisualizerBrandFilterTest.php
```

Expected: FAIL — method/property not found.

- [ ] **Step 3: Add same Livewire properties and methods to public visualizer PHP section**

In `resources/views/components/public/⚡color-visualizer.blade.php`, apply the same PHP changes as Task 5 Step 3:

1. Add `use App\Models\PaintBrand;` to imports.
2. Add `public ?int $selectedBrandId = null;` property.
3. Add `selectBrand()` method.
4. Replace `popularColors` computed with `availableBrands`, `interiorPopularColors`, `exteriorPopularColors`.
5. Update `colorSearchResults` to filter by `selectedBrandId`.

These are identical to the estimates version — the same PHP logic applies.

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/Livewire/Public/ColorVisualizerBrandFilterTest.php
```

Expected: All 4 pass.

- [ ] **Step 5: Commit**

```bash
git add "resources/views/components/public/⚡color-visualizer.blade.php" tests/Feature/Livewire/Public/ColorVisualizerBrandFilterTest.php
git commit -m "feat: add brand selection and filtered popular colors to public visualizer"
```

---

### Task 8: Update public visualizer Blade template

**Files:**
- Modify: `resources/views/components/public/⚡color-visualizer.blade.php` (Blade section, lines 190-473)

- [ ] **Step 1: Replace Popular Colors section with brand selector + carousels**

Replace the entire block from `{{-- Popular colors --}}` (lines 407-425) with the same brand selector + carousel markup from Task 6 Step 1. The Blade markup is identical — same brand pills, same carousel structure, same color cards, same "Select a brand" fallback.

The public version uses slightly different Tailwind classes (blue instead of purple for brand highlight). Adjust the active brand button class:

```blade
{{ $selectedBrandId === $brand->id
    ? 'bg-blue-600 text-white shadow-md'
    : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-700' }}
```

- [ ] **Step 2: Update search placeholder**

In the color search input (line ~361), update placeholder:

```blade
placeholder="{{ $selectedBrandId ? __('Search :brand colors...', ['brand' => $this->availableBrands->firstWhere('id', $selectedBrandId)?->code ?? '']) : __('Search 12,000+ colors by name or code...') }}"
```

- [ ] **Step 3: Add prompt helper tip above the textarea**

Before the `flux:textarea` (line ~400), add:

```blade
<p class="text-xs text-zinc-500 dark:text-zinc-400 mb-1">
    {{ __('Tip: "Paint [surface] [color name]" — e.g. "Paint walls Agreeable Gray"') }}
</p>
```

- [ ] **Step 4: Run all public visualizer tests**

```bash
php artisan test --compact tests/Feature/Livewire/Public/ColorVisualizerBrandFilterTest.php
```

Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add "resources/views/components/public/⚡color-visualizer.blade.php"
git commit -m "feat: brand selector, carousel UI, and prompt tip in public visualizer"
```

---

## Chunk 4: Final Verification

### Task 9: Run full test suite for affected areas

- [ ] **Step 1: Run all color visualizer tests**

```bash
php artisan test --compact tests/Feature/Livewire/Estimates/ColorVisualizerTest.php tests/Feature/Livewire/Estimates/ColorVisualizerAddToEstimateTest.php tests/Feature/Livewire/Estimates/ColorVisualizerBrandFilterTest.php tests/Feature/Livewire/Public/ColorVisualizerBrandFilterTest.php
```

Expected: All pass.

- [ ] **Step 2: Run paint color model tests**

```bash
php artisan test --compact tests/Feature/Models/PaintColorSearchTest.php tests/Feature/Ai/PaintColorLookupToolTest.php
```

Expected: All pass.

- [ ] **Step 3: Run Pint formatter**

```bash
vendor/bin/pint --dirty
```

Expected: Pass or auto-fix formatting.

- [ ] **Step 4: Final commit if pint made changes**

```bash
git add -A && git commit -m "style: format with pint"
```

- [ ] **Step 5: Ask user if they want to run full test suite**

The full estimate test suite can be run to ensure nothing else broke:

```bash
php artisan test --compact tests/Feature/Livewire/Estimates/
```

Note: Pre-existing failures (ProfitBreakdownTest floating point, CostBreakdownTest auth redirects) are unrelated to this work.
