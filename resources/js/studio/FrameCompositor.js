/**
 * FrameCompositor — Canvas-based video compositor for live streaming.
 *
 * Composites multiple video feeds and overlays onto an HTML5 Canvas,
 * producing a MediaStream via captureStream(30) for publishing to
 * LiveKit as the broadcast output.
 *
 * Render pipeline (each frame):
 *   1. Clear canvas (black)
 *   2. Draw each slot's video feed (or placeholder if missing)
 *   3. Draw overlays (text with optional background, images)
 *   4. requestAnimationFrame for next frame
 */

const CANVAS_WIDTH = 1280;
const CANVAS_HEIGHT = 720;
const TARGET_FPS = 30;
const FRAME_INTERVAL = 1000 / TARGET_FPS;

export default class FrameCompositor {
    constructor(canvas, options = {}) {
        this._canvas = canvas;
        this._ctx = canvas.getContext('2d');
        this._width = options.width ?? CANVAS_WIDTH;
        this._height = options.height ?? CANVAS_HEIGHT;

        canvas.width = this._width;
        canvas.height = this._height;

        // Scene state
        this._scene = null;
        this._overlays = [];

        // Registered video elements keyed by source name
        this._feedSources = new Map();

        // Preloaded Image objects keyed by overlay src URL
        this._imageCache = new Map();

        // Render loop state
        this._running = false;
        this._rafId = null;
        this._lastFrameTime = 0;

        // Output stream (lazy-created)
        this._outputStream = null;

        // Bind once so we can cancel cleanly
        this._renderFrame = this._renderFrame.bind(this);
    }

    // =========================================================================
    // Scene management
    // =========================================================================

    /**
     * Load a scene configuration.
     *
     * @param {Object} sceneConfig
     * @param {Array} sceneConfig.slots - Video slot definitions
     * @param {Array} [sceneConfig.overlays] - Overlay definitions
     */
    setScene(sceneConfig) {
        this._scene = sceneConfig;

        if (sceneConfig.overlays) {
            this.setOverlays(sceneConfig.overlays);
        } else {
            this._overlays = [];
        }
    }

    // =========================================================================
    // Feed source management
    // =========================================================================

    /**
     * Register a video element for a source key (e.g. "host", "guest-1").
     *
     * @param {string} sourceKey
     * @param {HTMLVideoElement} videoElement
     */
    setFeedSource(sourceKey, videoElement) {
        this._feedSources.set(sourceKey, videoElement);
    }

    /**
     * Remove a feed source by key.
     *
     * @param {string} sourceKey
     */
    removeFeedSource(sourceKey) {
        this._feedSources.delete(sourceKey);
    }

    // =========================================================================
    // Overlay management
    // =========================================================================

    /**
     * Replace the entire overlay list.
     * Preloads any image overlays so they're ready for rendering.
     *
     * @param {Array} overlays
     */
    setOverlays(overlays) {
        this._overlays = overlays.map((overlay) => ({
            ...overlay,
            visible: overlay.visible !== false,
        }));

        this._preloadImages();
    }

    /**
     * Update a text overlay's content by index.
     *
     * @param {number} index
     * @param {string} text
     */
    updateOverlayText(index, text) {
        if (this._overlays[index] && this._overlays[index].type === 'text') {
            this._overlays[index].content = text;
        }
    }

    /**
     * Show or hide an overlay by index.
     *
     * @param {number} index
     * @param {boolean} visible
     */
    toggleOverlay(index, visible) {
        if (this._overlays[index]) {
            this._overlays[index].visible = visible;
        }
    }

    // =========================================================================
    // Render loop control
    // =========================================================================

    start() {
        if (this._running) {
            return;
        }

        this._running = true;
        this._lastFrameTime = 0;
        this._rafId = requestAnimationFrame(this._renderFrame);
    }

    stop() {
        this._running = false;

        if (this._rafId !== null) {
            cancelAnimationFrame(this._rafId);
            this._rafId = null;
        }
    }

    // =========================================================================
    // Output
    // =========================================================================

    /**
     * Returns the canvas capture stream for publishing to LiveKit.
     *
     * @returns {MediaStream}
     */
    getOutputStream() {
        if (!this._outputStream) {
            this._outputStream = this._canvas.captureStream(TARGET_FPS);
        }

        return this._outputStream;
    }

    // =========================================================================
    // Cleanup
    // =========================================================================

