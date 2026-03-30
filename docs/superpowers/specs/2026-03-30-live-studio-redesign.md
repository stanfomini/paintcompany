# Live Production Studio Redesign

## Problem

The live studio's guest video compositing doesn't work. Root causes:

1. **Guest video elements never play** — stored in JS closures but never added to DOM, so `readyState` stays 0 and the compositor draws placeholder text ("guest-1") instead of video
2. **Frame mode toggle creates fragile track swaps** — switching between raw camera and canvas output causes subscription gaps for viewers
3. **Guest connections duplicate existing infrastructure** — the guest room reinvents camera/device management instead of reusing the proven WebRTC video-call system
4. **Quality suffers from unnecessary SFU hops** — guest video routes through LiveKit SFU before reaching the host, adding encoding overhead

## Architecture: OBS-Style Input/Compositor/Output

Three clean layers with clear boundaries:

```
INPUTS                          PROCESSING              OUTPUT
─────────────────────          ─────────────           ──────────────
Host camera ──────────┐
                      │
Guest 1 ──WebRTC peer─┤        SourceManager           LiveKit SFU
                      ├──────→ (DOM elements) ──→      (broadcast only)
Guest 2 ──WebRTC peer─┤        FrameCompositor         ├→ Viewer 1
                      │        (canvas 1280×720)        ├→ Viewer 2
Screen share ─────────┘        captureStream(30)        └→ Viewer N
```

**Key principle:** LiveKit is ONLY used for one-way broadcast to viewers. Guest video arrives via direct WebRTC peer connections for maximum quality. The compositor is always running — no frame mode toggle.

---

## Layer 1: SourceManager

**File:** `resources/js/studio/SourceManager.js`

A registry of all video sources. Each source is a `<video>` element that is always in the DOM and always playing.

### API

```javascript
class SourceManager {
    constructor(containerEl)           // Hidden DOM container for video elements

    addSource(key, mediaStream)        // Register a source ('host', 'guest-1', 'screen')
    removeSource(key)                  // Deregister and clean up
    getElement(key)                    // Get the <video> element (for compositor)
    getState(key)                      // 'connecting' | 'live' | 'dead'
    getAllSources()                    // Map<key, {element, state, name}>
    setName(key, name)                // Human-readable name for UI

    // Events (via EventTarget or callback)
    on('source-added', ({key, name}))
    on('source-ready', ({key}))        // readyState >= 2, compositor can draw
    on('source-removed', ({key}))
}
```

### Behavior

When `addSource(key, mediaStream)` is called:
1. Create `<video>` element
2. Set `srcObject = mediaStream`, `autoplay`, `muted`, `playsInline`
3. Append to hidden DOM container (ensures browser will play it)
4. Call `.play()` explicitly
5. Listen for `loadeddata` event → emit `source-ready`
6. Store in internal `Map<key, {element, stream, state, name}>`

When `removeSource(key)` is called:
1. Pause video, set `srcObject = null`
2. Remove from DOM container
3. Remove from internal map
4. Emit `source-removed`

### Hidden DOM Container

In the manage.blade.php template:
```html
<div x-ref="sourceContainer" class="sr-only" aria-hidden="true" wire:ignore>
    <!-- SourceManager inserts <video> elements here -->
    <!-- sr-only = visually hidden but in DOM, so browsers play them -->
</div>
```

### Why This Matters

