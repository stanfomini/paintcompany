# Live Studio Scene System Simplification

## Problem

The studio's scene/template/overlay system has state scattered across 4 places (Alpine vars, Livewire props, two DB columns, compositor internals) that constantly desync. Overlays are stored in two places (global `event.overlays` AND per-scene `scene.overlays`) with no clear precedence. Scene switching drops overlays, TAKE doesn't sync them, the editor only persists on close, and captions break when anything changes. The system was iteratively built and is now too fragmented to work reliably.

## Solution: Single Source of Truth with 3 Clean Layers

### Scene Data Model

A scene is the **single atomic unit** of everything visible on screen:

```json
{
  "name": "My Solo Scene",
  "base_layout": "solo",
  "slots": [
    { "source": "host", "x": 0, "y": 0, "w": 1, "h": 1, "label": "A", "borderRadius": 0, "cropZoom": 1, "cropX": 0, "cropY": 0 }
  ],
  "overlays": [
    { "type": "text", "content": "Welcome!", "position": "bottom-center", "x": 0.1, "y": 0.85, "w": 0.8, "h": 0.1, "visible": true, "style": "lower-third", "fontSize": 28, "fontWeight": "700", "color": "#ffffff", "bg": "#000000cc" },
    { "type": "caption", "style": "white-on-black", "x": 0.1, "y": 0.88, "w": 0.8, "h": 0.12, "visible": true, "showSpeakerName": true, "fontSize": 24, "maxLines": 2 },
    { "type": "image", "src": "/storage/logo.png", "x": 0.02, "y": 0.02, "w": 0.15, "h": 0.1, "visible": true, "opacity": 1 }
  ]
}
```

**Key rule:** No overlay data lives outside the scene object. Captions are an overlay of `type: "caption"`.

### Three Layers

#### Layer 1: Base Layouts (System Presets)

Immutable slot geometry templates. No overlays, no captions — just video feed positions.

| Layout | Description |
|--------|-------------|
| Solo | 1 full-screen slot |
| Interview | 2 side-by-side 50/50 |
| Guest Spotlight | 1 full-screen (guest) |
| Screen Share | 1 full-screen (screen) |
| Picture-in-Picture | 1 full + 1 corner |

Source: `LiveEventSceneTemplate::defaultLayouts()` (renamed from `defaultScenes()`).

Users can also create **Custom layouts** with arbitrary slot counts/positions in the pre-live setup.

#### Layer 2: Saved Scenes (User Library)

Personal library of reusable scenes — a base layout + customized overlays/captions/positions.

**Table: `live_event_scene_templates`** (restructured):

| Column | Type | Description |
|--------|------|-------------|
| id | bigint | Primary key |
| user_id | foreignId | Owner |
| name | string(255) | Display name (e.g., "My Solo with Logo") |
| base_layout | string, nullable | Which preset it started from |
| scene_data | json | Complete scene object (slots + overlays) |
| timestamps | | |

One row = one scene. Not an array of scenes per row.

**Usage:** In pre-live setup or live modal, user picks from dropdown → scene_data is cloned into event.

#### Layer 3: Event Scenes (Working Copies)

Per-event working copies stored in `live_events.scenes` (JSON array).

- When user loads a saved scene from library, it's **cloned** into this array
- Edits here do NOT affect the library copy
- Multiple scenes can be loaded (e.g., Solo + Interview) for switching during live
- All changes auto-save immediately

**`live_events.overlays` column is dropped.** Overlays only exist inside each scene.

### State Flow

```
Alpine this.scenes (single source of truth during live)
    │
    ├── previewCompositor renders scenes[previewSceneIndex]
    ├── programCompositor renders scenes[liveSceneIndex]
    │
    │   ANY mutation → _autoSave() (debounced 500ms)
    │
    └── $wire.updateScenes(this.scenes) → DB
```

**One Alpine array. One Livewire method. One DB column. One source of truth.**

### Auto-Save

Every mutation to `this.scenes` calls `_autoSave()`:

```javascript
_autoSave() {
    clearTimeout(this._saveTimer);
    this._saveTimer = setTimeout(() => {
        this.$wire.updateScenes(this.scenes);
    }, 500);
}
```

Triggered by: slot drag/resize, overlay add/edit/remove/move/toggle, caption toggle, scene rename, layout change, source assignment — everything.

**What this replaces:**
- `_syncOverlays()` (dual write to scene + global) → gone
- `updateOverlays()` Livewire method → gone
- `addTextOverlay()` / `removeOverlay()` Livewire methods (pre-live) → gone
- `toggleEditor()` save-on-close pattern → gone (editor changes auto-save on each drag)
- Separate `this.overlayConfigs` Alpine variable → gone (use `this.scenes[idx].overlays`)

### Scene Switching (Atomic)

**Preview switch:**
```javascript
previewScene(index) {
    this.previewSceneIndex = index;
    const scene = this.scenes[index];
    previewCompositor.setScene(scene);
    previewCompositor.setOverlays(scene.overlays || []);
    if (canvasEditor && this.editorMode) {
        canvasEditor.setSlots(scene.slots || []);
        canvasEditor.setOverlays(scene.overlays || []);
        this.selectedItem = null;
    }
}
```

