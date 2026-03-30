# Interactive Canvas Editor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Preview monitor into an interactive canvas editor with drag/resize handles for video slots and overlays, template versions for different guest combinations, and a properties panel for fine-tuning.

**Architecture:** HTML drag handles (divs) positioned over the Preview canvas using interact.js. CanvasEditor module manages handle creation, drag events, and coordinate mapping. Template versions stored in the existing `live_events.scenes` JSON column. Each version is a self-contained scene config with its own slots, sources, overlays, and name.

**Tech Stack:** interact.js (drag/resize, already in project), Alpine.js (state/reactivity), FrameCompositor (canvas rendering), Livewire 4 SFC (persistence)

**Spec:** `docs/superpowers/specs/2026-03-30-canvas-editor-design.md`

**Critical: Do NOT break existing functionality.** All existing video compositing, guest connections, source management, and broadcasting must continue to work.

---

## File Structure

### New Files
| File | Responsibility |
|------|---------------|
| `resources/js/studio/CanvasEditor.js` | Manages HTML drag handles over preview canvas. Creates/updates/removes handles for slots and overlays. Fires position change callbacks via interact.js drag/resize. |

### Modified Files
| File | Changes |
|------|---------|
| `resources/js/studio/FrameCompositor.js` | Add border radius support to `_drawSlot()`, rounded rect helper |
| `resources/views/pages/events/⚡manage.blade.php` | Editor mode toggle, handle container div, version tabs, properties panel, wire CanvasEditor into Alpine |
| `resources/js/app.js` | Add `loadCanvasEditor` lazy loader |

---

### Task 1: CanvasEditor Module — Drag/Resize Handles

**Files:**
- Create: `resources/js/studio/CanvasEditor.js`
- Modify: `resources/js/app.js`

The core module. Creates draggable, resizable HTML handles positioned over the preview canvas. Uses percentage-based positioning that maps directly to the compositor's 0-1 normalized coordinates.

- [ ] **Step 1: Create CanvasEditor.js**

Create `resources/js/studio/CanvasEditor.js`:

```javascript
/**
 * CanvasEditor — Interactive drag/resize handles over the preview canvas.
 *
 * Creates transparent HTML divs positioned over the canvas for each video slot
 * and overlay. Uses interact.js for drag and resize. Fires callbacks when
 * positions change so the compositor can re-render in real-time.
 *
 * Coordinate system: all positions are percentages (0-100) which map to the
 * compositor's normalized 0-1 coordinates by dividing by 100.
 */

import interact from 'interactjs';

export default class CanvasEditor {
    /**
     * @param {HTMLCanvasElement} canvasEl — the preview canvas (for size reference)
     * @param {HTMLElement} handleContainerEl — absolutely-positioned div over the canvas
     */
    constructor(canvasEl, handleContainerEl) {
        this._canvas = canvasEl;
        this._container = handleContainerEl;
        this._handles = new Map(); // key → {element, type, index}
        this._enabled = false;
        this._selectedKey = null;

        this._onSlotChangedCallback = null;
        this._onOverlayChangedCallback = null;
        this._onSelectCallback = null;

        // Bind click handler for selection
        this._container.addEventListener('click', (e) => {
            const handle = e.target.closest('.ce-handle');
            if (handle) {
                this._select(handle.dataset.key);
            }
        });
    }

    // =========================================================================
    // Enable / Disable
    // =========================================================================

    enable() {
        this._enabled = true;
        this._container.style.pointerEvents = 'auto';
        for (const [, entry] of this._handles) {
            entry.element.style.display = '';
        }
        console.log('[CanvasEditor] Enabled');
    }

    disable() {
        this._enabled = false;
        this._container.style.pointerEvents = 'none';
        for (const [, entry] of this._handles) {
            entry.element.style.display = 'none';
        }
        this._selectedKey = null;
        console.log('[CanvasEditor] Disabled');
    }

    isEnabled() {
        return this._enabled;
    }

    // =========================================================================
    // Slots and Overlays
    // =========================================================================

    /**
     * Create/update handles for video slots.
     * @param {Array} slots — [{label, source, x, y, w, h}, ...]
     */
    setSlots(slots) {
        // Remove old slot handles
        for (const [key, entry] of this._handles) {
            if (entry.type === 'slot') {
                this._removeHandle(key);
            }
        }

        slots.forEach((slot, index) => {
            const key = `slot-${index}`;
            const label = `Slot ${slot.label || String.fromCharCode(65 + index)}`;
            const sourceName = slot.source || '(none)';

            this._createHandle(key, {
                type: 'slot',
                index,
                x: (slot.x ?? 0) * 100,
                y: (slot.y ?? 0) * 100,
                w: (slot.w ?? 0.5) * 100,
                h: (slot.h ?? 1) * 100,
                label: `${label}: ${sourceName}`,
                borderColor: 'rgba(34, 197, 94, 0.7)',  // Green for slots
                borderStyle: 'dashed',
            });
        });
    }

    /**
     * Create/update handles for overlays.
     * @param {Array} overlays — [{type, content, x, y, w, h, ...}, ...]
     */
    setOverlays(overlays) {
        // Remove old overlay handles
        for (const [key, entry] of this._handles) {
            if (entry.type === 'overlay') {
                this._removeHandle(key);
            }
        }

        overlays.forEach((overlay, index) => {
            if (!overlay.visible) return;

            const key = `overlay-${index}`;
            const label = this._overlayLabel(overlay);
            const color = overlay.type === 'text' ? 'rgba(59, 130, 246, 0.7)'    // Blue for text
                : overlay.type === 'image' ? 'rgba(168, 85, 247, 0.7)'           // Purple for image
                : overlay.type === 'html' ? 'rgba(236, 72, 153, 0.7)'            // Pink for HTML
                : 'rgba(234, 179, 8, 0.7)';                                      // Yellow for caption

            this._createHandle(key, {
                type: 'overlay',
                index,
                x: (overlay.x ?? 0) * 100,
                y: (overlay.y ?? 0) * 100,
                w: (overlay.w ?? 0.3) * 100,
                h: (overlay.h ?? 0.08) * 100,
                label,
                borderColor: color,
                borderStyle: 'dashed',
            });
        });
    }

    // =========================================================================
    // Selection
    // =========================================================================

    selectItem(type, index) {
        const key = `${type}-${index}`;
        this._select(key);
    }

    clearSelection() {
        if (this._selectedKey) {
            const entry = this._handles.get(this._selectedKey);
            if (entry) {
                entry.element.style.borderStyle = 'dashed';
                entry.element.style.borderWidth = '2px';
                entry.element.querySelector('.ce-corners')?.remove();
            }
            this._selectedKey = null;
        }
    }

    // =========================================================================
    // Callbacks
    // =========================================================================

    onSlotChanged(callback) { this._onSlotChangedCallback = callback; }
    onOverlayChanged(callback) { this._onOverlayChangedCallback = callback; }
    onSelect(callback) { this._onSelectCallback = callback; }

    // =========================================================================
    // Cleanup
    // =========================================================================

    destroy() {
        for (const [key] of this._handles) {
            this._removeHandle(key);
        }
        this._handles.clear();
    }

    // =========================================================================
    // Private — Handle creation
    // =========================================================================

    _createHandle(key, config) {
        // Remove existing handle with same key
        if (this._handles.has(key)) {
            this._removeHandle(key);
        }

        const el = document.createElement('div');
        el.className = 'ce-handle';
        el.dataset.key = key;
        el.dataset.type = config.type;
        el.dataset.index = config.index;
        el.style.cssText = `
            position: absolute;
            left: ${config.x}%;
            top: ${config.y}%;
            width: ${config.w}%;
            height: ${config.h}%;
            border: 2px ${config.borderStyle} ${config.borderColor};
            cursor: move;
            box-sizing: border-box;
            transition: border-color 0.15s;
            z-index: ${config.type === 'overlay' ? 20 : 10};
        `;

        // Label
        const label = document.createElement('span');
        label.className = 'ce-label';
        label.textContent = config.label;
        label.style.cssText = `
            position: absolute; top: 2px; left: 2px;
            font-size: 9px; color: white; background: rgba(0,0,0,0.7);
            padding: 1px 4px; border-radius: 2px; pointer-events: none;
            white-space: nowrap; max-width: 90%; overflow: hidden; text-overflow: ellipsis;
        `;
        el.appendChild(label);

        // Resize handle (bottom-right corner)
        const resizeHandle = document.createElement('div');
        resizeHandle.className = 'ce-resize';
        resizeHandle.style.cssText = `
            position: absolute; right: -4px; bottom: -4px;
            width: 10px; height: 10px; background: white; border: 1px solid #333;
            cursor: se-resize; border-radius: 2px; z-index: 5;
        `;
        el.appendChild(resizeHandle);

        if (!this._enabled) {
            el.style.display = 'none';
        }

        this._container.appendChild(el);

        // Set up interact.js
        this._setupInteract(el, key, config.type, config.index);

        this._handles.set(key, {
            element: el,
            type: config.type,
            index: config.index,
        });
    }

    _removeHandle(key) {
        const entry = this._handles.get(key);
        if (!entry) return;

        // Clean up interact.js
        interact(entry.element).unset();
        entry.element.remove();
        this._handles.delete(key);
    }

    _setupInteract(el, key, type, index) {
        const containerRect = () => this._container.getBoundingClientRect();

        interact(el).draggable({
            modifiers: [
                interact.modifiers.restrictRect({ restriction: 'parent' }),
            ],
            listeners: {
                move: (event) => {
                    const rect = containerRect();
                    const dx = (event.dx / rect.width) * 100;
                    const dy = (event.dy / rect.height) * 100;
                    const left = parseFloat(el.style.left) + dx;
                    const top = parseFloat(el.style.top) + dy;
                    el.style.left = `${Math.max(0, Math.min(100 - parseFloat(el.style.width), left))}%`;
                    el.style.top = `${Math.max(0, Math.min(100 - parseFloat(el.style.height), top))}%`;

                    this._fireChange(type, index, el);
                },
            },
        }).resizable({
            edges: { right: '.ce-resize', bottom: '.ce-resize' },
            modifiers: [
                interact.modifiers.restrictSize({
                    min: { width: 30, height: 20 },
                }),
                interact.modifiers.restrictEdges({ outer: 'parent' }),
            ],
            listeners: {
                move: (event) => {
                    const rect = containerRect();
                    const w = (event.rect.width / rect.width) * 100;
                    const h = (event.rect.height / rect.height) * 100;
                    el.style.width = `${Math.min(100, w)}%`;
                    el.style.height = `${Math.min(100, h)}%`;

                    this._fireChange(type, index, el);
                },
            },
        });
    }

    _fireChange(type, index, el) {
        const pos = {
            index,
            x: parseFloat(el.style.left) / 100,
            y: parseFloat(el.style.top) / 100,
            w: parseFloat(el.style.width) / 100,
            h: parseFloat(el.style.height) / 100,
        };

        if (type === 'slot' && this._onSlotChangedCallback) {
            this._onSlotChangedCallback(pos);
        } else if (type === 'overlay' && this._onOverlayChangedCallback) {
            this._onOverlayChangedCallback(pos);
        }
    }

    _select(key) {
        this.clearSelection();
        this._selectedKey = key;

        const entry = this._handles.get(key);
        if (!entry) return;

        entry.element.style.borderStyle = 'solid';
        entry.element.style.borderWidth = '3px';

        if (this._onSelectCallback) {
            this._onSelectCallback({ type: entry.type, index: entry.index });
        }
    }

    _overlayLabel(overlay) {
        switch (overlay.type) {
            case 'text': return `Text: ${(overlay.content || '').substring(0, 20)}`;
            case 'image': return `Image: ${(overlay.src || '').split('/').pop()}`;
            case 'html': return `HTML: ${(overlay.src || overlay.html || '').substring(0, 20)}`;
            case 'caption': return 'Captions';
            default: return 'Overlay';
        }
    }
}
```