The entire reason the current system fails is that video elements aren't in the DOM. SourceManager guarantees every source element is:
- In the document (so the browser plays it)
- Has `.play()` called (so `readyState` advances)
- Tracked with state (so the compositor knows what's available)

---

## Layer 2: PeerManager — Direct WebRTC Guest Connections

**File:** `resources/js/studio/PeerManager.js`

Manages WebRTC peer connections between host and guests. Uses Reverb (Echo) for signaling, same pattern as the existing video-call system.

### Signaling Channel

Both host and guests join a Reverb presence channel:
```
private channel: event-studio.{eventId}
```

Messages exchanged via `.whisper()`:
- `webrtc-offer` — guest sends SDP offer to host
- `webrtc-answer` — host sends SDP answer to guest
- `ice-candidate` — both sides exchange ICE candidates
- `guest-ready` — guest has camera ready, waiting to be called on
- `guest-disconnect` — guest is leaving

### Host Side

```javascript
class PeerManager {
    constructor(echoChannel, iceServers)

    // Called when a guest sends an offer
    async handleOffer(guestId, offer)   // Creates RTCPeerConnection, returns answer

    // Event: remote stream received from a guest
    onRemoteStream(callback)            // callback({guestId, stream})

    // Tear down
    closeConnection(guestId)
    closeAll()
}
```

When the host receives a `webrtc-offer` whisper:
1. Create `RTCPeerConnection` for this guest (with ICE servers)
2. Set remote description (the offer)
3. Create answer, set local description
4. Send `webrtc-answer` whisper back
5. Exchange ICE candidates via whispers
6. When `ontrack` fires → call `onRemoteStream` callback with `{guestId, stream}`
7. The Alpine component receives this and calls `sourceManager.addSource('guest-1', stream)`

### Guest Side

```javascript
class GuestPeerConnection {
    constructor(echoChannel, guestId, iceServers)

    async connect(localStream)          // Create offer, send to host
    disconnect()                        // Clean teardown

    onHostStream(callback)              // Optional: receive composed output back
}
```

When the guest clicks "Go Live":
1. Create `RTCPeerConnection`
2. Add local camera/mic tracks
3. Create offer → send via `webrtc-offer` whisper
4. Receive answer → set remote description
5. Exchange ICE candidates
6. Connection established, guest video flowing to host

### ICE Configuration

Reuse the same STUN/TURN servers configured for the existing video-call system. These are typically:
```javascript
iceServers: [
    { urls: 'stun:stun.l.google.com:19302' },
    // TURN servers if configured in .env
]
```

### What Happens to the Guest Room

The guest room (`⚡guest-room.blade.php`) changes its transport layer:
- **Before:** Connects to LiveKit room, publishes camera track via LiveKit
- **After:** Creates a direct WebRTC peer connection to the host via Reverb signaling

The UI stays mostly the same:
- Camera preview (local getUserMedia — unchanged)
- Device selector (unchanged)
- "Go Live" button → now initiates peer connection instead of LiveKit publish
- Backstage chat → still via Reverb (unchanged)
- Stream preview → guest joins LiveKit as viewer (subscribe-only) to see broadcast output

### What Happens to LiveKit Guest Tokens

Guest tokens change from `canPublish: true` to `canPublish: false`:
```php
// LiveKitService::generateGuestToken()
$videoGrant = new VideoGrant([
    'roomJoin' => true,
    'room' => $event->livekit_room_name,
    'canPublish' => false,     // Changed: guests don't publish to LiveKit
    'canSubscribe' => true,    // Can still watch the broadcast
]);
```

Guests connect to LiveKit ONLY to see the broadcast output (like a viewer). Their camera video goes directly to the host via WebRTC peer connection.

---

## Layer 3: FrameCompositor — Always-On Compositing

**File:** `resources/js/studio/FrameCompositor.js` (mostly unchanged)

### Changes

1. Gets video elements from SourceManager instead of direct references:
```javascript
_drawSlot(ctx, slot, canvasW, canvasH) {
    const videoEl = this._sourceManager.getElement(slot.source);
    // rest unchanged
}
```

2. Constructor takes SourceManager reference:
```javascript
constructor(canvas, sourceManager, options = {})
```

3. Listens for `source-ready` / `source-removed` events to know when to redraw vs placeholder.

Everything else stays the same: scene management, overlay drawing, `captureStream()`, render loop.

### Always-On Behavior

When the event goes live:
1. FrameCompositor starts immediately
2. Default scene is "Solo" (host fills full canvas)
3. Canvas captureStream is published to LiveKit
4. This never changes — no toggle, no track swap

When switching scenes:
- `compositor.setScene(newScene)` — changes layout
- Canvas output updates instantly — viewers see it on the next frame
- No track unpublish/republish needed

When a guest connects:
- SourceManager registers their stream → emits `source-ready`
- Compositor's next `_drawSlot()` call finds the element via SourceManager
- If the active scene has a `guest-1` slot, the guest appears

When a guest disconnects:
- SourceManager removes the source → emits `source-removed`
- Compositor draws placeholder for that slot until someone else fills it

---

## Layer 4: Broadcast — LiveKit Output Only

### Going Live Flow

```
1. Host clicks "Go Live"
2. Livewire creates LiveKit room (unchanged)
3. Alpine:
   a. Get local camera MediaStream
   b. sourceManager.addSource('host', cameraStream)
   c. compositor = new FrameCompositor(canvas, sourceManager)
   d. compositor.setScene(soloScene)
   e. compositor.start()
   f. canvasStream = compositor.getOutputStream()
   g. canvasTrack = canvasStream.getVideoTracks()[0]
   h. Connect to LiveKit room
   i. Publish canvasTrack to LiveKit (ONE TIME)
   j. Publish audio track to LiveKit (host mic, direct — not through compositor)
4. Viewers subscribe and see the canvas output
```

### Audio Handling

Audio is NOT composited through the canvas. Audio tracks are published directly:
- Host mic → published to LiveKit directly
- Guest audio → arrives via WebRTC peer connection → published to LiveKit as additional audio tracks
- Viewers receive all audio tracks and the browser mixes them automatically

This is important: canvas `captureStream()` doesn't carry audio. Audio must be handled separately.

### Host Program Monitor

The host sees the canvas directly in the control room (same as today):
```html
<canvas x-ref="compositorCanvas" class="h-full w-full" width="1280" height="720"></canvas>
```

No `x-show` toggle needed — the canvas is always visible when live.

### Source Monitor Thumbnails

The source monitor strip below the program monitor shows each source:
```html
<template x-for="source in sources" :key="source.key">
    <div class="w-40">
        <video :srcObject="sourceManager.getElement(source.key)?.srcObject"
               autoplay muted playsinline class="w-full aspect-video object-cover" />
        <span x-text="source.name" class="text-xs text-zinc-300"></span>
    </div>
</template>
```

Sources come from SourceManager events, so the strip updates automatically when guests connect/disconnect.

---

## File Changes Summary

### New Files
| File | Purpose |
|------|---------|
| `resources/js/studio/SourceManager.js` | Video source registry with DOM management |
| `resources/js/studio/PeerManager.js` | WebRTC peer connection management (host side) |
| `resources/js/studio/GuestPeerConnection.js` | WebRTC peer connection (guest side) |

### Modified Files
| File | Changes |
|------|---------|
| `resources/js/studio/FrameCompositor.js` | Accept SourceManager, use `getElement()` instead of direct refs |
| `resources/views/pages/events/⚡manage.blade.php` | Replace LiveKit guest handling with PeerManager + SourceManager, remove frame mode toggle, always-on compositor |
| `resources/views/pages/events/⚡guest-room.blade.php` | Replace LiveKit publish with WebRTC peer connection to host |
| `resources/js/app.js` | Viewer track filtering stays (already done) |
| `app/Services/LiveKitService.php` | Guest tokens: `canPublish: false` |

### Removed Concepts
- Frame mode toggle (`use_frame_mode` column, `toggleFrameMode()` method)
- Guest track subscription via LiveKit on host side
- `guestElements` / `guestMediaStreams` closure variables
- `publishCanvasStream()` / `publishRawCamera()` swap logic

---

## Verification Plan

### Manual Testing
1. **Host solo:** Go live → verify canvas shows host camera → viewer sees it
2. **Guest connect:** Guest opens room → clicks Go Live → verify video appears in source monitor AND compositor
3. **Scene switch:** Switch Solo → Interview → verify host + guest appear side by side → viewer sees composed output
4. **Guest disconnect:** Guest closes browser → verify source removed cleanly → compositor shows placeholder → switch back to Solo
5. **Multiple guests:** Two guests connected → switch to custom scene → verify both render
6. **Quality check:** Compare guest video quality between source monitor (direct) and viewer output (through canvas)

### Console Logging
Every layer logs with prefixes:
- `[SourceManager]` — source add/remove/ready/state changes
- `[PeerManager]` — peer connection lifecycle, ICE state, signaling
- `[Compositor]` — scene changes, frame counts, source availability
- `[Broadcast]` — track publish/unpublish to LiveKit

### Automated Tests
- Update existing LiveEvent tests for changed Livewire methods
- Add PeerManager unit tests (signaling protocol)
- Add SourceManager unit tests (element lifecycle)
