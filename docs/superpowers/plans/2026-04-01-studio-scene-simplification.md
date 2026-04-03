# Studio Scene System Simplification — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate scattered scene/overlay state by consolidating into a single source of truth — one Alpine array, one DB column, auto-save on every change.

**Architecture:** Three clean layers: immutable base layouts (slot geometry) → user's saved scene library (one scene per row) → event working copies (scenes array with overlays embedded). All overlay data lives inside the scene object. `live_events.overlays` column is dropped. Auto-save replaces manual save-on-close.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Alpine.js, Canvas API (FrameCompositor)

**Spec:** `docs/superpowers/specs/2026-04-01-studio-scene-simplification-design.md`

---

## File Map

| File | Action | Purpose |
|------|--------|---------|
| `database/migrations/2026_04_01_000001_merge_overlays_into_scenes_on_live_events.php` | Create | Merge global overlays into each scene, drop `overlays` column |
| `database/migrations/2026_04_01_000002_restructure_live_event_scene_templates.php` | Create | Rename `scenes`→`scene_data`, add `base_layout`, drop `overlays`, split rows |
| `app/Models/LiveEvent.php` | Modify | Remove `overlays` from casts/fillable |
| `app/Models/LiveEventSceneTemplate.php` | Modify | New casts, fillable, rename `defaultScenes()`→`defaultLayouts()` |
| `resources/views/pages/events/⚡manage.blade.php` | Modify | Refactor Livewire methods + Alpine state + pre-live UI |
| `resources/js/studio/FrameCompositor.js` | Modify | `setScene()` always sets overlays atomically |
| `tests/Feature/Livewire/Events/ManageEventProductionTest.php` | Modify | Update overlay/template tests for new data model |

---

### Task 1: Migration — Merge Overlays Into Scenes on `live_events`

**Files:**
- Create: `database/migrations/2026_04_01_000001_merge_overlays_into_scenes_on_live_events.php`
- Modify: `app/Models/LiveEvent.php` (lines 16-40 fillable, lines 42-54 casts)

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration merge_overlays_into_scenes_on_live_events --no-interaction
```

- [ ] **Step 2: Write migration logic**

Open the generated migration file and write:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Merge global overlays into each scene's overlays array
        DB::table('live_events')
            ->whereNotNull('overlays')
            ->where('overlays', '!=', '[]')
            ->where('overlays', '!=', 'null')
            ->orderBy('id')
            ->each(function ($event) {
                $globalOverlays = json_decode($event->overlays, true) ?? [];
                $scenes = json_decode($event->scenes, true) ?? [];

                if (empty($globalOverlays) || empty($scenes)) {
                    return;
                }

                foreach ($scenes as &$scene) {
                    $existingOverlays = $scene['overlays'] ?? [];
                    // Merge global overlays that aren't already in the scene
                    $scene['overlays'] = array_merge($existingOverlays, $globalOverlays);
                }

                DB::table('live_events')
                    ->where('id', $event->id)
                    ->update(['scenes' => json_encode($scenes)]);
            });

        Schema::table('live_events', function (Blueprint $table) {
            $table->dropColumn('overlays');
        });
    }

    public function down(): void
    {
        Schema::table('live_events', function (Blueprint $table) {
            $table->json('overlays')->nullable()->after('active_scene_index');
        });
    }
};
```

- [ ] **Step 3: Update `LiveEvent` model — remove overlays**

In `app/Models/LiveEvent.php`, remove `'overlays'` from the `$fillable` array (line ~37) and from the `casts()` method (line ~51).

Remove this line from `$fillable`:
```php
'overlays',
```

Remove this line from `casts()`:
```php
'overlays' => 'array',
```

- [ ] **Step 4: Run the migration**

```bash
php artisan migrate
```

- [ ] **Step 5: Commit**

```bash
git add database/migrations/*merge_overlays* app/Models/LiveEvent.php
git commit -m "refactor(studio): merge global overlays into scenes, drop overlays column"
```

---

### Task 2: Migration — Restructure `live_event_scene_templates`

