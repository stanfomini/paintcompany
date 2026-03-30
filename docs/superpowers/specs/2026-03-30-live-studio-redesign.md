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

## Layer 4: Preview/Program Dual Monitor

The control room uses an OBS-style Preview/Program workflow. Two monitors side by side, each rendering a scene with actual video feeds. The host can see exactly what a scene will look like before making it live.

### Two Compositors, One SourceManager

```
                          SourceManager (shared)
                         ┌──────────────────────┐
                         │  host: <video>        │
                         │  guest-1: <video>     │
                         │  screen: <video>      │
                         └───────┬──────┬────────┘
                                 │      │
                    ┌────────────┘      └────────────┐
                    ▼                                ▼
          Preview Compositor               Program Compositor
          (preview canvas)                 (program canvas)
          renders previewScene             renders liveScene
          GREEN border                     RED border
          NOT published                    → captureStream → LiveKit → Viewers
```

Both compositors are instances of FrameCompositor. Both read from the same SourceManager. A single `<video>` element can be drawn by multiple canvases simultaneously — `drawImage()` just reads the current frame.

### Layout

```
┌─────────────────────────────────────────────────────────┐
│  ┌──── PREVIEW ─────────┐  ┌──── PROGRAM (LIVE) ──────┐│
│  │ green border          │  │ red border                ││
│  │                       │  │                           ││
│  │  [Interview scene     │  │  [Solo scene              ││
│  │   with actual feeds]  │  │   currently broadcasting] ││
│  │                       │  │                           ││
│  └───────────────────────┘  └───────────────────────────┘│
│                                                          │
│  Sources: [🟢 Host] [🟢 Guest 1] [⬜ Screen]            │
│                                                          │
│  Scenes: [Solo] [Interview] [Spotlight] [PiP] [Screen]  │
│                                                          │
│  [━━━━━━━━━━ TAKE ━━━━━━━━━━]   ← transitions preview  │
│                                     to program           │
│  Controls: [🎤] [📷] [🖥️] [⚙️]  [End Stream]           │
└─────────────────────────────────────────────────────────┘
```

### Workflow

1. **Click a scene button** → loads it into the **Preview** monitor (green border)
   - Preview compositor renders the scene with current sources
   - Program monitor stays unchanged — viewers see no change
   - Host can inspect: are the feeds in the right positions? Does it look good?

2. **Click TAKE** → transitions the preview scene to **Program** (live)
   - Program compositor switches to the preview's scene
   - Viewers see the change immediately (continuous canvas stream)
   - Preview stays showing the same scene (now both match)
   - Host can then click a different scene to preview the next transition

3. **Scene buttons highlight state:**
   - Red outline = currently live (in Program)
   - Green outline = currently previewed (in Preview)
   - No outline = available to preview

### Pre-Live Preview

Before clicking "Go Live", the host gets a setup screen where:
- Camera is active, feeds to SourceManager
- Both Preview and Program monitors are running (rendering locally)
- Host can cycle through scenes, see themselves in different layouts
- Check that guest feeds (if connected in green room) appear correctly
- Nothing is published to LiveKit yet — just local preview
- Once satisfied, click "Go Live" → Program canvas stream gets published

This eliminates the "go live and hope it works" problem. The host verifies everything visually first.

### Template Structure