- [ ] **Step 2: Add lazy loader to app.js**

Add to `resources/js/app.js` after the existing loaders:

```javascript
window.loadCanvasEditor = async () => {
    if (!window.StudioCanvasEditor) {
        const module = await import('./studio/CanvasEditor.js');
        window.StudioCanvasEditor = module.default;
    }
    return window.StudioCanvasEditor;
};
```

- [ ] **Step 3: Build and verify**

Run: `npm run build`
Expected: Build succeeds, CanvasEditor chunk in output.

- [ ] **Step 4: Commit**

```bash
git add resources/js/studio/CanvasEditor.js resources/js/app.js
git commit -m "feat(studio): add CanvasEditor — drag/resize handles over preview canvas"
```

---

### Task 2: FrameCompositor — Border Radius Support

**Files:**
- Modify: `resources/js/studio/FrameCompositor.js`

Add border radius support to `_drawSlot()` so video slots can have rounded corners.

- [ ] **Step 1: Add rounded rect helper and update _drawSlot**

In `resources/js/studio/FrameCompositor.js`, add a `_roundedRect` helper method and update `_drawSlot` to use it when `slot.borderRadius` is set.

Add after `_preloadImages()`:

```javascript
    /**
     * Draw a rounded rectangle path (for clipping or filling).
     */
    _roundedRect(ctx, x, y, w, h, radius) {
        const r = Math.min(radius, w / 2, h / 2);
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }
```