**Files:**
- Create: `database/migrations/2026_04_01_000002_restructure_live_event_scene_templates.php`
- Modify: `app/Models/LiveEventSceneTemplate.php` (full file)

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration restructure_live_event_scene_templates --no-interaction
```

- [ ] **Step 2: Write migration logic**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Expand existing rows — each scene in the old `scenes` array becomes its own row
        $existingTemplates = DB::table('live_event_scene_templates')->get();

        $newRows = [];
        foreach ($existingTemplates as $template) {
            $scenes = json_decode($template->scenes, true) ?? [];
            $globalOverlays = json_decode($template->overlays, true) ?? [];

            if (empty($scenes)) {
                continue;
            }

            foreach ($scenes as $scene) {
                // Merge global overlays into each scene if scene doesn't have its own
                if (empty($scene['overlays'] ?? []) && ! empty($globalOverlays)) {
                    $scene['overlays'] = $globalOverlays;
                }

                $newRows[] = [
                    'user_id' => $template->user_id,
                    'name' => $template->name . ' — ' . ($scene['name'] ?? 'Scene'),
                    'scenes' => json_encode($scene), // Temporarily reuse column
                    'overlays' => null,
                    'created_at' => $template->created_at,
                    'updated_at' => $template->updated_at,
                ];
            }
        }

        // Step 2: Clear and re-insert
        DB::table('live_event_scene_templates')->truncate();

        foreach ($newRows as $row) {
            DB::table('live_event_scene_templates')->insert($row);
        }

        // Step 3: Schema changes
        Schema::table('live_event_scene_templates', function (Blueprint $table) {
            $table->renameColumn('scenes', 'scene_data');
        });

        Schema::table('live_event_scene_templates', function (Blueprint $table) {
            $table->string('base_layout')->nullable()->after('name');
            $table->dropColumn('overlays');
        });
    }

    public function down(): void
    {
        Schema::table('live_event_scene_templates', function (Blueprint $table) {
            $table->renameColumn('scene_data', 'scenes');
        });

        Schema::table('live_event_scene_templates', function (Blueprint $table) {
            $table->json('overlays')->nullable()->after('scenes');
            $table->dropColumn('base_layout');
        });
    }
};
```

- [ ] **Step 3: Update `LiveEventSceneTemplate` model**

Replace the full model at `app/Models/LiveEventSceneTemplate.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveEventSceneTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'base_layout',
        'scene_data',
    ];

    protected function casts(): array
    {
        return [
            'scene_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Default layout presets — slot geometry only, no overlays.
     *
     * @return array<int, array{name: string, slots: array}>
     */
    public static function defaultLayouts(): array
    {
        return [
            [
                'name' => 'Solo',
                'slots' => [
                    ['source' => 'host', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'label' => 'A'],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Interview',
                'slots' => [
                    ['source' => 'host', 'x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 1, 'label' => 'A'],
                    ['source' => 'guest-1', 'x' => 0.5, 'y' => 0, 'w' => 0.5, 'h' => 1, 'label' => 'B'],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Guest Spotlight',
                'slots' => [
                    ['source' => 'guest-1', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'label' => 'A'],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Screen Share',
                'slots' => [
                    ['source' => 'screen', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'label' => 'A'],
                ],
                'overlays' => [],
            ],
            [
                'name' => 'Picture-in-Picture',
                'slots' => [
                    ['source' => 'guest-1', 'x' => 0, 'y' => 0, 'w' => 1, 'h' => 1, 'label' => 'A'],
                    ['source' => 'host', 'x' => 0.73, 'y' => 0.70, 'w' => 0.25, 'h' => 0.25, 'label' => 'B'],
                ],
                'overlays' => [],
            ],
        ];
    }

    /**
     * Backward-compatible alias.
     */
    public static function defaultScenes(): array
    {
        return static::defaultLayouts();
    }
}
```

- [ ] **Step 4: Run the migration**

```bash
php artisan migrate
```

- [ ] **Step 5: Commit**

```bash
git add database/migrations/*restructure_live_event* app/Models/LiveEventSceneTemplate.php
git commit -m "refactor(studio): restructure scene templates to one-scene-per-row"
```

