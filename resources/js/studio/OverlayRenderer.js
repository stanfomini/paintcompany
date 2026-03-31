/**
 * OverlayRenderer — Captures HTML/iframe overlays as images for canvas compositing.
 *
 * Canvas drawImage() can't render live HTML. This module renders HTML overlays
 * in hidden DOM elements and captures them as images at a configurable interval.
 */

export default class OverlayRenderer {
    constructor(containerEl) {
        if (!containerEl) throw new Error('[OverlayRenderer] containerEl required');
        this._container = containerEl;
        this._overlays = new Map(); // index → {element, timer, image, config}
    }

    addOverlay(index, config) {
        this.removeOverlay(index); // Clean up if exists

        const width = Math.round(1280 * (config.w ?? 1));
        const height = Math.round(720 * (config.h ?? 0.08));

        // For raw HTML: render in a visible-but-offscreen div and capture
        // For iframes: capture is unreliable due to cross-origin restrictions,
        // so we render the iframe in the hidden container and try to capture.
        // If capture fails, the overlay just won't render (no crash).
        const wrapper = document.createElement('div');
        wrapper.style.cssText = `position:fixed;left:-9999px;top:0;width:${width}px;height:${height}px;overflow:hidden;z-index:-1;opacity:0;pointer-events:none;`;

        if (config.html) {
            // Raw HTML overlay — render directly
            const content = document.createElement('div');
            content.style.cssText = 'width:100%;height:100%;';
            content.innerHTML = config.html;
            wrapper.appendChild(content);
        } else if (config.src) {
            // Iframe overlay
            const iframe = document.createElement('iframe');
            iframe.src = config.src;
            iframe.style.cssText = 'width:100%;height:100%;border:none;';
            iframe.setAttribute('sandbox', 'allow-scripts allow-same-origin');
            wrapper.appendChild(iframe);
        }

        this._container.appendChild(wrapper);

        const entry = { element: wrapper, timer: null, image: null, config, width, height };
        this._overlays.set(index, entry);

        // Start capture timer — each capture is isolated and can't crash the compositor
        const interval = config.refreshInterval ?? 3000;
        const capture = () => this._captureOverlay(index);

        // Initial capture after iframe loads
        setTimeout(capture, 1500);
        entry.timer = setInterval(capture, interval);

        console.log(`[OverlayRenderer] Added overlay ${index} (${width}x${height})`);
    }

    removeOverlay(index) {
        const entry = this._overlays.get(index);
        if (!entry) return;
        if (entry.timer) clearInterval(entry.timer);
        entry.element.remove();
        this._overlays.delete(index);
        console.log(`[OverlayRenderer] Removed overlay ${index}`);
    }

    getCapturedImage(index) {
        return this._overlays.get(index)?.image ?? null;
    }

    destroy() {
        for (const [index] of this._overlays) {
            this.removeOverlay(index);
        }
    }

    async _captureOverlay(index) {
        const entry = this._overlays.get(index);
        if (!entry || entry._capturing) return;

        entry._capturing = true;
        try {
            const { default: html2canvas } = await import('html2canvas');

            // Temporarily make visible for capture (html2canvas needs visibility)
            entry.element.style.left = '0';
            entry.element.style.opacity = '1';

            const canvas = await html2canvas(entry.element, {
                width: entry.width,
                height: entry.height,
                scale: 1,
                logging: false,
                useCORS: true,
                allowTaint: true, // Allow cross-origin images to render (even if tainted)
                foreignObjectRendering: false, // More compatible
                removeContainer: true,
            });

            // Hide again
            entry.element.style.left = '-9999px';
            entry.element.style.opacity = '0';

            const img = new Image();
            img.src = canvas.toDataURL();
            await new Promise((resolve, reject) => {
                img.onload = resolve;
                img.onerror = reject;
                setTimeout(reject, 3000); // Timeout
            });
            entry.image = img;
        } catch (err) {
            // Hide element even on error
            if (entry?.element) {
                entry.element.style.left = '-9999px';
                entry.element.style.opacity = '0';
            }
            console.warn(`[OverlayRenderer] Capture failed for ${index}:`, err?.message ?? err);
        } finally {
            entry._capturing = false;
        }
    }
}
