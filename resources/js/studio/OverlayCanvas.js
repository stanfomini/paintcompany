import { Canvas, Textbox } from 'fabric';

/**
 * @typedef {Object} OverlayStyle
 * @property {number} font_size
 * @property {string} color
 * @property {string} font_weight
 * @property {string} [bg_color]
 */

/**
 * @typedef {Object} OverlayData
 * @property {number} id
 * @property {'text'|'caption'} type
 * @property {string} content
 * @property {OverlayStyle} style
 * @property {number} position_x - Fraction of canvas width (0-1)
 * @property {number} position_y - Fraction of canvas height (0-1)
 * @property {number|null} width - Fraction of canvas width, or null
 * @property {number|null} height - Fraction of canvas height, or null
 * @property {number} start_ms
 * @property {number} end_ms
 */

/**
 * @typedef {Object} OverlayUpdatePayload
 * @property {number} [position_x]
 * @property {number} [position_y]
 * @property {number} [width]
 * @property {number} [height]
 * @property {string} [content]
 */

/**
 * @typedef {Object} OverlayCanvasCallbacks
 * @property {function(number, OverlayUpdatePayload): void} onOverlayUpdate
 * @property {function(number): void} onOverlaySelect
 */

/** @type {number} Reference height used for font size scaling */
const REFERENCE_HEIGHT = 1080;

/** @type {number} Default caption vertical position as fraction of canvas height */
const CAPTION_POSITION_Y = 0.85;

/**
 * Manages a Fabric.js canvas overlay on top of the video composition area.
 *
 * Handles text and caption overlays with drag, resize, rotate, and inline
 * editing. Coordinates are stored as fractions (0-1) of canvas dimensions
 * so overlays scale correctly when the canvas is resized.
 */
export default class OverlayCanvas {
    /** @type {Canvas|null} */
    _canvas = null;

    /** @type {number} */
    _width = 0;

    /** @type {number} */
    _height = 0;

    /** @type {function(number, OverlayUpdatePayload): void} */
    _onOverlayUpdate;

    /** @type {function(number): void} */
    _onOverlaySelect;

    /**
     * @param {OverlayCanvasCallbacks} callbacks
     */
    constructor({ onOverlayUpdate, onOverlaySelect }) {
        this._onOverlayUpdate = onOverlayUpdate ?? (() => {});
        this._onOverlaySelect = onOverlaySelect ?? (() => {});
    }

    /**
     * Initialize the Fabric.js canvas on the given HTML canvas element.
     *
     * @param {HTMLCanvasElement} canvasElement
     * @param {number} containerWidth
     * @param {number} containerHeight
     */
    init(canvasElement, containerWidth, containerHeight) {
        this._width = containerWidth;
        this._height = containerHeight;

        this._canvas = new Canvas(canvasElement, {
            width: containerWidth,
            height: containerHeight,
            selection: true,
            preserveObjectStacking: true,
        });

        this._bindEvents();
    }

    /**
     * Update canvas dimensions for responsive layout.
     *
     * @param {number} width
     * @param {number} height
     */
    resize(width, height) {
        if (!this._canvas) {
            return;
        }

        const prevWidth = this._width;
        const prevHeight = this._height;

        this._width = width;
        this._height = height;

        this._canvas.setDimensions({ width, height });

        // Reposition and rescale all objects to maintain their fractional positions
        const objects = this._canvas.getObjects();

        for (const obj of objects) {
            const fracX = prevWidth > 0 ? obj.left / prevWidth : 0;
            const fracY = prevHeight > 0 ? obj.top / prevHeight : 0;

            obj.set({
                left: fracX * width,
                top: fracY * height,
                fontSize: this._scaleFontSize(obj._baseFontSize ?? obj.fontSize),
            });

            if (obj._fracWidth !== null && obj._fracWidth !== undefined) {
                obj.set({ width: obj._fracWidth * width });
            }

            obj.setCoords();
        }

        this._canvas.renderAll();
    }

    /**
     * Clear the canvas and load a full set of overlays.
     *
     * @param {OverlayData[]} overlays
     */
    loadOverlays(overlays) {
        if (!this._canvas) {
            return;
        }

        this._canvas.clear();

        for (const overlay of overlays) {
            this._createAndAddObject(overlay);
        }

        this._canvas.renderAll();
    }