---

### Task 3: Update Tests for New Data Model

**Files:**
- Modify: `tests/Feature/Livewire/Events/ManageEventProductionTest.php`

- [ ] **Step 1: Update overlay tests to use scene-embedded overlays**

The following tests need updating because `addTextOverlay`, `removeOverlay` (pre-live), and `updateOverlays` Livewire methods will be removed. The overlay data now lives inside `event.scenes`.

Replace the test `'adds a text overlay'` (line 165-176) with a test that adds overlays via `updateScenes`:

```php
it('stores overlays within scene data', function () {
    $scenes = LiveEventSceneTemplate::defaultLayouts();
    $scenes[0]['overlays'] = [
        ['type' => 'text', 'content' => 'Hello World', 'visible' => true, 'x' => 0.1, 'y' => 0.85],
    ];

    Livewire::actingAs($this->internal)
        ->test('pages::events.manage', ['liveEvent' => $this->event])
        ->call('updateScenes', $scenes);

    $updatedScenes = $this->event->fresh()->scenes;
    expect($updatedScenes[0]['overlays'])->toHaveCount(1)
        ->and($updatedScenes[0]['overlays'][0]['content'])->toBe('Hello World');
});
```

Replace `'validates overlay text is required'` (line 178-184) — remove this test entirely (no longer a Livewire validation, overlays managed in Alpine).

Replace `'removes an overlay by index'` (line 186-200) with:

```php
it('removes overlays via updateScenes', function () {
    $scenes = LiveEventSceneTemplate::defaultLayouts();
    $scenes[0]['overlays'] = [
        ['type' => 'text', 'content' => 'Keep me', 'visible' => true],
        ['type' => 'text', 'content' => 'Remove me', 'visible' => true],
    ];
    $this->event->update(['scenes' => $scenes]);

    // Remove second overlay
    $scenes[0]['overlays'] = [
        ['type' => 'text', 'content' => 'Keep me', 'visible' => true],
    ];

    Livewire::actingAs($this->internal)
        ->test('pages::events.manage', ['liveEvent' => $this->event])
        ->call('updateScenes', $scenes);

    expect($this->event->fresh()->scenes[0]['overlays'])->toHaveCount(1)
        ->and($this->event->fresh()->scenes[0]['overlays'][0]['content'])->toBe('Keep me');
});
```

Replace `'updates overlays directly'` (line 202-213) — remove this test entirely (`updateOverlays` method is removed).

- [ ] **Step 2: Update template tests for single-scene-per-row**

Replace `'saves current config as template'` (line 221-235) with:

```php
it('saves a scene as template', function () {
    $scene = LiveEventSceneTemplate::defaultLayouts()[0];
    $scene['overlays'] = [
        ['type' => 'text', 'content' => 'My overlay', 'visible' => true],
    ];

    Livewire::actingAs($this->internal)
        ->test('pages::events.manage', ['liveEvent' => $this->event])
        ->set('templateName', 'My Solo Scene')
        ->call('saveAsTemplate', $scene)
        ->assertDispatched('template-saved');

    $template = LiveEventSceneTemplate::where('user_id', $this->internal->id)->first();
    expect($template->name)->toBe('My Solo Scene')
        ->and($template->scene_data)->toBeArray()
        ->and($template->scene_data['overlays'])->toHaveCount(1);
});
```

Replace `'loads a saved template into event'` (line 245-259) with:

```php
it('loads a saved template into event scenes', function () {
    $scene = LiveEventSceneTemplate::defaultLayouts()[1]; // Interview
    $scene['overlays'] = [
        ['type' => 'text', 'content' => 'Interview Title', 'visible' => true],
    ];

    $template = LiveEventSceneTemplate::create([
        'user_id' => $this->internal->id,
        'name' => 'My Interview',
        'scene_data' => $scene,
    ]);

    Livewire::actingAs($this->internal)
        ->test('pages::events.manage', ['liveEvent' => $this->event])
        ->call('loadTemplate', $template->id)
        ->assertDispatched('template-loaded');

    $eventScenes = $this->event->fresh()->scenes;
    // Template scene is appended to event scenes
    $lastScene = end($eventScenes);
    expect($lastScene['name'])->toBe('Interview')
        ->and($lastScene['overlays'])->toHaveCount(1)
        ->and($lastScene['overlays'][0]['content'])->toBe('Interview Title');
});
```