```html
<!-- Dual monitor container -->
<div class="flex gap-2" style="max-height: 45vh;">
    <!-- Preview Monitor -->
    <div class="relative flex-1 border-2 border-green-500/50 rounded-lg overflow-hidden bg-black">
        <canvas x-ref="previewCanvas" wire:ignore
                class="w-full h-full object-contain" width="1280" height="720"></canvas>
        <span class="absolute top-2 left-2 rounded bg-green-600/90 px-2 py-0.5 text-[10px]
                     font-bold text-white tracking-wider">PREVIEW</span>
        <span class="absolute top-2 right-2 rounded bg-black/60 px-2 py-0.5 text-[10px]
                     text-zinc-300" x-text="previewSceneName"></span>
    </div>

    <!-- Program Monitor -->
    <div class="relative flex-1 border-2 border-red-500/50 rounded-lg overflow-hidden bg-black">
        <canvas x-ref="programCanvas" wire:ignore
                class="w-full h-full object-contain" width="1280" height="720"></canvas>
        <span class="absolute top-2 left-2 rounded bg-red-600/90 px-2 py-0.5 text-[10px]
                     font-bold text-white tracking-wider">PROGRAM</span>
        <span class="absolute top-2 right-2 rounded bg-black/60 px-2 py-0.5 text-[10px]
                     text-zinc-300" x-text="liveSceneName"></span>
    </div>
</div>
```

### Alpine State

```javascript
// Scene state
previewSceneIndex: 0,     // Scene loaded in Preview monitor
liveSceneIndex: 0,        // Scene currently live in Program

// Compositor instances
let previewCompositor = null;  // Renders preview canvas
let programCompositor = null;  // Renders program canvas → published to LiveKit

// TAKE action
take() {
    this.liveSceneIndex = this.previewSceneIndex;
    programCompositor.setScene(this.scenes[this.liveSceneIndex]);
    this.$wire.switchScene(this.liveSceneIndex);
}

// Scene preview (does NOT change what's live)
previewScene(index) {
    this.previewSceneIndex = index;
    previewCompositor.setScene(this.scenes[index]);
}
```

---

## Layer 5: Broadcast — LiveKit Output Only

### Going Live Flow

```
1. Host clicks "Go Live"
2. Livewire creates LiveKit room (unchanged)
3. Alpine:
   a. Get local camera MediaStream
   b. sourceManager.addSource('host', cameraStream)
   c. programCompositor = new FrameCompositor(programCanvas, sourceManager)
   d. previewCompositor = new FrameCompositor(previewCanvas, sourceManager)
   e. programCompositor.setScene(soloScene)
   f. previewCompositor.setScene(soloScene)
   g. programCompositor.start()
   h. previewCompositor.start()
   i. canvasStream = programCompositor.getOutputStream()
   j. canvasTrack = canvasStream.getVideoTracks()[0]
   k. Connect to LiveKit room
   l. Publish canvasTrack to LiveKit (ONE TIME — program canvas only)
   m. Publish audio track to LiveKit (host mic, direct)
4. Viewers subscribe and see the program canvas output
```

### Audio Handling

Audio is NOT composited through the canvas. Audio tracks are published directly:
- Host mic → published to LiveKit directly
- Guest audio → arrives via WebRTC peer connection → published to LiveKit as additional audio tracks
- Viewers receive all audio tracks and the browser mixes them automatically

This is important: canvas `captureStream()` doesn't carry audio. Audio must be handled separately.

### Source Monitor Thumbnails

The source monitor strip shows each available source with live video:
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
1. **Pre-live preview:** Open control room before going live → verify Preview and Program monitors render host camera in Solo layout → try switching scenes in Preview → verify feeds appear in correct positions
2. **Go live:** Click Go Live → verify Program monitor shows live indicator → viewer sees composed output
3. **Preview/Take workflow:** Click "Interview" scene → verify Preview shows host+guest layout → click TAKE → verify Program switches → viewer sees interview layout
4. **Guest connect:** Guest opens room → clicks Go Live → verify video appears in source monitor, Preview, AND Program (if scene includes that guest slot)
5. **Scene preview before switch:** With guest connected, preview Guest Spotlight → see guest full-screen in Preview while Program still shows Solo → click TAKE → viewer sees spotlight
6. **Guest disconnect:** Guest closes browser → verify source removed cleanly → compositor shows placeholder → TAKE back to Solo
7. **Quality check:** Compare guest video quality between source monitor (direct peer) and viewer output (through canvas)

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
