# Interactive Canvas Editor with Template Versions

## Problem

The current scene system uses fixed preset layouts (Solo, Interview, PiP, etc.) with dropdown source assignment. The host can't visually position video slots or overlays by dragging them on the canvas. There's no way to save multiple versions of a template for different guest combinations. Overlays can't be repositioned once placed.

## Architecture

The Preview monitor gets an **editor layer** — a transparent HTML div positioned over the canvas with draggable handles for each slot and overlay. Uses `interact.js` (already in the project) for drag and resize. A **CanvasEditor** module manages the handles.

```
Preview Canvas (renders video + overlays)
    ↑ reads positions from
Scene Data (slots + overlays with x/y/w/h)
    ↑ updated by
CanvasEditor (HTML drag handles over canvas)
    ↑ user drags handles
    ↓ fires onSlotMoved / onOverlayMoved callbacks
Alpine State → previewCompositor.setScene()
```

Template **versions** are stored in the `live_events.scenes` JSON column. Each version is a complete scene config (slots + overlays + name).

---

## 1. CanvasEditor Module

**File:** `resources/js/studio/CanvasEditor.js`

Manages the HTML handle layer over the preview canvas.

### API

```javascript
class CanvasEditor {
    constructor(canvasEl, handleContainerEl)

    enable()                      // Show handles, enable interaction
    disable()                     // Hide handles, disable interaction
    isEnabled()                   // Returns boolean

    setSlots(slots)               // Create/update slot handles from scene data
    setOverlays(overlays)         // Create/update overlay handles
    selectItem(type, index)       // Programmatically select a slot or overlay
    clearSelection()

    onSlotChanged(callback)       // ({index, x, y, w, h}) — after drag/resize
    onOverlayChanged(callback)    // ({index, x, y, w, h}) — after drag/resize
    onSelect(callback)            // ({type: 'slot'|'overlay', index}) — on click

    destroy()
}
```

### Handle Elements

Each slot and overlay gets a handle div:

```html
<div class="canvas-editor-handle"
     data-type="slot" data-index="0"
     style="position:absolute; left:0%; top:0%; width:50%; height:100%;
            border: 2px dashed rgba(34,197,94,0.6);
            cursor: move; pointer-events: auto;">
    <!-- Resize corners -->
    <div class="handle-corner handle-corner-se" style="position:absolute; right:-4px; bottom:-4px;
         width:8px; height:8px; background:white; border:1px solid #333; cursor:se-resize;"></div>
    <div class="handle-corner handle-corner-ne" style="position:absolute; right:-4px; top:-4px; ..."></div>
    <!-- Label -->
    <span class="handle-label" style="position:absolute; top:4px; left:4px;
          font-size:10px; color:white; background:rgba(0,0,0,0.6); padding:1px 4px; border-radius:2px;">
        Slot A: Host
    </span>
</div>
```

- **Slot handles:** dashed green border, draggable + resizable, labeled with slot name + source
- **Overlay handles:** dashed blue border for text, dashed purple for images/HTML, labeled with type + content preview
- **Selected handle:** solid bright border, shows resize corners
- **Hover:** border brightens

### Coordinate Mapping

Handles use percentage positioning (CSS `left`, `top`, `width`, `height` as percentages) which maps directly to the compositor's normalized 0-1 coordinates:

```javascript
// Handle position → compositor coordinates
const x = parseFloat(handle.style.left) / 100;  // 0-1
const y = parseFloat(handle.style.top) / 100;   // 0-1
const w = parseFloat(handle.style.width) / 100;  // 0-1
const h = parseFloat(handle.style.height) / 100; // 0-1
```

### interact.js Integration