- [ ] **Step 3: Run the tests to see current state**

```bash
php artisan test --compact --filter=ManageEventProduction
```

Expected: Some tests fail due to removed methods — that's expected. We'll fix the methods in Task 4.

- [ ] **Step 4: Commit test changes**

```bash
git add tests/Feature/Livewire/Events/ManageEventProductionTest.php
git commit -m "test(studio): update overlay and template tests for scene-embedded data model"
```

---

### Task 4: Livewire Method Cleanup

**Files:**
- Modify: `resources/views/pages/events/⚡manage.blade.php` (PHP section, lines 1-745)

- [ ] **Step 1: Remove `updateOverlays()` method**

Delete lines 469-478 (the `updateOverlays` method):

```php
    #[Renderless]
    public function updateOverlays(array $overlays): void
    {
        foreach ($overlays as $overlay) {
            if (! isset($overlay['type'])) {
                return;
            }
        }

        $this->event->update(['overlays' => $overlays]);
    }
```

- [ ] **Step 2: Remove `addTextOverlay()` method**

Delete lines 555-578 (the `addTextOverlay` method) and its `$overlayText` property declaration (find `public string $overlayText = '';` near the top of the PHP section and remove it).

- [ ] **Step 3: Remove `removeOverlay()` method (pre-live version)**

Delete lines 581-591 (the `removeOverlay` method that uses `$this->event->overlays`):

```php
    #[Renderless]
    public function removeOverlay(int $index): void
    {
        $overlays = $this->event->overlays ?? [];
        unset($overlays[$index]);
        $this->event->update(['overlays' => array_values($overlays)]);
        $this->event->refresh();
    }
```

- [ ] **Step 4: Update `saveAsTemplate()` to save one scene**

Replace the existing `saveAsTemplate` method with:

```php
    #[Renderless]
    public function saveAsTemplate(array $sceneData = []): void
    {
        $this->validate([
            'templateName' => 'required|string|max:255',
        ]);

        if (empty($sceneData) || ! isset($sceneData['name'], $sceneData['slots'])) {
            // Fall back to the first scene from the event
            $sceneData = $this->event->getScenesOrDefaults()[0] ?? [];
            if (empty($sceneData)) {
                return;
            }
        }

        $template = LiveEventSceneTemplate::create([
            'user_id' => auth()->id(),
            'name' => $this->templateName,
            'base_layout' => $sceneData['base_layout'] ?? null,
            'scene_data' => $sceneData,
        ]);

        $this->reset('templateName');
        unset($this->sceneTemplates);
        $this->dispatch('template-saved', id: $template->id, name: $template->name);
    }
```

- [ ] **Step 5: Update `loadTemplate()` to append scene to event**

Replace the existing `loadTemplate` method with:

```php
    public function loadTemplate(int $templateId): void
    {
        $template = LiveEventSceneTemplate::where('user_id', auth()->id())->findOrFail($templateId);

        $sceneData = $template->scene_data ?? [];
        if (empty($sceneData) || ! isset($sceneData['name'], $sceneData['slots'])) {
            return;
        }

        // Ensure overlays array exists
        if (! isset($sceneData['overlays'])) {
            $sceneData['overlays'] = [];
        }

        // Append the template scene to event's scenes array
        $scenes = $this->event->scenes ?? $this->event->getScenesOrDefaults();
        $scenes[] = $sceneData;

        $this->event->update([
            'scenes' => $scenes,
        ]);

        $this->event->refresh();

        $this->dispatch('template-loaded', scenes: $this->event->getScenesOrDefaults());
    }
```

- [ ] **Step 6: Update `sceneTemplates` computed property**

The computed at line ~451 should still work since we just renamed columns. Verify it returns the correct data:

```php
    #[Computed]
    public function sceneTemplates(): \Illuminate\Database\Eloquent\Collection
    {
        return LiveEventSceneTemplate::where('user_id', auth()->id())
            ->orderBy('name')
            ->get();
    }
```

This should work as-is since we're just reading from the same table with different column names.

- [ ] **Step 7: Run tests**

```bash
php artisan test --compact --filter=ManageEventProduction
```

Expected: Overlay and template tests from Task 3 should now pass. Some other tests may still fail (pre-existing failures).

- [ ] **Step 8: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 9: Commit**

```bash
git add "resources/views/pages/events/⚡manage.blade.php"
git commit -m "refactor(studio): remove dual overlay storage, simplify template save/load"
```

---

### Task 5: Alpine State Refactor — Single Source of Truth + Auto-Save

**Files:**
- Modify: `resources/views/pages/events/⚡manage.blade.php` (Alpine section, lines ~2021-3100)

This is the largest task. The Alpine `livekitProducer` component gets refactored to use `this.scenes[idx].overlays` instead of `this.overlayConfigs`, and every mutation calls `_autoSave()`.

- [ ] **Step 1: Remove `overlayConfigs` from Alpine data block**

In the `return { ... }` block (~line 2057), remove:
```javascript
overlayConfigs: [],
```

It will be replaced by always referencing `this.scenes[this.previewSceneIndex].overlays`.

Add a getter helper to the Alpine data:
```javascript
get currentOverlays() {
    return this.scenes[this.previewSceneIndex]?.overlays || [];
},
```

- [ ] **Step 2: Add `_autoSave()` method**

Add this method to the Alpine component (near the bottom of the methods section):

```javascript
_autoSave() {
    clearTimeout(this._saveTimer);
    this._saveTimer = setTimeout(() => {
        this.$wire.updateScenes(this.scenes);
    }, 500);
},
```

- [ ] **Step 3: Replace `_syncOverlays()` with `_autoSave()`**

Delete the entire `_syncOverlays()` method (~lines 2863-2885). Then update every call site:

In `saveOverlay()` (~line 2798): replace `this._syncOverlays()` with:
```javascript
// Update compositors
const scene = this.scenes[this.previewSceneIndex];
if (previewCompositor) previewCompositor.setOverlays([...(scene?.overlays || [])]);
if (programCompositor && this.previewSceneIndex === this.liveSceneIndex) {
    programCompositor.setOverlays([...(scene?.overlays || [])]);
}
this._autoSave();
```

In `removeOverlay()` (Alpine version, ~line 2829-2834): update to:
```javascript
removeOverlay(index) {
    const scene = this.scenes[this.previewSceneIndex];
    if (!scene) return;
    scene.overlays.splice(index, 1);
    if (previewCompositor) previewCompositor.setOverlays([...scene.overlays]);
    if (programCompositor && this.previewSceneIndex === this.liveSceneIndex) {
        programCompositor.setOverlays([...scene.overlays]);
    }
    if (overlayRenderer) overlayRenderer.removeOverlay(index);
    this._autoSave();
},
```

In `toggleOverlayVisibility()` (~line 2836-2851): update to:
```javascript
toggleOverlayVisibility(index) {
    const scene = this.scenes[this.previewSceneIndex];
    if (!scene?.overlays?.[index]) return;
    scene.overlays[index].visible = !scene.overlays[index].visible;
    if (previewCompositor) previewCompositor.setOverlays([...scene.overlays]);
    if (programCompositor && this.previewSceneIndex === this.liveSceneIndex) {
        programCompositor.setOverlays([...scene.overlays]);
    }
    this._autoSave();
},
```

- [ ] **Step 4: Update `saveOverlay()` to write to scene.overlays**

In `saveOverlay()` (~line 2744-2798), update the part that adds/edits the overlay:

Replace:
```javascript
if (this.editOverlayIndex !== null && this.editOverlayIndex >= 0) {
    this.overlayConfigs[this.editOverlayIndex] = overlay;
} else {
    this.overlayConfigs.push(overlay);
}
this._syncOverlays();
```

