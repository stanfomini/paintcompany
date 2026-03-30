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
        // Container visibility is managed by Alpine :style binding
        // Just ensure handles are visible
        for (const [, entry] of this._handles) {
            entry.element.style.display = '';
        }
        console.log('[CanvasEditor] Enabled');
    }

    disable() {
        this._enabled = false;
        this.clearSelection();
        // Container visibility is managed by Alpine :style binding
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

            // Ensure overlay handles have a minimum visible size
            const w = Math.max((overlay.w ?? 0.3) * 100, 15);
            const h = Math.max((overlay.h ?? 0.08) * 100, 5);

            this._createHandle(key, {
                type: 'overlay',
                index,
                x: (overlay.x ?? 0) * 100,
                y: (overlay.y ?? 0) * 100,
                w,
                h,
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
            background: ${config.type === 'overlay' ? 'rgba(0,0,0,0.15)' : 'transparent'};
            cursor: move;
            box-sizing: border-box;
            touch-action: none;
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

        // Resize handle (bottom-right corner, inside the handle)
        const resizeHandle = document.createElement('div');
        resizeHandle.className = 'ce-resize';
        resizeHandle.style.cssText = `
            position: absolute; right: 2px; bottom: 2px;
            width: 12px; height: 12px; background: white; border: 2px solid #333;
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
