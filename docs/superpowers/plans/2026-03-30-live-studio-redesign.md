# Live Studio Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the live production studio with OBS-style architecture — direct WebRTC guest inputs, SourceManager for video element lifecycle, always-on dual Preview/Program compositors, LiveKit only for broadcast output.

**Architecture:** SourceManager guarantees video elements are in the DOM and playing. PeerManager handles direct WebRTC connections to guests via Reverb signaling. Two FrameCompositor instances (Preview + Program) share the SourceManager. Only the Program canvas is published to LiveKit.

**Tech Stack:** LiveKit (broadcast only), WebRTC RTCPeerConnection (guest inputs), Reverb/Echo (signaling), Canvas API (compositing), Livewire 4 SFC, Alpine.js

**Spec:** `docs/superpowers/specs/2026-03-30-live-studio-redesign.md`

---

## File Structure

### New Files
| File | Responsibility |
|------|---------------|
| `resources/js/studio/SourceManager.js` | Video source registry — creates `<video>` elements in DOM, tracks state, provides elements to compositors |
| `resources/js/studio/PeerManager.js` | Host-side WebRTC peer connection manager — one RTCPeerConnection per guest, Reverb signaling |
| `resources/js/studio/GuestPeerConnection.js` | Guest-side WebRTC peer connection — connects to host, sends camera/mic |

### Modified Files
| File | What Changes |
|------|-------------|
| `resources/js/studio/FrameCompositor.js` | Constructor takes SourceManager, `_drawSlot()` uses `sourceManager.getElement()` instead of internal Map |
| `resources/views/pages/events/⚡manage.blade.php` | Full rewrite of live state: dual Preview/Program monitors, SourceManager + PeerManager integration, TAKE workflow, remove frame mode toggle, remove LiveKit guest handling |
| `resources/views/pages/events/⚡guest-room.blade.php` | Replace LiveKit publish with WebRTC peer connection to host via Reverb |
| `app/Http/Controllers/EventGuestAuthController.php` | Add support for `event-studio.{eventId}` presence channel auth |
| `app/Services/LiveKitService.php` | Guest tokens: `canPublish: false` |
| `resources/js/app.js` | Add `loadSourceManager`, `loadPeerManager` lazy loaders (same pattern as existing `loadLiveKit`, `loadFrameCompositor`) |

---

### Task 1: SourceManager — Video Source Registry

**Files:**
- Create: `resources/js/studio/SourceManager.js`

This is the foundation everything else builds on. It guarantees video elements are in the DOM and playing.

- [ ] **Step 1: Create SourceManager.js**

```javascript
// resources/js/studio/SourceManager.js

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
        this._sources = new Map(); // key → {element, stream, state, name}
        this._listeners = new Map(); // eventName → Set<callback>
    }

    // =========================================================================
    // Source lifecycle
    // =========================================================================

    /**
     * Register a video source. Creates a <video> element in the DOM and starts playing.
     *
     * @param {string} key - Source identifier ('host', 'guest-1', 'screen', etc.)
     * @param {MediaStream} mediaStream - The video/audio MediaStream
     * @param {string} [name] - Human-readable display name
     */
    addSource(key, mediaStream, name = '') {
        // Remove existing source with same key if present
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

        // Append to DOM — critical for browser to advance readyState
        this._container.appendChild(el);

        const entry = {
            element: el,
            stream: mediaStream,
            state: 'connecting',
            name: name || key,
        };
        this._sources.set(key, entry);

        // Listen for video to be ready (readyState >= 2)
        el.addEventListener('loadeddata', () => {
            if (this._sources.has(key)) {
                this._sources.get(key).state = 'live';
                console.log(`[SourceManager] Source ready: ${key} (readyState: ${el.readyState})`);
                this._emit('source-ready', { key, name: entry.name });
            }
        }, { once: true });

        // Listen for track ending (e.g. guest disconnects)
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

        // Explicitly call play — autoplay alone doesn't guarantee playback
        el.play().catch(err => {
            console.warn(`[SourceManager] play() failed for ${key}:`, err.message);
        });

        console.log(`[SourceManager] Source added: ${key} (${name || key})`);
        this._emit('source-added', { key, name: entry.name });

        return entry;
    }

    /**
     * Remove a source and clean up its DOM element.
     *
     * @param {string} key
     */
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

    // =========================================================================
    // Queries
    // =========================================================================

    /**
     * Get the <video> element for a source key. Returns null if not registered.
     *
     * @param {string} key
     * @returns {HTMLVideoElement|null}
     */
    getElement(key) {
        return this._sources.get(key)?.element ?? null;
    }

    /**
     * Get the state of a source: 'connecting', 'live', or 'dead'.
     *
     * @param {string} key
     * @returns {string|null}
     */
    getState(key) {
        return this._sources.get(key)?.state ?? null;
    }

    /**
     * Get all registered sources as an array of {key, state, name} objects.
     *
     * @returns {Array<{key: string, state: string, name: string}>}
     */
    getAllSources() {
        return Array.from(this._sources.entries()).map(([key, entry]) => ({
            key,
            state: entry.state,
            name: entry.name,
        }));
    }

    /**
     * Update the display name of a source.
     *
     * @param {string} key
     * @param {string} name
     */
    setName(key, name) {
        const entry = this._sources.get(key);
        if (entry) {
            entry.name = name;
        }
    }

    // =========================================================================
    // Events
    // =========================================================================

    /**
     * Register an event listener.
     * Events: 'source-added', 'source-ready', 'source-removed', 'source-lost'
     *
     * @param {string} event
     * @param {Function} callback
     */
    on(event, callback) {
        if (!this._listeners.has(event)) {
            this._listeners.set(event, new Set());
        }
        this._listeners.get(event).add(callback);
    }

    /**
     * Remove an event listener.
     *
     * @param {string} event
     * @param {Function} callback
     */
    off(event, callback) {
        this._listeners.get(event)?.delete(callback);
    }

    // =========================================================================
    // Cleanup
    // =========================================================================

    /**
     * Remove all sources and clean up.
     */
    destroy() {
        for (const key of Array.from(this._sources.keys())) {
            this.removeSource(key);
        }
        this._listeners.clear();
    }

    // =========================================================================
    // Private
    // =========================================================================

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
```

- [ ] **Step 2: Add lazy loader to app.js**

Add to `resources/js/app.js` alongside the existing `loadLiveKit` and `loadFrameCompositor` loaders:

```javascript
window.loadSourceManager = async () => {
    if (!window.StudioSourceManager) {
        const module = await import('./studio/SourceManager.js');
        window.StudioSourceManager = module.default;
    }
    return window.StudioSourceManager;
};
```

- [ ] **Step 3: Build to verify no syntax errors**