    /**
     * Show or hide overlays based on the current playback time.
     *
     * Each Fabric object stores `_startMs` and `_endMs` custom properties.
     * Objects outside the current time range are hidden.
     *
     * @param {number} currentTimeMs
     */
    updateVisibility(currentTimeMs) {
        if (!this._canvas) {
            return;
        }

        const objects = this._canvas.getObjects();

        for (const obj of objects) {
            const inRange = currentTimeMs >= obj._startMs && currentTimeMs <= obj._endMs;
            obj.visible = inRange;
        }

        this._canvas.renderAll();
    }

    /**
     * Add a single overlay to the canvas.
     *
     * @param {OverlayData} overlay
     */
    addOverlay(overlay) {
        if (!this._canvas) {
            return;
        }

        this._createAndAddObject(overlay);
        this._canvas.renderAll();
    }

    /**
     * Remove an overlay from the canvas by its ID.
     *
     * @param {number} overlayId
     */
    removeOverlay(overlayId) {
        if (!this._canvas) {
            return;
        }

        const obj = this._findObjectById(overlayId);

        if (obj) {
            this._canvas.remove(obj);
            this._canvas.renderAll();
        }
    }

    /**
     * Update the position, content, or style of an existing overlay.
     *
     * @param {number} overlayId
     * @param {Partial<OverlayData>} data
     */
    updateOverlay(overlayId, data) {
        if (!this._canvas) {
            return;
        }

        const obj = this._findObjectById(overlayId);

        if (!obj) {
            return;
        }

        if (data.content !== undefined) {
            obj.set({ text: data.content });
        }

        if (data.position_x !== undefined) {
            obj.set({ left: data.position_x * this._width });
        }

        if (data.position_y !== undefined) {
            obj.set({ top: data.position_y * this._height });
        }

        if (data.width !== undefined && data.width !== null) {
            obj.set({ width: data.width * this._width });
            obj._fracWidth = data.width;
        }

        if (data.height !== undefined && data.height !== null) {
            obj.set({ height: data.height * this._height });
        }

        if (data.start_ms !== undefined) {
            obj._startMs = data.start_ms;
        }

        if (data.end_ms !== undefined) {
            obj._endMs = data.end_ms;
        }

        if (data.style) {
            this._applyStyle(obj, data.style);
        }

        obj.setCoords();
        this._canvas.renderAll();
    }

    /**
     * Enable or disable interactive selection and dragging on all objects.
     *
     * Call `setInteractive(false)` during playback to prevent the user from
     * accidentally moving overlays. Call `setInteractive(true)` on pause.
     *
     * @param {boolean} enabled
     */
    setInteractive(enabled) {
        if (!this._canvas) {
            return;
        }

        this._canvas.selection = enabled;

        const objects = this._canvas.getObjects();

        for (const obj of objects) {
            obj.selectable = enabled;
            obj.evented = enabled;
        }

        if (!enabled) {
            this._canvas.discardActiveObject();
        }

        this._canvas.renderAll();
    }