```javascript
import interact from 'interactjs';

// Make handle draggable
interact(handle).draggable({
    modifiers: [
        interact.modifiers.restrictRect({ restriction: 'parent' }),
    ],
    listeners: {
        move(event) {
            const target = event.target;
            const dx = (event.dx / containerWidth) * 100;
            const dy = (event.dy / containerHeight) * 100;
            const left = parseFloat(target.style.left) + dx;
            const top = parseFloat(target.style.top) + dy;
            target.style.left = `${left}%`;
            target.style.top = `${top}%`;
        },
        end(event) {
            // Fire callback with new normalized position
        }
    }
}).resizable({
    edges: { right: '.handle-corner-se', bottom: '.handle-corner-se',
             top: '.handle-corner-ne', left: false },
    modifiers: [
        interact.modifiers.restrictSize({ min: { width: 50, height: 50 } }),
    ],
    listeners: {
        move(event) { /* update width/height */ },
        end(event) { /* fire callback */ }
    }
});
```

---

## 2. Template Versions

### Data Structure

The `live_events.scenes` JSON column changes from an array of preset scenes to an array of **versions**:

```javascript
// Before (preset scenes):
[
    { name: 'Solo', slots: [{source:'host', x:0, y:0, w:1, h:1}] },
    { name: 'Interview', slots: [...] },
]

// After (template versions):
[
    {
        name: 'Host + Mike',
        layout: 'interview',          // Base layout (for reference)
        slots: [
            { label: 'A', source: 'host', x: 0, y: 0, w: 0.48, h: 1 },
            { label: 'B', source: 'guest-1', x: 0.52, y: 0, w: 0.48, h: 1 },
        ],
        overlays: [
            { type: 'text', content: 'Mike Johnson', style: 'lower-third',
              x: 0.02, y: 0.85, fontSize: 24, color: '#fff', bg: '#000000cc', visible: true },
        ],
    },
    {
        name: 'Host + Sarah',
        layout: 'interview',
        slots: [
            { label: 'A', source: 'host', x: 0, y: 0, w: 0.48, h: 1 },
            { label: 'B', source: 'guest-2', x: 0.52, y: 0, w: 0.48, h: 1 },
        ],
        overlays: [
            { type: 'text', content: 'Sarah Lee', style: 'lower-third',
              x: 0.02, y: 0.85, fontSize: 24, color: '#fff', bg: '#000000cc', visible: true },
        ],
    },
    {
        name: 'Panel Discussion',
        layout: 'panel-3up',
        slots: [
            { label: 'A', source: 'host', x: 0, y: 0, w: 0.33, h: 1 },
            { label: 'B', source: 'guest-1', x: 0.33, y: 0, w: 0.34, h: 1 },
            { label: 'C', source: 'guest-2', x: 0.67, y: 0, w: 0.33, h: 1 },
        ],
        overlays: [],
    },
]
```

Each version is self-contained — it has its own slots, sources, and overlays. Switching versions in Preview loads everything at once.

### Backward Compatibility

Existing scenes (from before this change) don't have per-version overlays. The FrameCompositor and Alpine code will check: if the scene has `overlays`, use those. Otherwise, fall back to `this.overlayConfigs` (the global event overlays). This preserves existing behavior.

### Version Management UI

Below the Preview/Program monitors:

```
Versions: [● Host+Mike] [Host+Sarah] [Panel] [+New] [Clone] [Delete]
```