Run: `npm run build`
Expected: Build succeeds, `SourceManager` chunk appears in output.

- [ ] **Step 4: Commit**

```bash
git add resources/js/studio/SourceManager.js resources/js/app.js
git commit -m "feat(studio): add SourceManager — video source registry with DOM lifecycle"
```

---

### Task 2: Update FrameCompositor to Use SourceManager

**Files:**
- Modify: `resources/js/studio/FrameCompositor.js`

Remove the internal `_feedSources` Map. Use SourceManager's `getElement()` instead.

- [ ] **Step 1: Update FrameCompositor constructor and _drawSlot**

Replace the entire file `resources/js/studio/FrameCompositor.js`:

```javascript
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

        // Scene state
        this._scene = null;
        this._overlays = [];

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

    setScene(sceneConfig) {
        this._scene = sceneConfig;

        if (sceneConfig.overlays) {
            this.setOverlays(sceneConfig.overlays);
        } else {
            this._overlays = [];
        }
    }

    // =========================================================================
    // Overlay management
    // =========================================================================

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

    // =========================================================================
    // Output
    // =========================================================================

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
        this._imageCache.clear();
        this._outputStream = null;
        this._sourceManager = null;
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
     * Draw a single video slot. Gets the video element from SourceManager.
     * If the source exists and is playing (readyState >= 2), draws it.
     * Otherwise draws a labeled placeholder.
     */
    _drawSlot(ctx, slot, canvasW, canvasH) {
        const x = slot.x * canvasW;
        const y = slot.y * canvasH;
        const w = slot.w * canvasW;
        const h = slot.h * canvasH;

        // Get video element from SourceManager (not internal Map)
        const videoEl = this._sourceManager?.getElement(slot.source);

        if (videoEl && videoEl.readyState >= 2) {
            ctx.drawImage(videoEl, x, y, w, h);
        } else {
            // Placeholder: dark gray box with source label
            ctx.fillStyle = '#1a1a1a';
            ctx.fillRect(x, y, w, h);

            ctx.fillStyle = '#555555';
            ctx.font = '600 14px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            // Show human-readable name if available
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
```

- [ ] **Step 2: Build to verify no syntax errors**

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 3: Commit**

```bash
git add resources/js/studio/FrameCompositor.js
git commit -m "refactor(studio): FrameCompositor uses SourceManager instead of internal feedSources Map"
```

---

### Task 3: PeerManager — Host-Side WebRTC Connections

**Files:**
- Create: `resources/js/studio/PeerManager.js`

Manages one RTCPeerConnection per guest. Uses Reverb Echo channel for signaling. Pattern borrowed from existing `resources/views/components/video-call/⚡test.blade.php`.

- [ ] **Step 1: Create PeerManager.js**

```javascript
// resources/js/studio/PeerManager.js

/**
 * PeerManager — Host-side WebRTC peer connection manager for live studio.
 *
 * Creates one RTCPeerConnection per guest. Uses a Reverb Echo channel
 * for signaling (offer/answer/ICE candidate exchange).
 *
 * When a guest's video arrives, calls the onRemoteStream callback so the
 * host's Alpine component can register it with SourceManager.
 *
 * Signaling protocol (via Echo .whisper()):
 *   Guest → Host: 'webrtc-offer'   {guestId, sdp}
 *   Host → Guest: 'webrtc-answer'  {guestId, sdp}
 *   Both:         'ice-candidate'  {guestId, targetId, candidate}
 *   Guest → Host: 'guest-ready'    {guestId, name}
 *   Guest → Host: 'guest-disconnect' {guestId}
 */

const ICE_SERVERS = [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
];

export default class PeerManager {
    /**
     * @param {Object} echoChannel - Echo presence channel instance
     * @param {string} hostId - Unique host identifier for signaling
     * @param {Array} [iceServers] - Custom ICE servers (optional)
     */
    constructor(echoChannel, hostId, iceServers = ICE_SERVERS) {
        this._channel = echoChannel;
        this._hostId = hostId;
        this._iceServers = iceServers;
        this._connections = new Map(); // guestId → RTCPeerConnection
        this._onRemoteStreamCallback = null;
        this._onGuestReadyCallback = null;
        this._onGuestDisconnectCallback = null;

        this._setupSignaling();
    }

    // =========================================================================
    // Event callbacks
    // =========================================================================

    /**
     * Called when a guest's video/audio stream arrives.
     * @param {Function} callback - ({guestId, stream: MediaStream}) => void
     */
    onRemoteStream(callback) {
        this._onRemoteStreamCallback = callback;
    }

    /**
     * Called when a guest signals they're ready (camera on, waiting).
     * @param {Function} callback - ({guestId, name}) => void
     */
    onGuestReady(callback) {
        this._onGuestReadyCallback = callback;
    }

    /**
     * Called when a guest disconnects.
     * @param {Function} callback - ({guestId}) => void
     */
    onGuestDisconnect(callback) {
        this._onGuestDisconnectCallback = callback;
    }

    // =========================================================================
    // Connection management
    // =========================================================================

    /**
     * Close a specific guest's peer connection.
     * @param {string} guestId
     */
    closeConnection(guestId) {
        const pc = this._connections.get(guestId);
        if (pc) {
            pc.close();
            this._connections.delete(guestId);
            console.log(`[PeerManager] Connection closed: ${guestId}`);
        }
    }

    /**
     * Close all peer connections and stop listening.
     */
    closeAll() {
        for (const [guestId, pc] of this._connections) {
            pc.close();
            console.log(`[PeerManager] Connection closed: ${guestId}`);
        }
        this._connections.clear();
    }

    /**
     * Destroy — close all connections and remove signaling listeners.
     */
    destroy() {
        this.closeAll();
        this._channel = null;
    }

    // =========================================================================
    // Private — Signaling
    // =========================================================================

    _setupSignaling() {
        // Guest sends WebRTC offer
        this._channel.listenForWhisper('webrtc-offer', async (data) => {
            console.log(`[PeerManager] Received offer from: ${data.guestId}`);
            await this._handleOffer(data.guestId, data.sdp);
        });

        // Guest sends ICE candidate
        this._channel.listenForWhisper('ice-candidate', (data) => {
            if (data.targetId !== this._hostId) return; // Not for us

            const pc = this._connections.get(data.guestId);
            if (pc && data.candidate) {
                pc.addIceCandidate(new RTCIceCandidate(data.candidate))
                    .catch(err => console.warn(`[PeerManager] ICE candidate error:`, err));
            }
        });

        // Guest signals ready (camera on, waiting to be called)
        this._channel.listenForWhisper('guest-ready', (data) => {
            console.log(`[PeerManager] Guest ready: ${data.guestId} (${data.name})`);
            if (this._onGuestReadyCallback) {
                this._onGuestReadyCallback({ guestId: data.guestId, name: data.name });
            }
        });

        // Guest disconnecting
        this._channel.listenForWhisper('guest-disconnect', (data) => {
            console.log(`[PeerManager] Guest disconnecting: ${data.guestId}`);
            this.closeConnection(data.guestId);
            if (this._onGuestDisconnectCallback) {
                this._onGuestDisconnectCallback({ guestId: data.guestId });
            }
        });
    }

    /**
     * Handle an incoming WebRTC offer from a guest.
     * Creates a peer connection, generates answer, sends it back.
     */
    async _handleOffer(guestId, sdp) {
        // Close existing connection if any
        if (this._connections.has(guestId)) {
            this._connections.get(guestId).close();
        }

        const pc = new RTCPeerConnection({ iceServers: this._iceServers });
        this._connections.set(guestId, pc);

        // Send ICE candidates to guest
        pc.onicecandidate = (event) => {
            if (event.candidate && this._channel) {
                this._channel.whisper('ice-candidate', {
                    guestId: this._hostId, // Sender ID
                    targetId: guestId,      // Recipient
                    candidate: event.candidate.toJSON(),
                });
            }
        };

        // Log ICE connection state changes
        pc.oniceconnectionstatechange = () => {
            console.log(`[PeerManager] ICE state (${guestId}):`, pc.iceConnectionState);
            if (pc.iceConnectionState === 'disconnected' || pc.iceConnectionState === 'failed') {
                this.closeConnection(guestId);
                if (this._onGuestDisconnectCallback) {
                    this._onGuestDisconnectCallback({ guestId });
                }
            }
        };

        // Receive guest's video/audio tracks
        pc.ontrack = (event) => {
            console.log(`[PeerManager] Track received from ${guestId}:`, event.track.kind);
            if (event.streams && event.streams[0]) {
                if (this._onRemoteStreamCallback) {
                    this._onRemoteStreamCallback({
                        guestId,
                        stream: event.streams[0],
                    });
                }
            }
        };

        try {
            // Set the guest's offer
            await pc.setRemoteDescription({ type: 'offer', sdp });

            // Create and set answer
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);

            // Send answer back to guest
            this._channel.whisper('webrtc-answer', {
                guestId: guestId,
                sdp: answer.sdp,
            });

            console.log(`[PeerManager] Answer sent to: ${guestId}`);
        } catch (err) {
            console.error(`[PeerManager] Failed to handle offer from ${guestId}:`, err);
            this.closeConnection(guestId);
        }
    }
}
```