Update `_drawSlot` — wrap the drawImage in a clip path when borderRadius is set:

In the section where `videoEl && videoEl.readyState >= 2`, replace the drawImage block:

```javascript
        if (videoEl && videoEl.readyState >= 2) {
            // Cover-style draw: maintain aspect ratio, crop overflow
            const vidW = videoEl.videoWidth || videoEl.width || w;
            const vidH = videoEl.videoHeight || videoEl.height || h;
            const slotRatio = w / h;
            const vidRatio = vidW / vidH;

            let sx = 0, sy = 0, sw = vidW, sh = vidH;
            if (vidRatio > slotRatio) {
                sw = vidH * slotRatio;
                sx = (vidW - sw) / 2;
            } else {
                sh = vidW / slotRatio;
                sy = (vidH - sh) / 2;
            }

            if (slot.borderRadius) {
                ctx.save();
                this._roundedRect(ctx, x, y, w, h, slot.borderRadius);
                ctx.clip();
                ctx.drawImage(videoEl, sx, sy, sw, sh, x, y, w, h);
                ctx.restore();
            } else {
                ctx.drawImage(videoEl, sx, sy, sw, sh, x, y, w, h);
            }
        }
```

- [ ] **Step 2: Build and verify**

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 3: Commit**

```bash
git add resources/js/studio/FrameCompositor.js
git commit -m "feat(studio): border radius support for video slots in compositor"
```

---

### Task 3: Editor UI — Mode Toggle, Handle Container, Version Tabs

**Files:**
- Modify: `resources/views/pages/events/⚡manage.blade.php`

Add the editor mode toggle, the handle container div over the preview canvas, version tabs for template management, and wire CanvasEditor into Alpine.

- [ ] **Step 1: Add handle container to Preview monitor**

In the Preview monitor section, add a handle container div positioned over the canvas. Find the preview canvas and wrap it:

Replace the Preview Monitor div:
```blade
{{-- Preview Monitor --}}
<div class="relative flex-1 overflow-hidden rounded-lg border-2 bg-black"
     :class="editorMode ? 'border-green-400' : 'border-green-500/40'">
    <canvas x-ref="previewCanvas" class="h-full w-full object-contain" width="1280" height="720"></canvas>
    {{-- Editor handles layer — positioned over canvas --}}
    <div x-ref="editorHandles" x-show="editorMode"
         class="absolute inset-0 z-10"
         style="pointer-events: none;"></div>
    <span class="absolute top-2 left-2 rounded px-2 py-0.5 text-[10px] font-bold text-white tracking-wider z-20"
          :class="editorMode ? 'bg-yellow-500' : 'bg-green-600/90'"
          x-text="editorMode ? 'EDITOR' : 'PREVIEW'"></span>
    <span class="absolute top-2 right-2 rounded bg-black/60 px-2 py-0.5 text-[10px] text-zinc-300 z-20"
          x-text="scenes[previewSceneIndex]?.name ?? 'Solo'"></span>
    {{-- Edit mode toggle --}}
    <button type="button" @click="toggleEditor()"
            class="absolute bottom-2 right-2 rounded px-2 py-1 text-[10px] font-medium transition-colors z-20"
            :class="editorMode ? 'bg-yellow-500 text-black hover:bg-yellow-400' : 'bg-zinc-700/80 text-zinc-300 hover:bg-zinc-600'">
        <span x-text="editorMode ? 'Done Editing' : 'Edit Layout'"></span>
    </button>
</div>
```

- [ ] **Step 2: Add version tabs between scene switcher and scene config**

Add a version management strip. Insert AFTER the scene switcher and BEFORE the scene config:

```blade
{{-- ═══════════════════════════════════════════ --}}
{{-- VERSION TABS --}}
{{-- ═══════════════════════════════════════════ --}}
<div class="flex items-center gap-1.5 border-t border-zinc-700 bg-zinc-850 px-3 py-1.5" wire:ignore>
    <span class="mr-1 text-[9px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Versions') }}</span>
    <template x-for="(scene, idx) in scenes" :key="idx">
        <button type="button"
                @click="previewScene(idx)"
                class="rounded px-2.5 py-1 text-[10px] font-medium transition-colors truncate max-w-[120px]"
                :class="{
                    'ring-1 ring-red-500 bg-red-600/20 text-red-300': liveSceneIndex === idx,
                    'ring-1 ring-green-500 bg-green-600/20 text-green-300': previewSceneIndex === idx && liveSceneIndex !== idx,
                    'bg-zinc-700 text-zinc-400 hover:bg-zinc-600 hover:text-zinc-300': previewSceneIndex !== idx && liveSceneIndex !== idx,
                }"
                x-text="scene.name || ('Version ' + (idx + 1))">
        </button>
    </template>
    <button type="button" @click="addVersion()" class="rounded bg-zinc-700 px-2 py-1 text-[10px] text-zinc-400 hover:bg-zinc-600">+ New</button>
    <button type="button" @click="cloneVersion()" class="rounded bg-zinc-700 px-2 py-1 text-[10px] text-zinc-400 hover:bg-zinc-600">Clone</button>
    <button type="button" @click="deleteVersion()"
            x-show="scenes.length > 1 && previewSceneIndex !== liveSceneIndex"
            class="rounded bg-zinc-700 px-2 py-1 text-[10px] text-red-400 hover:bg-red-600/30">Delete</button>
</div>
```

- [ ] **Step 3: Add properties panel**

Add after the overlay panel (or replace the scene config row when editor mode is active):