With:
```javascript
const scene = this.scenes[this.previewSceneIndex];
if (!scene) return;
if (!scene.overlays) scene.overlays = [];

if (this.editOverlayIndex !== null && this.editOverlayIndex >= 0) {
    scene.overlays[this.editOverlayIndex] = overlay;
} else {
    scene.overlays.push(overlay);
}

// Update compositors
if (previewCompositor) previewCompositor.setOverlays([...scene.overlays]);
if (programCompositor && this.previewSceneIndex === this.liveSceneIndex) {
    programCompositor.setOverlays([...scene.overlays]);
}
this._autoSave();
```

- [ ] **Step 5: Update `previewScene()` for atomic switching**

Replace the existing `previewScene()` method with:

```javascript
previewScene(index) {
    this.previewSceneIndex = index;
    const scene = this.scenes[index];
    if (!scene) return;

    if (previewCompositor) {
        previewCompositor.setScene(scene);
        previewCompositor.setOverlays(scene.overlays || []);
    }

    if (canvasEditor && this.editorMode) {
        canvasEditor.setSlots(scene.slots || []);
        canvasEditor.setOverlays(scene.overlays || []);
        this.selectedItem = null;
    }
},
```

- [ ] **Step 6: Update `take()` for atomic switching**

Replace the existing `take()` method with:

```javascript
take() {
    this.liveSceneIndex = this.previewSceneIndex;
    const scene = this.scenes[this.liveSceneIndex];
    if (!scene) return;

    if (programCompositor) {
        programCompositor.setScene(scene);
        programCompositor.setOverlays(scene.overlays || []);
    }

    this.$wire.switchScene(this.liveSceneIndex);
    console.log('[Studio] TAKE:', scene.name);
},
```

- [ ] **Step 7: Update `toggleEditor()` — remove save-on-close**

Replace the else branch of `toggleEditor()` (~line 2567-2572):

```javascript
} else {
    if (canvasEditor) canvasEditor.disable();
    this.selectedItem = null;
    // No explicit save — _autoSave handles persistence
}
```

- [ ] **Step 8: Add `_autoSave()` to canvas editor callbacks**

In `toggleEditor()` where `canvasEditor.onSlotChanged` is set up (~line 2537-2543), add `_autoSave()`:

```javascript
canvasEditor.onSlotChanged(({ index, x, y, w, h }) => {
    const scene = this.scenes[this.previewSceneIndex];
    if (scene?.slots?.[index]) {
        Object.assign(scene.slots[index], { x, y, w, h });
        if (previewCompositor) previewCompositor.setScene(scene);
        this._autoSave();
    }
});
```

In `canvasEditor.onOverlayChanged` (~line 2545-2553), add `_autoSave()`:

```javascript
canvasEditor.onOverlayChanged(({ index, x, y, w, h }) => {
    const scene = this.scenes[this.previewSceneIndex];
    if (scene?.overlays?.[index]) {
        Object.assign(scene.overlays[index], { x, y, w, h });
        if (previewCompositor) previewCompositor.setOverlays(scene.overlays);
        this._autoSave();
    }
});
```

- [ ] **Step 9: Update `template-loaded` listener**

Update the `template-loaded` listener (~line 2119-2133) to match the new format (scenes only, no separate overlays):

```javascript
this.$wire.on('template-loaded', ({ scenes }) => {
    this.scenes = scenes;
    this.previewSceneIndex = Math.min(this.previewSceneIndex, scenes.length - 1);
    const scene = this.scenes[this.previewSceneIndex];
    if (previewCompositor && scene) {
        previewCompositor.setScene(scene);
        previewCompositor.setOverlays(scene.overlays || []);
    }
    if (programCompositor && this.scenes[this.liveSceneIndex]) {
        programCompositor.setScene(this.scenes[this.liveSceneIndex]);
        programCompositor.setOverlays(this.scenes[this.liveSceneIndex].overlays || []);
    }
    if (canvasEditor && this.editorMode && scene) {
        canvasEditor.setSlots(scene.slots || []);
        canvasEditor.setOverlays(scene.overlays || []);
        this.selectedItem = null;
    }
    console.log('[Studio] Template loaded:', scenes.length, 'scenes');
});
```