- [ ] **Step 2: Add lazy loader to app.js**

Add to `resources/js/app.js`:

```javascript
window.loadPeerManager = async () => {
    if (!window.StudioPeerManager) {
        const module = await import('./studio/PeerManager.js');
        window.StudioPeerManager = module.default;
    }
    return window.StudioPeerManager;
};
```

- [ ] **Step 3: Build to verify**

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 4: Commit**

```bash
git add resources/js/studio/PeerManager.js resources/js/app.js
git commit -m "feat(studio): add PeerManager — host-side WebRTC peer connections with Reverb signaling"
```

---

### Task 4: GuestPeerConnection — Guest-Side WebRTC

**Files:**
- Create: `resources/js/studio/GuestPeerConnection.js`

The guest's side of the WebRTC connection. Used by the guest room page.

- [ ] **Step 1: Create GuestPeerConnection.js**

```javascript
// resources/js/studio/GuestPeerConnection.js

/**
 * GuestPeerConnection — Guest-side WebRTC connection to the host.
 *
 * Sends the guest's camera/mic to the host via a direct peer connection.
 * Uses a Reverb Echo channel for signaling.
 *
 * Usage:
 *   const gpc = new GuestPeerConnection(echoChannel, guestId);
 *   await gpc.connect(localStream);
 *   // ... later
 *   gpc.disconnect();
 */

const ICE_SERVERS = [
    { urls: 'stun:stun.l.google.com:19302' },
    { urls: 'stun:stun1.l.google.com:19302' },
];

export default class GuestPeerConnection {
    /**
     * @param {Object} echoChannel - Echo presence channel instance
     * @param {string} guestId - Unique guest identifier for signaling
     * @param {Array} [iceServers]
     */
    constructor(echoChannel, guestId, iceServers = ICE_SERVERS) {
        this._channel = echoChannel;
        this._guestId = guestId;
        this._iceServers = iceServers;
        this._pc = null;
        this._connected = false;
        this._onConnectedCallback = null;
        this._onDisconnectedCallback = null;
    }

    /**
     * Called when the peer connection is established.
     * @param {Function} callback
     */
    onConnected(callback) {
        this._onConnectedCallback = callback;
    }

    /**
     * Called when the peer connection drops.
     * @param {Function} callback
     */
    onDisconnected(callback) {
        this._onDisconnectedCallback = callback;
    }

    /**
     * Connect to the host by creating an offer and sending it.
     *
     * @param {MediaStream} localStream - Guest's camera + mic stream
     */
    async connect(localStream) {
        this._pc = new RTCPeerConnection({ iceServers: this._iceServers });

        // Add local tracks (camera + mic)
        for (const track of localStream.getTracks()) {
            this._pc.addTrack(track, localStream);
        }

        // Send ICE candidates to host
        this._pc.onicecandidate = (event) => {
            if (event.candidate && this._channel) {
                this._channel.whisper('ice-candidate', {
                    guestId: this._guestId,
                    targetId: 'host',
                    candidate: event.candidate.toJSON(),
                });
            }
        };

        // Track connection state
        this._pc.oniceconnectionstatechange = () => {
            const state = this._pc.iceConnectionState;
            console.log(`[GuestPeer] ICE state: ${state}`);

            if (state === 'connected' || state === 'completed') {
                if (!this._connected) {
                    this._connected = true;
                    if (this._onConnectedCallback) this._onConnectedCallback();
                }
            }

            if (state === 'disconnected' || state === 'failed' || state === 'closed') {
                this._connected = false;
                if (this._onDisconnectedCallback) this._onDisconnectedCallback();
            }
        };

        // Listen for host's answer
        this._channel.listenForWhisper('webrtc-answer', async (data) => {
            if (data.guestId !== this._guestId) return; // Not for us

            console.log('[GuestPeer] Received answer from host');
            try {
                await this._pc.setRemoteDescription({ type: 'answer', sdp: data.sdp });
            } catch (err) {
                console.error('[GuestPeer] Failed to set remote description:', err);
            }
        });

        // Listen for host's ICE candidates
        this._channel.listenForWhisper('ice-candidate', (data) => {
            if (data.targetId !== this._guestId) return; // Not for us

            if (this._pc && data.candidate) {
                this._pc.addIceCandidate(new RTCIceCandidate(data.candidate))
                    .catch(err => console.warn('[GuestPeer] ICE candidate error:', err));
            }
        });

        try {
            // Create and send offer
            const offer = await this._pc.createOffer();
            await this._pc.setLocalDescription(offer);

            this._channel.whisper('webrtc-offer', {
                guestId: this._guestId,
                sdp: offer.sdp,
            });

            console.log('[GuestPeer] Offer sent to host');
        } catch (err) {
            console.error('[GuestPeer] Failed to create offer:', err);
            throw err;
        }
    }

    /**
     * Disconnect and clean up.
     */
    disconnect() {
        if (this._channel) {
            this._channel.whisper('guest-disconnect', {
                guestId: this._guestId,
            });
        }

        if (this._pc) {
            this._pc.close();
            this._pc = null;
        }

        this._connected = false;
        console.log('[GuestPeer] Disconnected');
    }

    /**
     * Whether the peer connection is currently established.
     * @returns {boolean}
     */
    get isConnected() {
        return this._connected;
    }
}
```