```blade
{{-- ═══════════════════════════════════════════ --}}
{{-- PROPERTIES PANEL (visible when item selected in editor) --}}
{{-- ═══════════════════════════════════════════ --}}
<div x-show="editorMode && selectedItem" x-cloak
     class="border-t border-zinc-700 bg-zinc-800 px-3 py-2" wire:ignore>
    <div class="flex items-center gap-2 mb-2">
        <span class="text-[9px] font-bold uppercase tracking-widest text-zinc-500">Properties</span>
        <span class="text-[10px] text-zinc-400" x-text="selectedItemLabel"></span>
    </div>
    <div class="flex flex-wrap items-center gap-3">
        {{-- Source dropdown (slots only) --}}
        <template x-if="selectedItem?.type === 'slot'">
            <div class="flex items-center gap-1">
                <span class="text-[10px] text-zinc-500">Source:</span>
                <select @change="updateSelectedProperty('source', $event.target.value)"
                        class="rounded bg-zinc-700 px-1.5 py-0.5 text-[10px] text-zinc-200 border-zinc-600">
                    <option value="">(none)</option>
                    <template x-for="source in sources" :key="source.key">
                        <option :value="source.key" :selected="getSelectedProperty('source') === source.key" x-text="source.name"></option>
                    </template>
                </select>
            </div>
        </template>
        {{-- Position/Size --}}
        <div class="flex items-center gap-1">
            <span class="text-[10px] text-zinc-500">X:</span>
            <input type="number" min="0" max="100" step="1"
                   :value="Math.round((getSelectedProperty('x') ?? 0) * 100)"
                   @change="updateSelectedProperty('x', $event.target.value / 100)"
                   class="w-12 rounded bg-zinc-700 px-1.5 py-0.5 text-[10px] text-zinc-200 border-zinc-600">
        </div>
        <div class="flex items-center gap-1">
            <span class="text-[10px] text-zinc-500">Y:</span>
            <input type="number" min="0" max="100" step="1"
                   :value="Math.round((getSelectedProperty('y') ?? 0) * 100)"
                   @change="updateSelectedProperty('y', $event.target.value / 100)"
                   class="w-12 rounded bg-zinc-700 px-1.5 py-0.5 text-[10px] text-zinc-200 border-zinc-600">
        </div>
        <div class="flex items-center gap-1">
            <span class="text-[10px] text-zinc-500">W:</span>
            <input type="number" min="1" max="100" step="1"
                   :value="Math.round((getSelectedProperty('w') ?? 50) * 100)"
                   @change="updateSelectedProperty('w', $event.target.value / 100)"
                   class="w-12 rounded bg-zinc-700 px-1.5 py-0.5 text-[10px] text-zinc-200 border-zinc-600">
        </div>
        <div class="flex items-center gap-1">
            <span class="text-[10px] text-zinc-500">H:</span>
            <input type="number" min="1" max="100" step="1"
                   :value="Math.round((getSelectedProperty('h') ?? 50) * 100)"
                   @change="updateSelectedProperty('h', $event.target.value / 100)"
                   class="w-12 rounded bg-zinc-700 px-1.5 py-0.5 text-[10px] text-zinc-200 border-zinc-600">
        </div>
        {{-- Border Radius (slots) --}}
        <template x-if="selectedItem?.type === 'slot'">
            <div class="flex items-center gap-1">
                <span class="text-[10px] text-zinc-500">Radius:</span>
                <input type="number" min="0" max="50" step="1"
                       :value="getSelectedProperty('borderRadius') ?? 0"
                       @change="updateSelectedProperty('borderRadius', parseInt($event.target.value))"
                       class="w-12 rounded bg-zinc-700 px-1.5 py-0.5 text-[10px] text-zinc-200 border-zinc-600">
                <span class="text-[10px] text-zinc-600">px</span>
            </div>
        </template>
        {{-- Content (text overlays) --}}
        <template x-if="selectedItem?.type === 'overlay' && getSelectedOverlay()?.type === 'text'">
            <div class="flex items-center gap-1">
                <span class="text-[10px] text-zinc-500">Text:</span>
                <input type="text"
                       :value="getSelectedProperty('content') ?? ''"
                       @input="updateSelectedProperty('content', $event.target.value)"
                       class="w-40 rounded bg-zinc-700 px-1.5 py-0.5 text-[10px] text-zinc-200 border-zinc-600">
            </div>
        </template>
    </div>
</div>
```