- [ ] **Step 10: Update `enableCaptions()` initialization**

In `enableCaptions()` (~line 2891), update the x-init section. The overlay initialization and the `_autoSave` approach are compatible. The caption overlay is now added to `this.scenes[idx].overlays` like any other overlay. No change needed to the CaptionEngine itself — it runs independently. Just ensure the caption overlay add uses `_autoSave()`:

```javascript
// When auto-starting caption engine after a caption overlay is added (in saveOverlay):
if (type === 'caption' && !captionEngine) {
    this.enableCaptions();
}
```

This already exists in `saveOverlay()` — no change needed.

- [ ] **Step 11: Update all references from `this.overlayConfigs` to `this.currentOverlays`**

Search and replace ALL remaining references to `this.overlayConfigs` in the Alpine component:

- In the overlay list display in the live UI templates, replace `overlayConfigs` with `currentOverlays`
- In `addVersion()` (~line 2648): if it sets `overlayConfigs`, update to set `scene.overlays` on the new scene instead
- In `cloneVersion()` (~line 2660): the cloned scene already contains its overlays — remove any `overlayConfigs` reference
- In `deleteVersion()` (~line 2670): same — remove `overlayConfigs` references, overlays travel with the scene
- In `changeLayout()` (~line 2688): add `this._autoSave()` call since it mutates scene data
- In `assignSlotSource()` (~line 2707): add `this._autoSave()` call since it mutates scene data
- In HTML overlay renderer setup: replace `overlayConfigs` with `this.currentOverlays`

For the `x-init` block (~line 1140-1149), update:

```javascript
x-init="$nextTick(() => {
    scenes = @js($scenes);
    liveSceneIndex = @js($activeSceneIndex);
    previewSceneIndex = @js($activeSceneIndex);
    // overlayConfigs line removed — overlays are inside scenes
    liveGuests = @js(...);
    @if ($livekitToken)
        startStudio(@js($livekitToken), @js($livekitUrl), @js($event->id));
    @endif
})"
```

Remove the `overlayConfigs = @js($overlays);` line and the `@php $overlays = $event->overlays ?? []; @endphp` line from the `@php` block above.

- [ ] **Step 12: Update `saveTemplate()` Alpine helper**

Replace the `saveTemplate()` Alpine helper (~line 2178):

```javascript
saveTemplate() {
    const scene = this.scenes[this.previewSceneIndex];
    if (scene) {
        this.$wire.saveAsTemplate(scene);
    }
},
```

- [ ] **Step 13: Commit**

```bash
git add "resources/views/pages/events/⚡manage.blade.php"
git commit -m "refactor(studio): single source of truth — auto-save, atomic scene switching, no dual overlays"
```

---

### Task 6: Pre-Live UI — Scene-Based Overlay Management

**Files:**
- Modify: `resources/views/pages/events/⚡manage.blade.php` (Blade template section, pre-live view ~lines 739-1127)

- [ ] **Step 1: Remove old overlay section from pre-live view**

Delete the old overlay panel (~lines 922-972) that used `$event->overlays`, `wire:click="removeOverlay"`, and the `addTextOverlay` form.

- [ ] **Step 2: Replace with scene-embedded overlay display**

In its place, show overlays from the first scene in the event's scenes array:

```blade
{{-- Overlay List (from active scene) --}}
<div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
    <flux:heading size="sm" class="mb-3">{{ __('Overlays') }}</flux:heading>

    @php
        $activeScene = ($event->scenes ?? [])[0] ?? null;
        $sceneOverlays = $activeScene['overlays'] ?? [];
    @endphp

    @if (! empty($sceneOverlays))
        <div class="mb-3 space-y-2">
            @foreach ($sceneOverlays as $index => $overlay)
                <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-900">
                    <span class="text-sm text-zinc-700 dark:text-zinc-300 truncate">
                        <flux:badge size="sm" color="zinc" class="mr-1">{{ ucfirst($overlay['type'] ?? 'text') }}</flux:badge>
                        {{ $overlay['content'] ?? '' }}
                    </span>
                </div>
            @endforeach
        </div>
    @else
        <flux:text class="text-sm text-zinc-400">{{ __('No overlays configured. Add overlays during the live session.') }}</flux:text>
    @endif
</div>
```