- [ ] **Step 2: Add lazy loader to app.js**

Add to `resources/js/app.js`:

```javascript
window.loadGuestPeerConnection = async () => {
    if (!window.StudioGuestPeerConnection) {
        const module = await import('./studio/GuestPeerConnection.js');
        window.StudioGuestPeerConnection = module.default;
    }
    return window.StudioGuestPeerConnection;
};
```

- [ ] **Step 3: Build to verify**

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 4: Commit**

```bash
git add resources/js/studio/GuestPeerConnection.js resources/js/app.js
git commit -m "feat(studio): add GuestPeerConnection — guest-side WebRTC peer connection"
```

---

### Task 5: Update EventGuestAuthController for Studio Channel

**Files:**
- Modify: `app/Http/Controllers/EventGuestAuthController.php`

The controller currently only handles `private-event-backstage.{eventId}` channels. It needs to also handle `presence-event-studio.{eventId}` channels for WebRTC signaling.

- [ ] **Step 1: Update the controller**

Replace the `authenticate` method in `app/Http/Controllers/EventGuestAuthController.php`:

```php
public function authenticate(Request $request): JsonResponse
{
    $channelName = $request->input('channel_name', '');
    $socketId = $request->input('socket_id', '');

    // Determine channel type and extract event ID
    $eventId = null;
    $isPresence = false;

    if (str_starts_with($channelName, 'private-event-backstage.')) {
        $eventId = str_replace('private-event-backstage.', '', $channelName);
    } elseif (str_starts_with($channelName, 'presence-event-studio.')) {
        $eventId = str_replace('presence-event-studio.', '', $channelName);
        $isPresence = true;
    } else {
        abort(403, 'This endpoint only handles event channels.');
    }

    $event = LiveEvent::find($eventId);
    if (! $event) {
        abort(404, 'Live event not found.');
    }

    // Check for authenticated internal user first
    $user = auth()->user();
    if ($user && $user->isInternal()) {
        return $isPresence
            ? $this->generatePresenceAuth($channelName, $socketId, "host-{$user->id}", $user->name)
            : $this->generateAuth($channelName, $socketId);
    }

    // Check for guest token
    $guestToken = $request->input('guest_token');
    if ($guestToken) {
        $guest = LiveEventGuest::where('token', $guestToken)
            ->where('live_event_id', $event->id)
            ->first();

        if ($guest) {
            return $isPresence
                ? $this->generatePresenceAuth($channelName, $socketId, $guest->getLivekitIdentity(), $guest->name)
                : $this->generateAuth($channelName, $socketId);
        }
    }

    abort(403, 'Unauthorized.');
}
```

Add the `generatePresenceAuth` method:

```php
private function generatePresenceAuth(string $channelName, string $socketId, string $userId, string $userName): JsonResponse
{
    $pusher = new Pusher(
        config('broadcasting.connections.reverb.key'),
        config('broadcasting.connections.reverb.secret'),
        config('broadcasting.connections.reverb.app_id'),
    );

    $auth = $pusher->presenceAuth($channelName, $socketId, $userId, [
        'name' => $userName,
    ]);

    return response()->json(json_decode($auth, true));
}
```

- [ ] **Step 2: Update LiveKitService guest token — canPublish: false**

In `app/Services/LiveKitService.php`, find the `generateGuestToken` method and change `canPublish` from `true` to `false`:

```php
$videoGrant = new VideoGrant([
    'roomJoin' => true,
    'room' => $event->livekit_room_name,
    'canPublish' => false,     // Changed: guests don't publish to LiveKit
    'canSubscribe' => true,    // Can still watch the broadcast output
]);
```

- [ ] **Step 3: Run existing tests to verify nothing breaks**

Run: `php artisan test --compact --filter="LiveEvent"`
Expected: All 62 tests pass.

