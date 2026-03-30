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
 *
 * Video elements are provided by a SourceManager instance, which
 * guarantees they are in the DOM and playing.
 */

const CANVAS_WIDTH = 1280;
const CANVAS_HEIGHT = 720;
const TARGET_FPS = 30;
const FRAME_INTERVAL = 1000 / TARGET_FPS;

export default class FrameCompositor {
    /**
     * @param {HTMLCanvasElement} canvas
     * @param {SourceManager} sourceManager - Provides video elements by source key
     * @param {Object} [options]
     * @param {number} [options.width=1280]
     * @param {number} [options.height=720]
     */
    constructor(canvas, sourceManager, options = {}) {
        this._canvas = canvas;
        this._ctx = canvas.getContext('2d');
        this._sourceManager = sourceManager;
        this._width = options.width ?? CANVAS_WIDTH;
        this._height = options.height ?? CANVAS_HEIGHT;

        canvas.width = this._width;
        canvas.height = this._height;

        this._scene = null;
        this._overlays = [];
        this._imageCache = new Map();
        this._running = false;
        this._rafId = null;
        this._lastFrameTime = 0;
        this._outputStream = null;

        this._renderFrame = this._renderFrame.bind(this);
    }

    setScene(sceneConfig) {
        this._scene = sceneConfig;

        if (sceneConfig.overlays) {
            this.setOverlays(sceneConfig.overlays);
        } else {
            this._overlays = [];
        }
    }

    setOverlays(overlays) {
        this._overlays = overlays.map((overlay) => ({
            ...overlay,
            visible: overlay.visible !== false,
        }));

        this._preloadImages();
    }

    updateOverlayText(index, text) {
        if (this._overlays[index] && this._overlays[index].type === 'text') {
            this._overlays[index].content = text;
        }
    }

    toggleOverlay(index, visible) {
        if (this._overlays[index]) {
            this._overlays[index].visible = visible;
        }
    }

    start() {
        if (this._running) {
            return;
        }

        this._running = true;
        this._lastFrameTime = 0;
        this._rafId = requestAnimationFrame(this._renderFrame);
        console.log('[Compositor] Started rendering');
    }

    stop() {
        this._running = false;

        if (this._rafId !== null) {
            cancelAnimationFrame(this._rafId);
            this._rafId = null;
        }
        console.log('[Compositor] Stopped rendering');
    }

    getOutputStream() {
        if (!this._outputStream) {
            this._outputStream = this._canvas.captureStream(TARGET_FPS);
        }

        return this._outputStream;
    }

    destroy() {
        this.stop();

        this._scene = null;
        this._overlays = [];
        this._imageCache.clear();
        this._outputStream = null;
        this._sourceManager = null;
        this._ctx = null;
        this._canvas = null;
    }

    _renderFrame(timestamp) {
        if (!this._running) {
            return;
        }

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

        ctx.fillStyle = '#000000';
        ctx.fillRect(0, 0, w, h);

        if (this._scene && this._scene.slots) {
            for (const slot of this._scene.slots) {
                this._drawSlot(ctx, slot, w, h);
            }
        }

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

    _drawSlot(ctx, slot, canvasW, canvasH) {
        const x = slot.x * canvasW;
        const y = slot.y * canvasH;
        const w = slot.w * canvasW;
        const h = slot.h * canvasH;

        const videoEl = this._sourceManager?.getElement(slot.source);

        if (videoEl && videoEl.readyState >= 2) {
            ctx.drawImage(videoEl, x, y, w, h);
        } else {
            ctx.fillStyle = '#1a1a1a';
            ctx.fillRect(x, y, w, h);

            ctx.fillStyle = '#555555';
            ctx.font = '600 14px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            const name = this._sourceManager?.getAllSources()
                .find(s => s.key === slot.source)?.name ?? slot.source;
            ctx.fillText(name, x + w / 2, y + h / 2);
        }
    }

    _drawTextOverlay(ctx, overlay, canvasW, canvasH) {
        const x = overlay.x * canvasW;
        const y = overlay.y * canvasH;
        const fontSize = overlay.fontSize ?? 24;
        const fontWeight = overlay.fontWeight ?? '700';
        const color = overlay.color ?? '#ffffff';

        ctx.font = `${fontWeight} ${fontSize}px sans-serif`;
        ctx.textBaseline = 'top';
        ctx.textAlign = 'left';

        const metrics = ctx.measureText(overlay.content);
        const textWidth = metrics.width;
        const textHeight = fontSize * 1.3;
        const padding = fontSize * 0.3;

        if (overlay.bg) {
            ctx.fillStyle = overlay.bg;
            ctx.fillRect(
                x - padding,
                y - padding,
                textWidth + padding * 2,
                textHeight + padding * 2,
            );
        }

        ctx.fillStyle = color;
        ctx.fillText(overlay.content, x, y);
    }

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