Note: Full overlay editing (add/remove/edit) happens during the live session in the Alpine-powered studio. The pre-live view is read-only for overlays — it shows what's been saved.

- [ ] **Step 3: Update template panel to show single-scene templates**

The template list in the pre-live view (~lines 880-918) should show individual scene names. Since `sceneTemplates` now returns single-scene rows, each row shows one scene name:

```blade
@if ($this->sceneTemplates->isNotEmpty())
    <div class="space-y-2">
        @foreach ($this->sceneTemplates as $template)
            <div class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-1.5 dark:bg-zinc-900">
                <span class="text-sm text-zinc-700 dark:text-zinc-300 truncate">{{ $template->name }}</span>
                <div class="flex items-center gap-1 shrink-0">
                    <flux:button size="xs" variant="ghost" icon="arrow-down-tray"
                        wire:click="loadTemplate({{ $template->id }})">
                        {{ __('Load') }}
                    </flux:button>
                    <flux:button size="xs" variant="ghost" icon="trash"
                        wire:click="deleteTemplate({{ $template->id }})"
                        wire:confirm="{{ __('Delete this template?') }}" />
                </div>
            </div>
        @endforeach
    </div>
@endif
```

This should already work since the template query is unchanged — just column names changed.

- [ ] **Step 4: Commit**

```bash
git add "resources/views/pages/events/⚡manage.blade.php"
git commit -m "refactor(studio): pre-live UI uses scene-embedded overlays"
```

---

### Task 7: FrameCompositor — Atomic Scene Switching

**Files:**
- Modify: `resources/js/studio/FrameCompositor.js` (lines 57-65)

- [ ] **Step 1: Update `setScene()` to always set overlays**

Replace `setScene()` at line 57-65:

```javascript
setScene(sceneConfig) {
    this._scene = sceneConfig;
    this.setOverlays(sceneConfig.overlays || []);
}
```

This ensures that when a scene is set (via preview switch or TAKE), overlays are ALWAYS updated atomically. No more conditional `if (sceneConfig.overlays)` that could leave stale overlays.

- [ ] **Step 2: Build JS**

```bash
npm run build
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/studio/FrameCompositor.js
git commit -m "refactor(studio): FrameCompositor.setScene() always sets overlays atomically"
```

---

### Task 8: Final Verification

- [ ] **Step 1: Run all ManageEvent tests**

```bash
php artisan test --compact --filter=ManageEvent
```

Expected: All new/updated tests pass. Pre-existing failures (scene-switched, guest bring-on, guest take-off, live state display) may still fail — these are unrelated to this refactor.

- [ ] **Step 2: Run Pint on all modified files**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 3: Build frontend**

```bash
npm run build
```

- [ ] **Step 4: Final commit if any formatting changes**

```bash
git add -A && git commit -m "style: apply Pint formatting"
```

---

## Summary of What's Removed

| Removed | Reason |
|---------|--------|
| `live_events.overlays` column | Overlays live inside `scenes[].overlays` |
| `LiveEvent` overlays cast/fillable | Column no longer exists |
| `updateOverlays()` Livewire method | One write path: `updateScenes()` |
| `addTextOverlay()` Livewire method | Overlays managed in Alpine, auto-saved |
| `removeOverlay()` Livewire method (pre-live) | Same |
| `$overlayText` Livewire property | Form removed |
| `this.overlayConfigs` Alpine variable | Use `this.scenes[idx].overlays` |
| `_syncOverlays()` Alpine method | Replaced by `_autoSave()` |
| `toggleEditor()` save-on-close | Auto-save on every drag |
| `LiveEventSceneTemplate.overlays` column | Merged into `scene_data` |
| `LiveEventSceneTemplate.scenes` column | Renamed to `scene_data` (single scene) |