- [ ] **Step 4: Add Alpine state and methods for editor**

Add these to the Alpine `livekitProducer` return object:

State:
```javascript
editorMode: false,
selectedItem: null, // {type: 'slot'|'overlay', index: number}
```

Closure variable:
```javascript
let canvasEditor = null;
```

Methods:
```javascript
async toggleEditor() {
    this.editorMode = !this.editorMode;

    if (this.editorMode) {
        if (!canvasEditor) {
            const CanvasEditor = await window.loadCanvasEditor();
            canvasEditor = new CanvasEditor(this.$refs.previewCanvas, this.$refs.editorHandles);

            canvasEditor.onSlotChanged(({ index, x, y, w, h }) => {
                const scene = this.scenes[this.previewSceneIndex];
                if (scene?.slots?.[index]) {
                    Object.assign(scene.slots[index], { x, y, w, h });
                    if (previewCompositor) previewCompositor.setScene(scene);
                }
            });

            canvasEditor.onOverlayChanged(({ index, x, y, w, h }) => {
                const scene = this.scenes[this.previewSceneIndex];
                if (scene?.overlays?.[index]) {
                    Object.assign(scene.overlays[index], { x, y, w, h });
                    if (previewCompositor) {
                        previewCompositor.setOverlays(scene.overlays);
                    }
                }
            });

            canvasEditor.onSelect(({ type, index }) => {
                this.selectedItem = { type, index };
            });
        }

        // Sync handles with current preview scene
        const scene = this.scenes[this.previewSceneIndex];
        if (scene) {
            canvasEditor.setSlots(scene.slots || []);
            canvasEditor.setOverlays(scene.overlays || this.overlayConfigs || []);
        }
        canvasEditor.enable();
    } else {
        if (canvasEditor) canvasEditor.disable();
        this.selectedItem = null;
        // Persist the modified scene
        this.$wire.updateScenes(this.scenes);
    }
},

get selectedItemLabel() {
    if (!this.selectedItem) return '';
    const { type, index } = this.selectedItem;
    const scene = this.scenes[this.previewSceneIndex];
    if (type === 'slot') {
        const slot = scene?.slots?.[index];
        return `Slot ${slot?.label || String.fromCharCode(65 + index)}: ${slot?.source || '(none)'}`;
    } else {
        const overlay = scene?.overlays?.[index] ?? this.overlayConfigs?.[index];
        return overlay ? `${overlay.type}: ${overlay.content || overlay.src || ''}`.substring(0, 40) : 'Overlay';
    }
},

getSelectedProperty(prop) {
    if (!this.selectedItem) return null;
    const { type, index } = this.selectedItem;
    const scene = this.scenes[this.previewSceneIndex];
    if (type === 'slot') return scene?.slots?.[index]?.[prop];
    return (scene?.overlays?.[index] ?? this.overlayConfigs?.[index])?.[prop];
},

getSelectedOverlay() {
    if (!this.selectedItem || this.selectedItem.type !== 'overlay') return null;
    const scene = this.scenes[this.previewSceneIndex];
    return scene?.overlays?.[this.selectedItem.index] ?? this.overlayConfigs?.[this.selectedItem.index];
},

updateSelectedProperty(prop, value) {
    if (!this.selectedItem) return;
    const { type, index } = this.selectedItem;
    const scene = this.scenes[this.previewSceneIndex];
    if (!scene) return;

    if (type === 'slot' && scene.slots?.[index]) {
        scene.slots[index][prop] = value;
        if (previewCompositor) previewCompositor.setScene(scene);
        if (canvasEditor) canvasEditor.setSlots(scene.slots);
    } else if (type === 'overlay') {
        if (!scene.overlays) scene.overlays = [...(this.overlayConfigs || [])];
        if (scene.overlays[index]) {
            scene.overlays[index][prop] = value;
            if (previewCompositor) previewCompositor.setOverlays(scene.overlays);
            if (canvasEditor) canvasEditor.setOverlays(scene.overlays);
        }
    }
},

// Version management
addVersion() {
    const newScene = {
        name: `Version ${this.scenes.length + 1}`,
        layout: 'solo',
        slots: [{ label: 'A', source: 'host', x: 0, y: 0, w: 1, h: 1 }],
        overlays: [],
    };
    this.scenes.push(newScene);
    this.previewScene(this.scenes.length - 1);
    this.$wire.updateScenes(this.scenes);
},

cloneVersion() {
    const current = this.scenes[this.previewSceneIndex];
    if (!current) return;
    const clone = JSON.parse(JSON.stringify(current));
    clone.name = `${current.name} (copy)`;
    this.scenes.push(clone);
    this.previewScene(this.scenes.length - 1);
    this.$wire.updateScenes(this.scenes);
},

deleteVersion() {
    if (this.scenes.length <= 1) return;
    if (this.previewSceneIndex === this.liveSceneIndex) return;
    this.scenes.splice(this.previewSceneIndex, 1);
    if (this.previewSceneIndex >= this.scenes.length) {
        this.previewSceneIndex = this.scenes.length - 1;
    }
    if (this.liveSceneIndex >= this.scenes.length) {
        this.liveSceneIndex = this.scenes.length - 1;
    }
    this.previewScene(this.previewSceneIndex);
    this.$wire.updateScenes(this.scenes);
},
```