- [ ] **Step 4: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/EventGuestAuthController.php app/Services/LiveKitService.php
git commit -m "feat(studio): support studio presence channel auth, guest tokens canPublish: false"
```

---

### Task 6: Rewrite Control Room — Dual Monitor + SourceManager + PeerManager

**Files:**
- Modify: `resources/views/pages/events/⚡manage.blade.php`

This is the largest task. The live state section of the control room gets a full rewrite: dual Preview/Program monitors, SourceManager for all video sources, PeerManager for guest WebRTC connections, TAKE workflow for scene transitions.

**Important context for the implementer:**
- This file is a Livewire SFC (single-file component) with PHP at the top and Blade template + Alpine.js below
- The pre-live state (draft/scheduled) and post-live state (ended) sections remain unchanged
- Only the `@elseif ($event->status === 'live')` section is rewritten
- The PHP class methods mostly stay the same — the big changes are in the Alpine.js `@script` block

- [ ] **Step 1: Update PHP class — remove frame mode, simplify scene switching**

In the PHP class section of `⚡manage.blade.php`, make these changes:

Remove the `toggleFrameMode()` method entirely.

Replace `switchScene()` with a simpler version that just persists the active scene (the Alpine `take()` action calls this):

```php
public function switchScene(int $index): void
{
    $scenes = $this->event->getScenesOrDefaults();

    if (! isset($scenes[$index])) {
        return;
    }

    $this->event->update(['active_scene_index' => $index]);
    $this->event->refresh();
}
```

The `bringGuestOn()`, `takeGuestOff()`, `refreshGuestStatuses()`, `dispatchGuestSlotMap()` methods all stay — they're still used for guest lifecycle management.

- [ ] **Step 2: Rewrite the live state template — dual monitors + sources + scenes + TAKE**

Replace the entire `@elseif ($event->status === 'live')` section's template (from the `<div x-data="livekitProducer"` to the closing `</div>` before `@script`) with:

```blade
@elseif ($event->status === 'live')
    @php
        $scenes = $event->getScenesOrDefaults();
        $activeSceneIndex = $event->active_scene_index ?? 0;
        $overlays = $event->overlays ?? [];
        $connectedGuests = $this->guests->whereIn('status', ['connected', 'live']);
    @endphp
    <div
        x-data="livekitProducer"
        x-init="$nextTick(() => {
            scenes = @js($scenes);
            liveSceneIndex = @js($activeSceneIndex);
            previewSceneIndex = @js($activeSceneIndex);
            overlayConfigs = @js($overlays);
            @if ($livekitToken)
                startStudio(@js($livekitToken), @js($livekitUrl), @js($event->id));
            @endif
        })"
        class="flex h-[calc(100vh-4rem)] flex-col lg:flex-row"
    >
        {{-- Left column: Monitors + Sources + Scenes + Controls --}}
        <div class="flex flex-1 flex-col overflow-hidden">
            {{-- Header bar --}}
            <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-900 px-4 py-2">
                <div class="flex items-center gap-3">
                    <span class="relative flex size-3">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-red-400 opacity-75"></span>
                        <span class="relative inline-flex size-3 rounded-full bg-red-500"></span>
                    </span>
                    <flux:heading size="sm" class="text-white">{{ $event->title }}</flux:heading>
                    <flux:badge color="red" size="sm">{{ __('LIVE') }}</flux:badge>
                </div>
                <div class="flex items-center gap-3">
                    <span class="flex items-center gap-1 text-sm text-zinc-400">
                        <flux:icon name="eye" variant="micro" />
                        <span x-text="viewerCount">{{ $viewerCount }}</span> {{ __('viewers') }}
                    </span>
                    @if ($event->started_at)
                        <span class="text-sm font-mono text-zinc-400"
                            x-data="{ elapsed: '' }"
                            x-init="
                                const started = new Date(@js($event->started_at->toIso8601String()));
                                setInterval(() => {
                                    const diff = Math.floor((Date.now() - started) / 1000);
                                    const h = Math.floor(diff / 3600);
                                    const m = Math.floor((diff % 3600) / 60);
                                    const s = diff % 60;
                                    elapsed = (h > 0 ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
                                }, 1000);
                            "
                            x-text="elapsed"
                        ></span>
                    @endif
                    <a href="{{ route('live.show', $event->slug) }}" target="_blank" class="text-xs text-blue-400 hover:text-blue-300 underline">{{ __('Public Page') }}</a>
                </div>
            </div>

            {{-- ═══════════════════════════════════════════ --}}
            {{-- DUAL MONITORS: PREVIEW + PROGRAM --}}
            {{-- ═══════════════════════════════════════════ --}}
            <div class="flex gap-2 bg-black p-2" style="max-height: 45vh;" wire:ignore>
                {{-- Preview Monitor --}}
                <div class="relative flex-1 overflow-hidden rounded-lg border-2 border-green-500/40 bg-black">
                    <canvas x-ref="previewCanvas" class="h-full w-full object-contain" width="1280" height="720"></canvas>
                    <span class="absolute top-2 left-2 rounded bg-green-600/90 px-2 py-0.5 text-[10px] font-bold text-white tracking-wider">PREVIEW</span>
                    <span class="absolute top-2 right-2 rounded bg-black/60 px-2 py-0.5 text-[10px] text-zinc-300" x-text="scenes[previewSceneIndex]?.name ?? 'Solo'"></span>
                </div>

                {{-- Program Monitor --}}
                <div class="relative flex-1 overflow-hidden rounded-lg border-2 border-red-500/40 bg-black">
                    <canvas x-ref="programCanvas" class="h-full w-full object-contain" width="1280" height="720"></canvas>
                    <span class="absolute top-2 left-2 rounded bg-red-600/90 px-2 py-0.5 text-[10px] font-bold text-white tracking-wider">PROGRAM</span>
                    <span class="absolute top-2 right-2 rounded bg-black/60 px-2 py-0.5 text-[10px] text-zinc-300" x-text="scenes[liveSceneIndex]?.name ?? 'Solo'"></span>
                </div>
            </div>

            {{-- Hidden container for SourceManager video elements --}}
            <div x-ref="sourceContainer" class="sr-only" aria-hidden="true" wire:ignore></div>

            {{-- ═══════════════════════════════════════════ --}}
            {{-- SOURCE MONITORS --}}
            {{-- ═══════════════════════════════════════════ --}}
            <div class="border-t border-zinc-700 bg-zinc-950 px-3 py-2 overflow-x-auto" wire:ignore>
                <div class="flex items-center gap-1 mb-1.5">
                    <span class="text-[9px] font-bold uppercase tracking-widest text-zinc-500">{{ __('Sources') }}</span>
                </div>
                <div class="flex items-start gap-2" x-ref="sourceMonitors">
                    <template x-for="source in sources" :key="source.key">
                        <div class="shrink-0 w-36">
                            <div class="relative overflow-hidden rounded border-2 bg-zinc-800 transition-colors"
                                 style="aspect-ratio: 16/9;"
                                 :class="source.state === 'live' ? 'border-green-500/60' : 'border-zinc-700'"
                                 :id="'source-thumb-' + source.key"
                                 x-init="$nextTick(() => renderSourceThumb(source.key))"
                            >
                                <div class="absolute inset-0 flex items-center justify-center">
                                    <span class="text-[10px] text-zinc-600" x-text="source.name"></span>
                                </div>
                            </div>
                            <div class="mt-1 flex items-center justify-between px-0.5">
                                <span class="text-[10px] font-semibold text-zinc-300" x-text="source.name"></span>
                                <span x-show="source.state === 'live'" x-cloak class="size-2 rounded-full bg-green-500"></span>
                            </div>
                        </div>
                    </template>

                    {{-- Empty state --}}
                    <div x-show="sources.length === 0" class="shrink-0 w-36">
                        <div class="relative overflow-hidden rounded border-2 border-dashed border-zinc-700 bg-zinc-900" style="aspect-ratio: 16/9;">
                            <div class="absolute inset-0 flex items-center justify-center">
                                <span class="text-[9px] text-zinc-600">{{ __('No sources') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══════════════════════════════════════════ --}}
            {{-- SCENE SWITCHER + TAKE --}}
            {{-- ═══════════════════════════════════════════ --}}
            <div class="flex items-center gap-1.5 border-t border-zinc-700 bg-zinc-900 px-3 py-2">
                <span class="mr-1 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ __('Scenes') }}</span>
                @foreach ($scenes as $index => $scene)
                    <button
                        type="button"
                        @click="previewScene({{ $index }})"
                        class="rounded px-3 py-1.5 text-xs font-medium transition-colors"
                        :class="{
                            'ring-2 ring-red-500 bg-red-600/20 text-red-300': liveSceneIndex === {{ $index }},
                            'ring-2 ring-green-500 bg-green-600/20 text-green-300': previewSceneIndex === {{ $index }} && liveSceneIndex !== {{ $index }},
                            'bg-zinc-700 text-zinc-300 hover:bg-zinc-600': previewSceneIndex !== {{ $index }} && liveSceneIndex !== {{ $index }},
                        }"
                    >
                        {{ $scene['name'] }}
                    </button>
                @endforeach

                <div class="mx-2 h-5 w-px bg-zinc-700"></div>

                {{-- TAKE button --}}
                <button
                    type="button"
                    @click="take()"
                    class="rounded bg-red-600 px-6 py-1.5 text-xs font-bold uppercase tracking-wider text-white transition-colors hover:bg-red-500 disabled:opacity-40"
                    :disabled="previewSceneIndex === liveSceneIndex"
                >
                    {{ __('TAKE') }}
                </button>
            </div>

            {{-- Guest Controls --}}
            @if ($this->guests->isNotEmpty())
                <div class="flex items-center gap-2 overflow-x-auto border-t border-zinc-700 bg-zinc-800 px-3 py-2">
                    <span class="mr-1 shrink-0 text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ __('Guests') }}</span>
                    @foreach ($this->guests as $guest)
                        <div wire:key="live-guest-{{ $guest->id }}" class="flex shrink-0 items-center gap-1.5 rounded-lg bg-zinc-900 px-2.5 py-1.5">
                            <span class="text-xs font-medium text-zinc-300">{{ $guest->name }}</span>
                            <flux:badge size="sm" :color="match($guest->status) {
                                'connected' => 'amber',
                                'live' => 'green',
                                'disconnected' => 'zinc',
                                default => 'zinc',
                            }">
                                {{ ucfirst($guest->status) }}
                            </flux:badge>
                            @if ($guest->isConnected())
                                <flux:button size="xs" variant="primary" wire:click="bringGuestOn({{ $guest->id }})">
                                    {{ __('Bring On') }}
                                </flux:button>
                            @elseif ($guest->isLive())
                                <flux:button size="xs" variant="ghost" wire:click="takeGuestOff({{ $guest->id }})">
                                    {{ __('Take Off') }}
                                </flux:button>
                            @endif
                        </div>
                    @endforeach
                    <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="refreshGuestStatuses" class="shrink-0" title="{{ __('Refresh') }}" />
                </div>
            @endif

            {{-- Controls bar --}}
            <div class="flex items-center justify-center gap-3 border-t border-zinc-700 bg-zinc-900 px-4 py-3">
                {{-- Mic toggle --}}
                <button type="button" @click="toggleAudio()"
                    class="flex size-11 items-center justify-center rounded-full transition-colors"
                    :class="audioEnabled ? 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600' : 'bg-red-500 text-white hover:bg-red-600'"
                >
                    <flux:icon name="microphone" class="size-5" />
                </button>

                {{-- Camera toggle --}}
                <button type="button" @click="toggleVideo()"
                    class="flex size-11 items-center justify-center rounded-full transition-colors"
                    :class="videoEnabled ? 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600' : 'bg-red-500 text-white hover:bg-red-600'"
                >
                    <flux:icon name="video-camera" class="size-5" />
                </button>

                {{-- Screen share --}}
                <button type="button" @click="toggleScreenShare()"
                    class="flex size-11 items-center justify-center rounded-full transition-colors"
                    :class="screenStream ? 'bg-purple-600 text-white' : 'bg-zinc-700 text-zinc-300 hover:bg-zinc-600'"
                >
                    <flux:icon name="computer-desktop" class="size-5" />
                </button>

                <div class="mx-2 h-6 w-px bg-zinc-700"></div>

                {{-- End Stream --}}
                <flux:button wire:click="endStream"
                    wire:confirm="{{ __('Are you sure you want to end the stream?') }}"
                    variant="danger" size="sm" icon="x-mark">
                    {{ __('End Stream') }}
                </flux:button>
            </div>
        </div>

        {{-- Right column: Tabbed sidebar (Chat, Q&A, Resources, Backstage) --}}
        {{-- This entire sidebar section is UNCHANGED from the current implementation --}}
        {{-- Copy the existing right column sidebar verbatim --}}
    </div>