**TAKE (preview → program):**
```javascript
take() {
    this.liveSceneIndex = this.previewSceneIndex;
    const scene = this.scenes[this.liveSceneIndex];
    programCompositor.setScene(scene);
    programCompositor.setOverlays(scene.overlays || []);
    this.$wire.switchScene(this.liveSceneIndex);
}
```

No sync logic needed — the scene IS the complete state.

### Compositor Changes

**`FrameCompositor.setScene(scene)`** becomes the primary method:
- Sets slots from `scene.slots`
- Sets overlays from `scene.overlays` (filtered by `visible !== false`)
- Pre-loads any images
- Caption overlay renders via the standard overlay pipeline (type: 'caption')

**`setOverlays(overlays)`** still exists for live overlay edits without full scene swap.

**`setCaptionText(speaker, text)`** unchanged — sets transcription text rendered by caption-type overlays.

### Caption Integration

Captions are a standard overlay with `type: "caption"`:

- **Enable captions** = add a caption overlay to the current scene
- **Disable captions** = set `visible: false` on the caption overlay (or remove it)
- **Scene switch** carries caption config atomically — if Scene A has captions and Scene B doesn't, switching to B hides captions automatically
- CaptionEngine runs independently (audio transcription) — its text output renders through whatever caption overlay exists in the active scene

### Canvas Editor Changes

- `onSlotChanged` callback: updates `scene.slots[idx]` + calls `_autoSave()`
- `onOverlayChanged` callback: updates `scene.overlays[idx]` + calls `_autoSave()`
- No more "save on editor close" — every drag auto-saves
- `toggleEditor()` just enables/disables handles, no persistence logic

### Database Migration

**Migration 1: Restructure `live_event_scene_templates`**
- Rename `scenes` → `scene_data`
- Add `base_layout` string column
- Drop `overlays` column
- Migrate existing data: each scene in old `scenes` array → own row, overlays merged in

**Migration 2: Drop `live_events.overlays`**
- Before dropping: merge any existing `overlays` data into each scene's `overlays` array in `scenes` column
- Drop the `overlays` column
- Keep `scenes` column unchanged

### Livewire Method Cleanup

**Keep (simplified):**
- `updateScenes(array $scenes)` — the ONE persistence method
- `switchScene(int $index)` — updates `active_scene_index`, broadcasts to viewers
- `saveAsTemplate(array $sceneData, string $name)` — saves ONE scene to library
- `loadTemplate(int $templateId)` — clones scene into event, dispatches to Alpine

**Remove:**
- `updateOverlays()` — merged into `updateScenes()`
- `addTextOverlay()` — handled in Alpine, auto-saved via `updateScenes()`
- `removeOverlay()` (pre-live version) — same
- `_syncOverlays()` (Alpine) — replaced by `_autoSave()`

### Pre-Live Setup UI

The pre-live setup gets a cleaner scene management panel:

1. **Layout picker** — grid of base layout cards (Solo, Interview, PiP, etc.) + "Custom" button to create new layouts with custom slot counts/positions
2. **Scene list** — shows scenes loaded into this event, each with name and base layout badge
3. **Scene editor** — click a scene to customize its overlays (text, images, captions) via forms. Pre-live overlays are managed through Livewire forms that update `event.scenes[idx].overlays` and call `updateScenes()` — same data path as live, no separate methods
4. **Save to library** — save one scene to user's template library (name + scene_data)
5. **Load from library** — dropdown of user's saved scenes → clones into event's scene array

### Files Modified

| File | Change |
|------|--------|
| `resources/views/pages/events/⚡manage.blade.php` | Refactor Alpine state, remove dual overlay storage, add auto-save, simplify scene switching, clean up Livewire methods |
| `app/Models/LiveEventSceneTemplate.php` | Restructure for single-scene rows, rename `defaultScenes()` → `defaultLayouts()`, update casts |
| `app/Models/LiveEvent.php` | Remove `overlays` from casts, update `getScenesOrDefaults()` |
| `resources/js/studio/FrameCompositor.js` | `setScene()` handles overlays atomically |
| `resources/js/studio/CanvasEditor.js` | No structural changes, but callbacks now trigger auto-save |
| `database/migrations/` | Two new migrations (restructure templates, drop overlays column) |
| `tests/Feature/Livewire/Events/ManageEventProductionTest.php` | Update for new data model |
| `tests/Feature/Livewire/Events/ManageEventTest.php` | Update for removed methods |

### Verification

1. Run full ManageEvent test suite after changes
2. Create event → add scenes from layouts → customize overlays → go live → switch scenes → verify no stale state
3. Save scene to library → start new event → load from library → verify all data preserved
4. Enable captions → switch scenes → verify captions follow scene config
5. Edit scene in canvas editor → verify auto-save (close browser, reopen → changes persisted)
6. TAKE from preview → verify program output matches preview exactly