Update `disconnect()` to clean up:
```javascript
if (canvasEditor) { canvasEditor.destroy(); canvasEditor = null; }
```

- [ ] **Step 5: Update previewScene to sync editor handles**

Modify the existing `previewScene(index)` method:
```javascript
previewScene(index) {
    this.previewSceneIndex = index;
    const scene = this.scenes[index];
    if (previewCompositor && scene) {
        previewCompositor.setScene(scene);
    }
    // Sync editor handles if in editor mode
    if (canvasEditor && this.editorMode && scene) {
        canvasEditor.setSlots(scene.slots || []);
        canvasEditor.setOverlays(scene.overlays || this.overlayConfigs || []);
        this.selectedItem = null;
    }
},
```

- [ ] **Step 6: Build, test, and commit**

Run: `npm run build`
Run: `php artisan test --compact --filter="LiveEvent"`
Run: `vendor/bin/pint --dirty --format agent`

```bash
git add "resources/views/pages/events/⚡manage.blade.php"
git commit -m "feat(studio): interactive canvas editor — drag/resize, version tabs, properties panel"
```

---

### Task 4: Build, Test, and Push

- [ ] **Step 1: Final build**

Run: `npm run build`
Expected: Clean build.

- [ ] **Step 2: Run tests**

Run: `php artisan test --compact --filter="LiveEvent"`
Expected: All 62 tests pass.

- [ ] **Step 3: Push**

```bash
git push origin main
```

---

## Verification

1. **Editor toggle:** Click "Edit Layout" on Preview → handles appear over video slots with dashed green borders
2. **Drag slot:** Grab a slot handle, drag it → video re-renders in new position in real-time
3. **Resize slot:** Drag bottom-right corner → slot shrinks/grows → compositor matches
4. **Drag overlay:** Drag a text overlay → compositor re-renders it in new position
5. **Properties panel:** Click a slot → panel shows source dropdown, x/y/w/h fields, border radius
6. **Properties update:** Change source in dropdown → preview updates, change border radius → rounded corners appear
7. **Version tabs:** Click "+ New" → new version created → click between versions → different layouts load
8. **Clone version:** Click "Clone" → copy created with "(copy)" suffix
9. **Delete version:** Click "Delete" on non-live version → removed
10. **TAKE with version:** Preview shows different version → TAKE → Program switches
11. **Persist:** Click "Done Editing" → positions saved → refresh page → positions preserved
12. **Existing scenes:** Events with old-format scenes still render correctly