    destroy() {
        this.stop();

        this._scene = null;
        this._overlays = [];
        this._feedSources.clear();
        this._imageCache.clear();
        this._outputStream = null;
        this._ctx = null;
        this._canvas = null;
    }

    // =========================================================================
    // Render loop (private)
    // =========================================================================

    _renderFrame(timestamp) {
        if (!this._running) {
            return;
        }

        // Throttle to target FPS
        const elapsed = timestamp - this._lastFrameTime;

        if (elapsed >= FRAME_INTERVAL) {
            this._lastFrameTime = timestamp - (elapsed % FRAME_INTERVAL);
            this._draw();
        }

        this._rafId = requestAnimationFrame(this._renderFrame);
    }

    _draw() {
        const ctx = this._ctx;
        const w = this._width;
        const h = this._height;

        // Clear with black background
        ctx.fillStyle = '#000000';
        ctx.fillRect(0, 0, w, h);

        // Draw video slots
        if (this._scene && this._scene.slots) {
            for (const slot of this._scene.slots) {
                this._drawSlot(ctx, slot, w, h);
            }
        }

        // Draw overlays
        for (const overlay of this._overlays) {
            if (!overlay.visible) {
                continue;
            }

            if (overlay.type === 'text') {
                this._drawTextOverlay(ctx, overlay, w, h);
            } else if (overlay.type === 'image') {
                this._drawImageOverlay(ctx, overlay, w, h);
            }
        }
    }

    /**
     * Draw a single video slot. If the source video element exists and has
     * data, draw it scaled to the slot rect. Otherwise draw a placeholder.
     */
    _drawSlot(ctx, slot, canvasW, canvasH) {
        const x = slot.x * canvasW;
        const y = slot.y * canvasH;
        const w = slot.w * canvasW;
        const h = slot.h * canvasH;

        const videoEl = this._feedSources.get(slot.source);

        if (videoEl && videoEl.readyState >= 2) {
            // drawImage handles aspect ratio stretching to fill the slot rect.
            // For a production compositor you might letterbox — this keeps it simple.
            ctx.drawImage(videoEl, x, y, w, h);
        } else {
            // Placeholder: dark gray box with source label
            ctx.fillStyle = '#1a1a1a';
            ctx.fillRect(x, y, w, h);

            ctx.fillStyle = '#555555';
            ctx.font = '600 14px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(slot.source, x + w / 2, y + h / 2);
        }
    }

    /**
     * Draw a text overlay with optional background box.
     */
    _drawTextOverlay(ctx, overlay, canvasW, canvasH) {
        const x = overlay.x * canvasW;
        const y = overlay.y * canvasH;
        const fontSize = overlay.fontSize ?? 24;
        const fontWeight = overlay.fontWeight ?? '700';
        const color = overlay.color ?? '#ffffff';

        ctx.font = `${fontWeight} ${fontSize}px sans-serif`;
        ctx.textBaseline = 'top';
        ctx.textAlign = 'left';

        // Measure text for background box
        const metrics = ctx.measureText(overlay.content);
        const textWidth = metrics.width;
        const textHeight = fontSize * 1.3; // approximate line height
        const padding = fontSize * 0.3;

        // Background box
        if (overlay.bg) {
            ctx.fillStyle = overlay.bg;
            ctx.fillRect(
                x - padding,
                y - padding,
                textWidth + padding * 2,
                textHeight + padding * 2,
            );
        }

        // Text
        ctx.fillStyle = color;
        ctx.fillText(overlay.content, x, y);
    }

    /**
     * Draw a preloaded image overlay.
     */
    _drawImageOverlay(ctx, overlay, canvasW, canvasH) {
        const img = this._imageCache.get(overlay.src);

        if (!img || !img.complete) {
            return;
        }

        const x = overlay.x * canvasW;
        const y = overlay.y * canvasH;
        const w = (overlay.w ?? 0.1) * canvasW;
        const h = (overlay.h ?? 0.1) * canvasH;

        ctx.drawImage(img, x, y, w, h);
    }

    // =========================================================================
    // Image preloading (private)
    // =========================================================================

    _preloadImages() {
        for (const overlay of this._overlays) {
            if (overlay.type !== 'image' || !overlay.src) {
                continue;
            }

            if (this._imageCache.has(overlay.src)) {
                continue;
            }

            const img = new Image();
            img.src = overlay.src;
            this._imageCache.set(overlay.src, img);
        }
    }
}