```

- [ ] **Step 3: Rewrite the Alpine.js `@script` block**

Replace the entire `@script` block (the `livekitProducer` Alpine data) with the new implementation using SourceManager, PeerManager, and dual compositors:

```javascript
@script
<script>
Alpine.data('livekitProducer', () => {
    let room = null;
    let sourceManager = null;
    let previewCompositor = null;
    let programCompositor = null;
    let peerManager = null;
    let studioChannel = null;

    return {
        connected: false,
        audioEnabled: true,
        videoEnabled: true,
        viewerCount: 0,
        connectionError: null,
        sources: [],            // Reactive array from SourceManager events
        previewSceneIndex: 0,
        liveSceneIndex: 0,
        scenes: [],
        overlayConfigs: [],
        screenStream: null,

        init() {
            this.$wire.on('livekit-disconnect', () => this.disconnect());

            // Guest bring-on: Livewire dispatches, we use PeerManager for video
            this.$wire.on('guest-bring-on', ({ identity, name, guestId }) => {
                // Guest video arrives via WebRTC (PeerManager handles it)
                // The bring-on signal just updates backend status
                console.log('[Studio] Guest brought on:', name);
            });

            this.$wire.on('guest-take-off', ({ identity, guestId }) => {
                // Remove guest source when taken off air
                const slotKey = this._guestSlotKey(guestId);
                if (sourceManager) sourceManager.removeSource(slotKey);
                console.log('[Studio] Guest taken off:', slotKey);
            });
        },

        // ==============================================================
        // Studio startup
        // ==============================================================

        async startStudio(livekitToken, livekitUrl, eventId) {
            try {
                this.connectionError = null;

                // 1. Initialize SourceManager
                const SourceManager = await window.loadSourceManager();
                sourceManager = new SourceManager(this.$refs.sourceContainer);

                // Update reactive sources array on changes
                const syncSources = () => {
                    this.sources = sourceManager.getAllSources();
                };
                sourceManager.on('source-added', syncSources);
                sourceManager.on('source-ready', syncSources);
                sourceManager.on('source-removed', syncSources);
                sourceManager.on('source-lost', syncSources);

                // 2. Get local camera and register as 'host' source
                const cameraStream = await navigator.mediaDevices.getUserMedia({
                    video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' },
                    audio: { echoCancellation: true, noiseSuppression: true },
                });
                sourceManager.addSource('host', cameraStream, 'You (Host)');

                // 3. Initialize dual compositors
                const FrameCompositor = await window.loadFrameCompositor();

                previewCompositor = new FrameCompositor(this.$refs.previewCanvas, sourceManager);
                programCompositor = new FrameCompositor(this.$refs.programCanvas, sourceManager);

                const scene = this.scenes[this.liveSceneIndex] ?? this.scenes[0];
                previewCompositor.setScene(scene);
                programCompositor.setScene(scene);

                if (this.overlayConfigs.length > 0) {
                    programCompositor.setOverlays(this.overlayConfigs);
                    previewCompositor.setOverlays(this.overlayConfigs);
                }

                previewCompositor.start();
                programCompositor.start();
                console.log('[Studio] Compositors started');

                // 4. Connect to LiveKit (broadcast output only)
                const { Room, RoomEvent } = await window.loadLiveKit();
                room = new Room({ adaptiveStream: true, dynacast: true });

                room.on(RoomEvent.ParticipantConnected, () => this._updateViewerCount());
                room.on(RoomEvent.ParticipantDisconnected, () => this._updateViewerCount());
                room.on(RoomEvent.Disconnected, () => { this.connected = false; });

                await room.connect(livekitUrl, livekitToken);

                // 5. Publish PROGRAM canvas to LiveKit (ONE TIME)
                const canvasStream = programCompositor.getOutputStream();
                const canvasTrack = canvasStream.getVideoTracks()[0];
                await room.localParticipant.publishTrack(canvasTrack, {
                    name: 'program-output',
                    simulcast: false,
                });
                console.log('[Broadcast] Program canvas published to LiveKit');

                // 6. Publish host audio directly to LiveKit
                const audioTrack = cameraStream.getAudioTracks()[0];
                if (audioTrack) {
                    await room.localParticipant.publishTrack(audioTrack, {
                        name: 'host-mic',
                    });
                    console.log('[Broadcast] Host audio published to LiveKit');
                }

                // 7. Set up PeerManager for guest WebRTC connections
                await this._setupPeerManager(eventId);

                this.connected = true;
                this._updateViewerCount();
                console.log('[Studio] Studio fully initialized');

            } catch (err) {
                console.error('[Studio] Startup error:', err);
                this.connectionError = 'Failed to start studio: ' + err.message;
            }
        },

        // ==============================================================
        // PeerManager setup (guest WebRTC connections)
        // ==============================================================

        async _setupPeerManager(eventId) {
            const PeerManager = await window.loadPeerManager();
            const EchoLib = window.EchoLib;
            if (!EchoLib) {
                console.warn('[Studio] Echo not available, guest connections disabled');
                return;
            }

            const echoInstance = new EchoLib({
                broadcaster: 'reverb',
                key: document.querySelector('meta[name="reverb-key"]')?.content,
                wsHost: document.querySelector('meta[name="reverb-host"]')?.content,
                wsPort: document.querySelector('meta[name="reverb-port"]')?.content || 443,
                wssPort: document.querySelector('meta[name="reverb-port"]')?.content || 443,
                forceTLS: (document.querySelector('meta[name="reverb-scheme"]')?.content || 'https') === 'https',
                enabledTransports: ['ws', 'wss'],
                authEndpoint: '/broadcasting/event-guest-auth',
                auth: {
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                },
            });

            studioChannel = echoInstance.join(`event-studio.${eventId}`);
            peerManager = new PeerManager(studioChannel, 'host');

            // When guest video arrives via WebRTC peer connection
            peerManager.onRemoteStream(({ guestId, stream }) => {
                const slotKey = this._guestSlotKey(guestId);
                const guest = this._findGuest(guestId);
                const name = guest?.name ?? guestId;

                sourceManager.addSource(slotKey, stream, name);
                console.log(`[Studio] Guest video registered: ${slotKey} (${name})`);

                // Also publish guest audio to LiveKit for viewers
                const audioTrack = stream.getAudioTracks()[0];
                if (audioTrack && room) {
                    room.localParticipant.publishTrack(audioTrack, {
                        name: `${slotKey}-audio`,
                    }).then(() => {
                        console.log(`[Broadcast] Guest audio published: ${slotKey}`);
                    }).catch(err => {
                        console.warn(`[Broadcast] Failed to publish guest audio:`, err);
                    });
                }
            });

            peerManager.onGuestReady(({ guestId, name }) => {
                console.log(`[Studio] Guest ready in green room: ${name}`);
                this.$wire.refreshGuestStatuses();
            });

            peerManager.onGuestDisconnect(({ guestId }) => {
                const slotKey = this._guestSlotKey(guestId);
                if (sourceManager) sourceManager.removeSource(slotKey);
                this.$wire.refreshGuestStatuses();
            });
        },

        // ==============================================================
        // Preview / Program / TAKE
        // ==============================================================

        previewScene(index) {
            this.previewSceneIndex = index;
            if (previewCompositor) {
                previewCompositor.setScene(this.scenes[index]);
            }
        },

        take() {
            this.liveSceneIndex = this.previewSceneIndex;
            if (programCompositor) {
                programCompositor.setScene(this.scenes[this.liveSceneIndex]);
            }
            this.$wire.switchScene(this.liveSceneIndex);
            console.log('[Studio] TAKE:', this.scenes[this.liveSceneIndex]?.name);
        },

        // ==============================================================
        // Controls
        // ==============================================================

        async toggleAudio() {
            this.audioEnabled = !this.audioEnabled;
            if (room) {
                await room.localParticipant.setMicrophoneEnabled(this.audioEnabled);
            }
        },

        async toggleVideo() {
            this.videoEnabled = !this.videoEnabled;
            // Toggle host source visibility — don't kill the track
            const hostEl = sourceManager?.getElement('host');
            if (hostEl) {
                const tracks = hostEl.srcObject?.getVideoTracks();
                if (tracks?.[0]) tracks[0].enabled = this.videoEnabled;
            }
        },

        async toggleScreenShare() {
            if (this.screenStream) {
                this.screenStream.getTracks().forEach(t => t.stop());
                this.screenStream = null;
                if (sourceManager) sourceManager.removeSource('screen');
                return;
            }

            try {
                this.screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
                if (sourceManager) {
                    sourceManager.addSource('screen', this.screenStream, 'Screen Share');
                }

                this.screenStream.getVideoTracks()[0].addEventListener('ended', () => {
                    this.screenStream = null;
                    if (sourceManager) sourceManager.removeSource('screen');
                });
            } catch (err) {
                console.warn('[Studio] Screen share cancelled:', err.message);
            }
        },

        // ==============================================================
        // Source monitor thumbnails
        // ==============================================================

        renderSourceThumb(key) {
            const container = document.getElementById('source-thumb-' + key);
            const el = sourceManager?.getElement(key);
            if (!container || !el?.srcObject) return;

            const existing = container.querySelector('video');
            if (existing) existing.remove();

            const thumbEl = document.createElement('video');
            thumbEl.srcObject = el.srcObject;
            thumbEl.autoplay = true;
            thumbEl.muted = true;
            thumbEl.playsInline = true;
            thumbEl.style.cssText = 'width:100%;height:100%;object-fit:cover;position:absolute;inset:0;';
            container.appendChild(thumbEl);
            thumbEl.play().catch(() => {});
        },

        // ==============================================================
        // Helpers
        // ==============================================================

        _guestSlotKey(guestId) {
            // Map guest identity to compositor slot key ('guest-1', 'guest-2', etc.)
            // Guest slot numbers are assigned by the backend (LiveEventGuest.slot_number)
            // The PeerManager receives guestId which is the livekit_identity string
            // We need to map that to the slot number for the compositor scene slots
            if (!this._guestSlotMap) this._guestSlotMap = {};
            if (this._guestSlotMap[guestId]) return this._guestSlotMap[guestId];

            // Assign next available slot number
            const usedSlots = Object.values(this._guestSlotMap);
            let slotNum = 1;
            while (usedSlots.includes('guest-' + slotNum)) slotNum++;
            this._guestSlotMap[guestId] = 'guest-' + slotNum;
            return 'guest-' + slotNum;
        },

        _updateViewerCount() {
            if (room) {
                this.viewerCount = room.remoteParticipants.size;
                this.$wire.set('viewerCount', this.viewerCount);
            }
        },

        // ==============================================================
        // Cleanup
        // ==============================================================

        async disconnect() {
            if (peerManager) { peerManager.destroy(); peerManager = null; }
            if (previewCompositor) { previewCompositor.stop(); previewCompositor.destroy(); previewCompositor = null; }
            if (programCompositor) { programCompositor.stop(); programCompositor.destroy(); programCompositor = null; }
            if (sourceManager) { sourceManager.destroy(); sourceManager = null; }
            if (this.screenStream) { this.screenStream.getTracks().forEach(t => t.stop()); this.screenStream = null; }
            if (room) { await room.disconnect(); room = null; }
            this.connected = false;
        },

        destroy() { this.disconnect(); }
    };
});
</script>
@endscript
```

- [ ] **Step 4: Build and verify**

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 5: Run existing tests**

Run: `php artisan test --compact --filter="LiveEvent"`
Expected: Tests pass (PHP methods are mostly unchanged).

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add "resources/views/pages/events/⚡manage.blade.php"
git commit -m "feat(studio): rewrite control room — dual Preview/Program monitors, SourceManager, PeerManager"
```