    /**
     * Dispose of the Fabric canvas and release all references.
     */
    destroy() {
        if (this._canvas) {
            this._canvas.dispose();
            this._canvas = null;
        }

        this._width = 0;
        this._height = 0;
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    /**
     * Bind Fabric.js canvas-level events for drag, resize, selection, and editing.
     */
    _bindEvents() {
        this._canvas.on('object:modified', (e) => {
            const obj = e.target;

            if (!obj || obj.overlayId === undefined) {
                return;
            }

            const payload = this._computePositionPayload(obj);
            this._onOverlayUpdate(obj.overlayId, payload);
        });

        this._canvas.on('selection:created', (e) => {
            this._handleSelection(e);
        });

        this._canvas.on('selection:updated', (e) => {
            this._handleSelection(e);
        });

        this._canvas.on('text:changed', (e) => {
            const obj = e.target;

            if (!obj || obj.overlayId === undefined) {
                return;
            }

            this._onOverlayUpdate(obj.overlayId, { content: obj.text });
        });
    }

    /**
     * Handle a selection event and dispatch onOverlaySelect for the selected object.
     *
     * @param {Object} e - Fabric selection event
     */
    _handleSelection(e) {
        const selected = e.selected;

        if (!selected || selected.length === 0) {
            return;
        }

        const obj = selected[0];

        if (obj.overlayId !== undefined) {
            this._onOverlaySelect(obj.overlayId);
        }
    }

    /**
     * Create a Fabric.js Textbox from overlay data and add it to the canvas.
     *
     * @param {OverlayData} overlay
     */
    _createAndAddObject(overlay) {
        const posX = overlay.type === 'caption' && overlay.position_x === undefined
            ? 0.5
            : overlay.position_x;

        const posY = overlay.type === 'caption' && overlay.position_y === undefined
            ? CAPTION_POSITION_Y
            : overlay.position_y;

        const scaledFontSize = this._scaleFontSize(overlay.style.font_size);

        const options = {
            left: posX * this._width,
            top: posY * this._height,
            fontSize: scaledFontSize,
            fill: overlay.style.color ?? '#ffffff',
            fontWeight: overlay.style.font_weight ?? 'normal',
            editable: true,
            originX: overlay.type === 'caption' ? 'center' : 'left',
            originY: 'top',
        };

        if (overlay.style.bg_color) {
            options.textBackgroundColor = overlay.style.bg_color;
        }

        if (overlay.width !== null && overlay.width !== undefined) {
            options.width = overlay.width * this._width;
        }

        // For captions, center horizontally on the canvas
        if (overlay.type === 'caption') {
            options.left = this._width / 2;
            options.top = posY * this._height;
            options.textAlign = 'center';

            if (!options.width) {
                options.width = this._width * 0.8;
            }
        }

        const textbox = new Textbox(overlay.content ?? '', options);

        // Store custom properties for tracking
        textbox.overlayId = overlay.id;
        textbox._startMs = overlay.start_ms;
        textbox._endMs = overlay.end_ms;
        textbox._overlayType = overlay.type;
        textbox._baseFontSize = overlay.style.font_size;
        textbox._fracWidth = overlay.width ?? null;

        this._canvas.add(textbox);
    }

    /**
     * Find a Fabric object by its overlay ID.
     *
     * @param {number} overlayId
     * @returns {Textbox|null}
     */
    _findObjectById(overlayId) {
        const objects = this._canvas.getObjects();

        for (const obj of objects) {
            if (obj.overlayId === overlayId) {
                return obj;
            }
        }

        return null;
    }

    /**
     * Apply style properties to an existing Fabric Textbox.
     *
     * @param {Textbox} obj
     * @param {Partial<OverlayStyle>} style
     */
    _applyStyle(obj, style) {
        if (style.font_size !== undefined) {
            obj._baseFontSize = style.font_size;
            obj.set({ fontSize: this._scaleFontSize(style.font_size) });
        }

        if (style.color !== undefined) {
            obj.set({ fill: style.color });
        }

        if (style.font_weight !== undefined) {
            obj.set({ fontWeight: style.font_weight });
        }

        if (style.bg_color !== undefined) {
            obj.set({ textBackgroundColor: style.bg_color || '' });
        }
    }

    /**
     * Scale a font size relative to the reference height (1080p).
     *
     * This ensures text appears at the correct visual size regardless of the
     * actual canvas dimensions.
     *
     * @param {number} baseFontSize
     * @returns {number}
     */
    _scaleFontSize(baseFontSize) {
        return baseFontSize * (this._height / REFERENCE_HEIGHT);
    }

    /**
     * Convert a Fabric object's pixel position and dimensions back to
     * fractional (0-1) coordinates for storage.
     *
     * @param {Textbox} obj
     * @returns {OverlayUpdatePayload}
     */
    _computePositionPayload(obj) {
        const payload = {
            position_x: this._width > 0 ? obj.left / this._width : 0,
            position_y: this._height > 0 ? obj.top / this._height : 0,
        };

        if (obj.width && this._width > 0) {
            payload.width = (obj.width * (obj.scaleX ?? 1)) / this._width;
        }

        if (obj.height && this._height > 0) {
            payload.height = (obj.height * (obj.scaleY ?? 1)) / this._height;
        }

        return payload;
    }
}
