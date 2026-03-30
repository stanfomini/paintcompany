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
     * @param {Object|null} [options.overlayRenderer=null] - Renderer with getCapturedImage(index)
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

        this._overlayRenderer = options.overlayRenderer ?? null;
        this._captionText = '';
        this._captionSpeaker = '';

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

    /**
     * Update the live caption text displayed by caption-type overlays.
     *
     * @param {string} speaker
     * @param {string} text
     */
    setCaptionText(speaker, text) {
        this._captionSpeaker = speaker;
        this._captionText = text;
    }

    /**
     * Set (or replace) the overlay renderer used by html-type overlays.
     *
     * @param {Object|null} renderer - Object with getCapturedImage(index): ImageBitmap|null
     */
    setOverlayRenderer(renderer) {
        this._overlayRenderer = renderer;
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
        this._overlayRenderer = null;
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
            } else if (overlay.type === 'html') {
                this._drawHtmlOverlay(ctx, overlay, w, h);
            } else if (overlay.type === 'caption') {
                this._drawCaptionOverlay(ctx, overlay, w, h);
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
            // Cover-style draw with optional crop offset and zoom
            // cropX/cropY: pan offset (0 = center, -0.5 to 0.5 range)
            // cropZoom: zoom level (1 = fill, 2 = 2x zoom, etc.)
            const vidW = videoEl.videoWidth || videoEl.width || w;
            const vidH = videoEl.videoHeight || videoEl.height || h;
            const slotRatio = w / h;
            const vidRatio = vidW / vidH;
            const zoom = Math.max(1, slot.cropZoom ?? 1);

            let sw, sh;
            if (vidRatio > slotRatio) {
                sh = vidH / zoom;
                sw = sh * slotRatio;
            } else {
                sw = vidW / zoom;
                sh = sw / slotRatio;
            }

            // Center + apply crop offset (cropX/cropY: -0.5 to 0.5)
            const cropX = slot.cropX ?? 0;
            const cropY = slot.cropY ?? 0;
            let sx = (vidW - sw) / 2 + cropX * (vidW - sw);
            let sy = (vidH - sh) / 2 + cropY * (vidH - sh);

            // Clamp to video bounds
            sx = Math.max(0, Math.min(vidW - sw, sx));
            sy = Math.max(0, Math.min(vidH - sh, sy));

            if (slot.borderRadius) {
                ctx.save();
                this._roundedRect(ctx, x, y, w, h, slot.borderRadius);
                ctx.clip();
                ctx.drawImage(videoEl, sx, sy, sw, sh, x, y, w, h);
                ctx.restore();
            } else {
                ctx.drawImage(videoEl, sx, sy, sw, sh, x, y, w, h);
            }
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
        const style = overlay.style;

        if (style === 'lower-third') {
            this._drawLowerThird(ctx, overlay, canvasW, canvasH);
        } else if (style === 'title') {
            this._drawTitleOverlay(ctx, overlay, canvasW, canvasH);
        } else if (style === 'ticker') {
            this._drawTickerOverlay(ctx, overlay, canvasW, canvasH);
        } else {
            // Default: existing behavior (custom or undefined style)
            this._drawDefaultTextOverlay(ctx, overlay, canvasW, canvasH);
        }
    }

    /**
     * Original text rendering — unchanged from initial implementation.
     * This is the fallback for overlays without a style field.
     */
    _drawDefaultTextOverlay(ctx, overlay, canvasW, canvasH) {
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

    /**
     * Lower-third: semi-transparent bar with text.
     * Uses overlay.x/y/w/h for position and size (set by drag handles).
     */
    _drawLowerThird(ctx, overlay, canvasW, canvasH) {
        // Use overlay position from drag handles, with sensible defaults
        const barX = (overlay.x ?? 0) * canvasW;
        const barY = (overlay.y ?? 0.78) * canvasH;
        const barW = (overlay.w ?? 1) * canvasW;
        const barH = (overlay.h ?? 0.14) * canvasH;
        const padding = 20;

        const bg = overlay.bg ?? 'rgba(0, 0, 0, 0.65)';
        ctx.fillStyle = bg;
        ctx.fillRect(barX, barY, barW, barH);

        // Accent line at top of bar
        ctx.fillStyle = overlay.accentColor ?? '#4f8ef7';
        ctx.fillRect(barX, barY, barW, 3);

        const nameFontSize = overlay.nameFontSize ?? 26;
        const contentFontSize = overlay.fontSize ?? 18;
        const color = overlay.color ?? '#ffffff';
        const nameColor = overlay.nameColor ?? '#ffffff';

        // Name (line 1)
        if (overlay.name) {
            ctx.font = `700 ${nameFontSize}px sans-serif`;
            ctx.textBaseline = 'top';
            ctx.textAlign = 'left';
            ctx.fillStyle = nameColor;
            ctx.fillText(overlay.name, barX + padding, barY + 10);
        }

        // Content (line 2)
        if (overlay.content) {
            ctx.font = `400 ${contentFontSize}px sans-serif`;
            ctx.textBaseline = 'top';
            ctx.textAlign = 'left';
            ctx.fillStyle = color;
            ctx.fillText(overlay.content, barX + padding, barY + 10 + nameFontSize * 1.3);
        }
    }

    /**
     * Title: centered text with large font, optional semi-transparent background box.
     */
    _drawTitleOverlay(ctx, overlay, canvasW, canvasH) {
        const fontSize = overlay.fontSize ?? 48;
        const fontWeight = overlay.fontWeight ?? '700';
        const color = overlay.color ?? '#ffffff';

        ctx.font = `${fontWeight} ${fontSize}px sans-serif`;
        ctx.textBaseline = 'middle';
        ctx.textAlign = 'center';

        // Use overlay position from drag handles
        const x = (overlay.x ?? 0.5) * canvasW;
        const y = (overlay.y ?? 0.5) * canvasH;

        if (overlay.bg) {
            const metrics = ctx.measureText(overlay.content);
            const textW = metrics.width;
            const textH = fontSize * 1.4;
            const padX = fontSize * 0.6;
            const padY = fontSize * 0.3;

            ctx.fillStyle = overlay.bg;
            ctx.fillRect(x - textW / 2 - padX, y - textH / 2 - padY, textW + padX * 2, textH + padY * 2);
        }

        ctx.fillStyle = color;
        ctx.fillText(overlay.content, x, y);
    }

    /**
     * Ticker: text scrolls from right to left across the canvas.
     * Tracks x offset in overlay._tickerOffset, incremented each frame.
     */
    _drawTickerOverlay(ctx, overlay, canvasW, canvasH) {
        const fontSize = overlay.fontSize ?? 20;
        const fontWeight = overlay.fontWeight ?? '400';
        const color = overlay.color ?? '#ffffff';
        const barX = (overlay.x ?? 0) * canvasW;
        const barW = (overlay.w ?? 1) * canvasW;
        const barH = (overlay.h != null) ? overlay.h * canvasH : fontSize * 2;
        const barY = (overlay.y ?? (1 - barH / canvasH)) * canvasH;
        const speed = overlay.tickerSpeed ?? 2;

        // Background bar
        const bg = overlay.bg ?? 'rgba(0, 0, 0, 0.75)';
        ctx.fillStyle = bg;
        ctx.fillRect(barX, barY, barW, barH);

        ctx.font = `${fontWeight} ${fontSize}px sans-serif`;
        ctx.textBaseline = 'middle';
        ctx.textAlign = 'left';

        const metrics = ctx.measureText(overlay.content);
        const textW = metrics.width + 80; // padding between loops

        // Initialize offset on first draw
        if (overlay._tickerOffset == null) {
            overlay._tickerOffset = canvasW;
        }

        // Advance the ticker
        overlay._tickerOffset -= speed;

        // Loop back when text has fully scrolled off left edge
        if (overlay._tickerOffset < barX - textW) {
            overlay._tickerOffset = barX + barW;
        }

        ctx.save();
        ctx.rect(barX, barY, barW, barH);
        ctx.clip();

        ctx.fillStyle = color;
        ctx.fillText(overlay.content, overlay._tickerOffset, barY + barH / 2);

        ctx.restore();
    }

    /**
     * HTML overlay: draws a captured image from the overlay renderer.
     * Expects overlay._rendererIndex to identify which renderer slot to use.
     */
    _drawHtmlOverlay(ctx, overlay, canvasW, canvasH) {
        try {
            const image = this._overlayRenderer?.getCapturedImage(overlay._rendererIndex);

            if (!image || !image.complete || image.naturalWidth === 0) {
                return; // No capture yet or broken image — skip silently
            }

            const x = (overlay.x ?? 0) * canvasW;
            const y = (overlay.y ?? 0) * canvasH;
            const w = (overlay.w ?? 1) * canvasW;
            const h = (overlay.h ?? 1) * canvasH;

            ctx.drawImage(image, x, y, w, h);
        } catch (err) {
            // Never let a broken HTML overlay crash the render loop
            console.warn('[Compositor] HTML overlay draw error:', err.message);
        }
    }

    /**
     * Caption overlay: renders live caption text with speaker prefix.
     * Supports word-wrap to overlay.maxLines (default 2).
     */
    _drawCaptionOverlay(ctx, overlay, canvasW, canvasH) {
        if (!this._captionText) {
            return;
        }

        const fontSize = overlay.fontSize ?? 22;
        const fontWeight = overlay.fontWeight ?? '600';
        const color = overlay.color ?? '#ffffff';
        const maxLines = overlay.maxLines ?? 2;
        const padding = fontSize * 0.6;
        const lineHeight = fontSize * 1.4;
        const maxWidth = canvasW * 0.85;

        ctx.font = `${fontWeight} ${fontSize}px sans-serif`;
        ctx.textBaseline = 'top';
        ctx.textAlign = 'left';

        // Build full text with speaker prefix
        const fullText = this._captionSpeaker
            ? `${this._captionSpeaker}: ${this._captionText}`
            : this._captionText;

        // Word-wrap
        const words = fullText.split(' ');
        const lines = [];
        let currentLine = '';

        for (const word of words) {
            const testLine = currentLine ? `${currentLine} ${word}` : word;
            const { width } = ctx.measureText(testLine);

            if (width > maxWidth && currentLine) {
                lines.push(currentLine);
                currentLine = word;

                if (lines.length >= maxLines) {
                    break;
                }
            } else {
                currentLine = testLine;
            }
        }

        if (currentLine && lines.length < maxLines) {
            lines.push(currentLine);
        }

        const totalH = lines.length * lineHeight;
        const boxH = totalH + padding * 2;
        // Use drag handle position if set
        const boxX = (overlay.x ?? 0.075) * canvasW;
        const boxY = (overlay.y ?? (1 - (boxH + 40) / canvasH)) * canvasH;
        const boxW = (overlay.w ?? 0.85) * canvasW;

        // Background box
        const bg = overlay.bg ?? 'rgba(0, 0, 0, 0.7)';
        ctx.fillStyle = bg;
        ctx.fillRect(boxX, boxY, boxW, boxH);

        // Draw each line
        ctx.fillStyle = color;

        for (let i = 0; i < lines.length; i++) {
            ctx.fillText(lines[i], boxX + padding, boxY + padding + i * lineHeight);
        }
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