---

### Task 7: Rewrite Guest Room — WebRTC Peer Connection to Host

**Files:**
- Modify: `resources/views/pages/events/⚡guest-room.blade.php`

Replace LiveKit publishing with a WebRTC peer connection to the host. The guest room UI stays similar — camera preview, device selector, go live button, backstage chat. Only the transport layer changes.

- [ ] **Step 1: Update the Livewire mount — remove LiveKit token generation for publishing**

In the PHP class section of `⚡guest-room.blade.php`, the `mount()` method should still generate a LiveKit token (for viewing the broadcast) but with `canPublish: false` (already changed in Task 5). No other PHP changes needed.

- [ ] **Step 2: Rewrite the Alpine.js component**

Replace the `livekitGuest` Alpine data function. The key change: instead of connecting to LiveKit to publish, the guest creates a `GuestPeerConnection` to the host via Reverb. The guest still connects to LiveKit as a viewer (subscribe-only) to see the broadcast output.

The full Alpine component replacement is too large to include inline but follows this structure:

```javascript
Alpine.data('livekitGuest', () => {
    let localStream = null;
    let peerConnection = null;  // GuestPeerConnection instance
    let viewerRoom = null;      // LiveKit room (subscribe-only, for seeing broadcast)
    let studioChannel = null;   // Echo presence channel for WebRTC signaling
    let audioContext = null;
    let analyser = null;
    let animationFrame = null;
    let echoInstance = null;

    return {
        guestStatus: 'waiting',   // 'waiting' | 'live' | 'finished'
        cameraEnabled: true,
        micEnabled: true,
        audioLevel: 0,
        cameras: [],
        microphones: [],
        hostTrackAttached: false,
        peerConnected: false,     // WebRTC connection to host established

        async init() {
            await this.startLocalPreview();
            this.setupStudioChannel();   // Join Reverb channel for signaling
            this.setupBackstageChat();   // Unchanged
            if (config.livekitToken && config.livekitUrl) {
                this.connectAsViewer();  // Subscribe-only LiveKit connection
            }
        },

        async goLive() {
            // Load GuestPeerConnection and connect to host
            const GuestPeerConnection = await window.loadGuestPeerConnection();
            peerConnection = new GuestPeerConnection(studioChannel, config.guestId);

            peerConnection.onConnected(() => {
                this.peerConnected = true;
                this.guestStatus = 'live';
                console.log('[GuestRoom] Connected to host via WebRTC');
            });

            peerConnection.onDisconnected(() => {
                this.peerConnected = false;
                this.guestStatus = 'waiting';
            });

            await peerConnection.connect(localStream);

            // Signal ready to host
            studioChannel.whisper('guest-ready', {
                guestId: config.guestId,
                name: config.guestName,
            });
        },

        // ... rest of methods (startLocalPreview, setupAudioMeter, etc. — mostly unchanged)
    };
});
```

- [ ] **Step 3: Build and verify**

Run: `npm run build`
Expected: Build succeeds.

- [ ] **Step 4: Commit**

```bash
git add "resources/views/pages/events/⚡guest-room.blade.php"
git commit -m "feat(studio): rewrite guest room — WebRTC peer connection to host instead of LiveKit publish"
```

---

### Task 8: Integration Testing and Polish

**Files:**
- Modify: Various test files and minor fixes

- [ ] **Step 1: Run the full LiveEvent test suite**

Run: `php artisan test --compact --filter="LiveEvent"`
Expected: All tests pass. Fix any that broke due to removed methods.

- [ ] **Step 2: Fix any broken tests**

If `toggleFrameMode` was tested, remove those test cases. If `switchScene` behavior changed, update assertions.

- [ ] **Step 3: Build frontend**

Run: `npm run build`
Expected: Clean build with all studio chunks present.

- [ ] **Step 4: Run Pint on all modified PHP files**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 5: Final commit**

```bash
git add -A
git commit -m "test(studio): fix tests for live studio redesign, final polish"
```

- [ ] **Step 6: Push to main**

```bash
git push origin main
```
