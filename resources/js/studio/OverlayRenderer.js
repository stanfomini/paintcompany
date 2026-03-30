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

        const wrapper = document.createElement('div');
        wrapper.style.cssText = 'position:absolute;left:-9999px;top:-9999px;';
        // Size proportional to canvas (1280x720)
        const width = Math.round(1280 * (config.w ?? 1));
        const height = Math.round(720 * (config.h ?? 0.08));
        wrapper.style.width = width + 'px';
        wrapper.style.height = height + 'px';
        wrapper.style.overflow = 'hidden';

        if (config.src && config.type === 'html') {
            // Iframe overlay
            const iframe = document.createElement('iframe');
            iframe.src = config.src;
            iframe.style.cssText = 'width:100%;height:100%;border:none;';
            wrapper.appendChild(iframe);
        } else if (config.html) {
            // Raw HTML overlay
            wrapper.innerHTML = config.html;
        }

        this._container.appendChild(wrapper);

        const entry = { element: wrapper, timer: null, image: null, config };

        // Start capture timer
        const interval = config.refreshInterval ?? 2000;
        const capture = async () => {
            try {
                // Dynamic import html2canvas
                const { default: html2canvas } = await import('html2canvas');
                const canvas = await html2canvas(wrapper, {
                    width, height, scale: 1, logging: false, useCORS: true,
                });
                const img = new Image();
                img.src = canvas.toDataURL();
                await new Promise(r => { img.onload = r; });
                entry.image = img;
            } catch (err) {
                console.warn(`[OverlayRenderer] Capture failed for index ${index}:`, err.message);
            }
        };

        // Initial capture after a short delay (let iframe load)
        setTimeout(() => capture(), 500);
        entry.timer = setInterval(() => capture(), interval);

        this._overlays.set(index, entry);
        console.log(`[OverlayRenderer] Added overlay ${index}`);
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
}