- Click a version tab → loads into Preview (editor)
- Red dot = currently live in Program
- Green dot = currently in Preview
- [+New] creates a new version from a layout preset
- [Clone] duplicates the current preview version
- [Delete] removes a version (can't delete the live one)

---

## 3. Properties Panel

When a slot or overlay is selected (clicked) in the editor, a **properties panel** appears below the version tabs.

### Slot Properties

```
Selected: Slot A — "Host"
┌────────────────────────────────────────────────┐
│ Source: [Host ▼]                               │
│ Position: X [0%] Y [0%]  Size: W [48%] H [100%]│
│ Border Radius: [0 ▼] px                        │
│ Z-Order: [1 ▼]                                 │
└────────────────────────────────────────────────┘
```

### Overlay Properties (varies by type)

**Text overlay:**
```
Selected: Text Overlay — "Mike Johnson"
┌────────────────────────────────────────────────┐
│ Content: [Mike Johnson          ]              │
│ Style: [Lower Third ▼]                        │
│ Font Size: [24 ▼]px  Weight: [Bold ▼]         │
│ Color: [#ffffff]  Background: [#000000cc]      │
│ Position: X [2%] Y [85%]  Width: [40%]        │
│ Visible: [✓]                                   │
└────────────────────────────────────────────────┘
```

**Image overlay:**
```
Selected: Image Overlay — "logo.png"
┌────────────────────────────────────────────────┐
│ URL: [/storage/overlays/logo.png    ]          │
│ Position: X [85%] Y [2%]  Size: W [12%] H [8%]│
│ Opacity: [████████░░] 80%                      │
│ Visible: [✓]                                   │
└────────────────────────────────────────────────┘
```

Properties update in real-time as you type — compositor re-renders immediately.

---

## 4. Enhanced Overlay Styling

The current text overlay styling is basic. Add these options accessible from the properties panel:

### Text Overlay Enhancements
- **Font family:** sans-serif, serif, monospace (rendered via canvas font)
- **Text shadow:** on/off with color
- **Text alignment:** left, center, right
- **Background shape:** rectangle, rounded rectangle, pill
- **Background padding:** adjustable
- **Animation:** none, fade-in, slide-up (for lower thirds)

### Image Overlay Enhancements
- **Border radius:** 0-50% (for circular logos)
- **Border:** color + width
- **Shadow:** on/off

These are stored as additional fields in the overlay config object and drawn by the compositor.

---

## 5. FrameCompositor Changes

### Slot Drawing Enhancements

Add border radius support to `_drawSlot()`:
```javascript
if (slot.borderRadius) {
    ctx.save();
    this._roundedRect(ctx, x, y, w, h, slot.borderRadius);
    ctx.clip();
    ctx.drawImage(videoEl, sx, sy, sw, sh, x, y, w, h);
    ctx.restore();
} else {
    ctx.drawImage(videoEl, sx, sy, sw, sh, x, y, w, h);
}
```

### Text Overlay Enhancements

Update `_drawTextOverlay` and style-specific methods to support:
- Text alignment (ctx.textAlign)
- Rounded background rectangles
- Text shadow (ctx.shadowColor, ctx.shadowBlur)
- Configurable padding

### setScene() with Per-Version Overlays

```javascript
setScene(sceneConfig) {
    this._scene = sceneConfig;
    // Use per-version overlays if available, otherwise keep global overlays
    if (sceneConfig.overlays) {
        this.setOverlays(sceneConfig.overlays);
    }
}
```

---

## 6. File Changes Summary

### New Files
| File | Purpose |
|------|---------|
| `resources/js/studio/CanvasEditor.js` | Draggable HTML handle layer over preview canvas |

### Modified Files
| File | Changes |
|------|---------|
| `resources/js/studio/FrameCompositor.js` | Border radius on slots, enhanced text styling, per-version overlays |
| `resources/views/pages/events/⚡manage.blade.php` | Editor mode toggle, handle container, version tabs, properties panel, enhanced overlay modals |
| `resources/js/app.js` | Add `loadCanvasEditor` lazy loader |

### Database
No schema changes. Scenes JSON column already supports arbitrary structure.

---

## 7. Verification

1. **Editor mode:** Toggle editor → see green dashed handles over slots → drag a slot → preview re-renders in real-time
2. **Resize:** Drag corner handle on a slot → slot shrinks/grows → compositor matches
3. **Overlay drag:** Drag a lower-third text overlay higher on screen → preview shows it in new position
4. **Template versions:** Create "Host + Mike" version → position slots → save → create "Host + Sarah" version → different positions → switch between them
5. **Properties panel:** Click a slot → see source dropdown, position fields → change source → preview updates
6. **TAKE:** Preview shows different version than Program → click TAKE → Program switches to the preview version
7. **Persist:** Refresh page → versions and positions are preserved
8. **Border radius:** Set border radius on a slot → video renders with rounded corners
9. **Backward compat:** Existing events with old-format scenes still work
