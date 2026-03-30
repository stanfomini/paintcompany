/**
 * SourceManager — Registry of video sources for the live studio compositor.
 *
 * Guarantees every source has a <video> element that is:
 *   1. In the DOM (appended to a hidden container)
 *   2. Playing (.play() called explicitly)
 *   3. Tracked with state ('connecting' | 'live' | 'dead')
 *
 * Usage:
 *   const sm = new SourceManager(containerEl);
 *   sm.addSource('host', cameraStream);
 *   sm.on('source-ready', ({key}) => console.log(key, 'is drawable'));
 *   const el = sm.getElement('host'); // <video> with readyState >= 2
 */

export default class SourceManager {
    constructor(containerEl) {
        if (!containerEl || !(containerEl instanceof HTMLElement)) {
            throw new Error('[SourceManager] containerEl must be a DOM element');
        }
        this._container = containerEl;
        this._sources = new Map();
        this._listeners = new Map();
    }

    addSource(key, mediaStream, name = '') {
        if (this._sources.has(key)) {
            this.removeSource(key);
        }

        const el = document.createElement('video');
        el.srcObject = mediaStream;
        el.autoplay = true;
        el.muted = true;
        el.playsInline = true;
        el.dataset.sourceKey = key;
        el.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;';

        this._container.appendChild(el);

        const entry = {
            element: el,
            stream: mediaStream,
            state: 'connecting',
            name: name || key,
        };
        this._sources.set(key, entry);

        el.addEventListener('loadeddata', () => {
            if (this._sources.has(key)) {
                this._sources.get(key).state = 'live';
                console.log(`[SourceManager] Source ready: ${key} (readyState: ${el.readyState})`);
                this._emit('source-ready', { key, name: entry.name });
            }
        }, { once: true });

        const videoTrack = mediaStream.getVideoTracks()[0];
        if (videoTrack) {
            videoTrack.addEventListener('ended', () => {
                console.log(`[SourceManager] Track ended for: ${key}`);
                if (this._sources.has(key)) {
                    this._sources.get(key).state = 'dead';
                    this._emit('source-lost', { key });
                }
            }, { once: true });
        }

        el.play().catch(err => {
            console.warn(`[SourceManager] play() failed for ${key}:`, err.message);
        });

        console.log(`[SourceManager] Source added: ${key} (${name || key})`);
        this._emit('source-added', { key, name: entry.name });

        return entry;
    }

    removeSource(key) {
        const entry = this._sources.get(key);
        if (!entry) return;

        entry.element.pause();
        entry.element.srcObject = null;
        entry.element.remove();
        this._sources.delete(key);

        console.log(`[SourceManager] Source removed: ${key}`);
        this._emit('source-removed', { key });
    }

    getElement(key) {
        return this._sources.get(key)?.element ?? null;
    }

    getState(key) {
        return this._sources.get(key)?.state ?? null;
    }

    getAllSources() {
        return Array.from(this._sources.entries()).map(([key, entry]) => ({
            key,
            state: entry.state,
            name: entry.name,
        }));
    }

    setName(key, name) {
        const entry = this._sources.get(key);
        if (entry) {
            entry.name = name;
        }
    }

    on(event, callback) {
        if (!this._listeners.has(event)) {
            this._listeners.set(event, new Set());
        }
        this._listeners.get(event).add(callback);
    }

    off(event, callback) {
        this._listeners.get(event)?.delete(callback);
    }

    destroy() {
        for (const key of Array.from(this._sources.keys())) {
            this.removeSource(key);
        }
        this._listeners.clear();
    }

    _emit(event, data) {
        const callbacks = this._listeners.get(event);
        if (callbacks) {
            for (const cb of callbacks) {
                try {
                    cb(data);
                } catch (err) {
                    console.error(`[SourceManager] Event handler error (${event}):`, err);
                }
            }
        }
    }
}
