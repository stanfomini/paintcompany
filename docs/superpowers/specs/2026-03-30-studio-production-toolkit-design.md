# Studio Production Toolkit — Scene Templates, Overlays, Captions

## Problem

The live studio has working video compositing (host + guests via WebRTC, dual Preview/Program monitors, TAKE workflow) but lacks the production tools that make it actually useful: configurable scene layouts with source assignment, text/image/HTML overlays, and live captions. Without these, the host can only use the 5 hardcoded scene presets with fixed source slots.

## Architecture

Four subsystems layered on the existing FrameCompositor:

```
User Interface (Alpine.js in manage.blade.php)
├── Scene Config Panel — select layout, assign sources to slots
├── Overlay Panel — add/edit/toggle text, image, HTML, caption overlays
└── Caption Controls — enable/configure live transcription

    ▼ passes config to ▼

Modules (resources/js/studio/)
├── OverlayRenderer.js — manages overlay lifecycle, captures HTML overlays as images
├── CaptionEngine.js — Web Speech API, active speaker detection, caption text
└── FrameCompositor.js — draws video slots + all overlay types onto canvas
```

All configuration happens in Alpine reactive state and persists to the `LiveEvent` model via Livewire. Both Preview and Program compositors share the same overlay data.

---

## 1. Scene Template System

### Preset Layout Library

Built-in layouts stored as constants (no database needed):

| Layout | Slots | Normalized Positions |
|--------|-------|---------------------|
| Solo | 1 | `[{label: 'A', x:0, y:0, w:1, h:1}]` |
| Interview | 2 | `[{label:'A', x:0, y:0, w:0.5, h:1}, {label:'B', x:0.5, y:0, w:0.5, h:1}]` |
| Panel 3-Up | 3 | `[{label:'A', x:0, y:0, w:0.333, h:1}, {label:'B', x:0.333, y:0, w:0.334, h:1}, {label:'C', x:0.667, y:0, w:0.333, h:1}]` |
| PiP | 2 | `[{label:'A', x:0, y:0, w:1, h:1}, {label:'B', x:0.73, y:0.7, w:0.25, h:0.25}]` |
| Sidebar | 2 | `[{label:'A', x:0, y:0, w:0.7, h:1}, {label:'B', x:0.7, y:0, w:0.3, h:1}]` |
| Screen + Speaker | 2 | `[{label:'A', x:0, y:0, w:1, h:1}, {label:'B', x:0.75, y:0.72, w:0.23, h:0.25}]` |

### Source Assignment

Each slot has a `source` field that the host sets via dropdown. Available sources come from `SourceManager.getAllSources()` plus `(none)` for empty slots.

Scene data structure:
```javascript
{
    name: 'Interview',
    layout: 'interview',        // Preset layout key
    slots: [
        { label: 'A', source: 'host', x: 0, y: 0, w: 0.5, h: 1 },
        { label: 'B', source: 'guest-1', x: 0.5, y: 0, w: 0.5, h: 1 },
    ]
}
```

### Scene Config UI

When a scene is selected in the Preview, the Scene Config row shows dropdowns:

```
Scene Config:  Layout: [Interview ▼]
               Slot A: [Host ▼]   Slot B: [Guest 1 ▼]
```

- Changing a source dropdown updates the Preview compositor immediately
- Program stays unchanged until TAKE
- Changing layout resets slot positions to the preset's defaults
- Sources list is dynamic (updates when guests connect/disconnect)

### Custom Templates

Host can save current scene (layout + assignments) as a named template:
- Save: enters name → stored in `live_event_scene_templates` table (existing)
- Load: dropdown of saved templates → applies layout + assignments to Preview
- Delete: remove saved template

---

## 2. Overlay System

### Data Structure

Overlays stored in `live_events.overlays` JSON column (already exists):

```javascript
[
    {
        type: 'text',
        content: 'John Smith - CEO',
        style: 'lower-third',         // 'lower-third' | 'title' | 'ticker' | 'custom'
        position: 'bottom-center',     // Preset position key
        x: 0.5, y: 0.9,               // Normalized (used for 'custom' position)
        fontSize: 28,
        fontWeight: '700',
        color: '#ffffff',
        bg: '#000000cc',
        visible: true,
    },
    {
        type: 'image',
        src: '/storage/overlays/logo.png',   // URL to uploaded image
        position: 'top-right',
        x: 0.85, y: 0.02,
        w: 0.12, h: 0.08,                   // Normalized size
        opacity: 0.8,
        visible: true,
    },
    {
        type: 'html',
        src: 'https://ticker.example.com',   // Iframe URL
        html: null,                          // OR raw HTML string
        position: 'bottom',
        x: 0, y: 0.92,
        w: 1.0, h: 0.08,
        refreshInterval: 2000,               // ms between captures
        visible: false,
    },
    {
        type: 'caption',
        position: 'bottom-center',
        x: 0.1, y: 0.85,
        w: 0.8,
        style: 'white-on-black',             // 'white-on-black' | 'yellow-on-transparent' | 'custom'
        fontSize: 24,
        maxLines: 2,
        showSpeakerName: true,
        visible: true,
    }
]
```

### Position Presets

| Key | x | y | Description |
|-----|---|---|-------------|
| `top-left` | 0.02 | 0.02 | Top-left corner |
| `top-center` | 0.5 | 0.02 | Top center (text-align center) |
| `top-right` | 0.85 | 0.02 | Top-right corner |
| `center` | 0.5 | 0.5 | Dead center |
| `bottom-left` | 0.02 | 0.88 | Bottom-left |
| `bottom-center` | 0.5 | 0.88 | Bottom center (for captions/lower thirds) |
| `bottom-right` | 0.85 | 0.88 | Bottom-right |
| `bottom` | 0 | 0.92 | Full-width bottom bar |
| `custom` | user-set | user-set | Free x/y input |

### Overlay Panel UI

Below the scene config row:

```
Overlays:
[🟢] Lower Third: "John Smith - CEO"          [Edit] [✕]
[⚪] Logo: logo.png                           [Edit] [✕]
[🟢] HTML: https://ticker.example.com         [Edit] [✕]
[🟢] Captions: Active Speaker                 [⚙️]  [✕]

[+ Text]  [+ Image]  [+ HTML]  [+ Caption]
```

- Green dot = visible, gray dot = hidden. Click to toggle.
- [Edit] opens a modal for that overlay type.
- [✕] removes the overlay.
- Changes persist to the `LiveEvent` model via `$wire.updateOverlays()`.

### Add/Edit Modals

Each overlay type has its own modal with the fields from the data structure above. Modals use Flux UI components (already in the project).

---

## 3. OverlayRenderer Module

**File:** `resources/js/studio/OverlayRenderer.js`

Handles the lifecycle of HTML overlays — renders them offscreen and captures as images for the FrameCompositor to draw on canvas.

### Why This Is Needed

Canvas `drawImage()` can't render live HTML or iframes directly. The solution:
1. Render the HTML/iframe in a hidden DOM element
2. Capture it as an image using `html2canvas` (already in the project's npm deps)
3. Pass the captured image to FrameCompositor at the configured refresh interval

### API

```javascript
class OverlayRenderer {
    constructor(containerEl)                   // Hidden DOM container for rendering

    addHtmlOverlay(index, config)              // Start rendering an HTML overlay
    removeHtmlOverlay(index)                   // Stop rendering
    getCapturedImage(index)                    // Get latest captured HTMLImageElement
    destroy()                                  // Clean up all renderers
}
```

### Render Loop

For each active HTML overlay:
1. Create a hidden `<div>` or `<iframe>` in the container
2. Set size to match the overlay's w/h at canvas resolution (e.g., 1280*w x 720*h pixels)
3. Every `refreshInterval` ms, capture via `html2canvas` → store as `Image` object
4. FrameCompositor calls `overlayRenderer.getCapturedImage(index)` during draw

### Limitations

- HTML overlays update at `refreshInterval` (default 2s), not every frame — this is a tradeoff for performance
- Iframes must allow cross-origin access or be same-origin for `html2canvas` to work
- Complex HTML may render slowly — keep overlays simple

---

## 4. CaptionEngine Module

**File:** `resources/js/studio/CaptionEngine.js`

Real-time speech-to-text using the Web Speech API with active speaker detection.

### API

```javascript
class CaptionEngine {
    constructor()

    start(audioSources)                        // {host: MediaStreamTrack, guests: Map<id, MediaStreamTrack>}
    stop()
    onCaption(callback)                        // ({speaker: string, text: string, isFinal: boolean}) => void
    getActiveSpeaker()                         // Returns key of loudest source
}
```

### How It Works

1. **Active Speaker Detection:** Compares audio levels from host mic and each guest's audio track (received via WebRTC). Uses `AudioContext` + `AnalyserNode` to sample volume levels. The loudest source above a threshold is the "active speaker."

2. **Speech Recognition:** Creates a `SpeechRecognition` instance (Web Speech API). Feeds it the active speaker's audio. Emits interim and final results via `onCaption()` callback.

3. **Caption Rendering:** The FrameCompositor receives caption text from the callback and draws it as a text overlay with speaker name prefix and styled background box.

### Active Speaker Flow

```
Every 100ms:
  hostLevel = analyseLevel(hostAudioTrack)
  guest1Level = analyseLevel(guest1AudioTrack)
  guest2Level = analyseLevel(guest2AudioTrack)

  activeSpeaker = highest level > threshold
  if activeSpeaker changed:
    restart SpeechRecognition with new audio source
```

### Limitations

- Web Speech API requires Chrome/Edge (not supported in Firefox/Safari)
- Accuracy varies by microphone quality and background noise
- Active speaker switching has ~200ms latency
- Caption text may have 500-1500ms delay from speech
- `SpeechRecognition` can only process one audio source at a time

### Fallback

If `SpeechRecognition` is not available, the Caption button shows "Captions not supported in this browser" and is disabled.

---

## 5. FrameCompositor Changes

### Updated Draw Pipeline

```javascript
_draw() {
    // 1. Clear
    ctx.fillRect(0, 0, w, h);

    // 2. Draw video slots (existing)
    for (slot of scene.slots) this._drawSlot(ctx, slot, w, h);

    // 3. Draw overlays (in order, bottom to top)
    for (overlay of this._overlays) {
        if (!overlay.visible) continue;

        switch (overlay.type) {
            case 'image': this._drawImageOverlay(ctx, overlay, w, h); break;
            case 'text':  this._drawTextOverlay(ctx, overlay, w, h); break;
            case 'html':  this._drawHtmlOverlay(ctx, overlay, w, h); break;
            case 'caption': this._drawCaptionOverlay(ctx, overlay, w, h); break;
        }
    }
}
```

### New Draw Methods

**`_drawHtmlOverlay(ctx, overlay, w, h)`**: Gets the captured image from `OverlayRenderer` and draws it at the overlay's position/size. Falls back to a placeholder rectangle if no capture is available yet.

**`_drawCaptionOverlay(ctx, overlay, w, h)`**: Draws the current caption text (from CaptionEngine) with speaker name prefix, styled background box, word wrapping to `maxLines`.

### Text Rendering Improvements

The existing `_drawTextOverlay` is basic (no word wrap, no lower-third style). Enhance it:

- **Lower Third:** Full-width bar at bottom with name + title on two lines, semi-transparent background
- **Title:** Centered large text with optional background
- **Ticker:** Scrolling text (position shifts left each frame)
- **Custom:** Free-positioned text at x/y coords

### Constructor Changes

```javascript
constructor(canvas, sourceManager, options = {}) {
    // ... existing ...
    this._overlayRenderer = options.overlayRenderer ?? null;
    this._captionEngine = options.captionEngine ?? null;
    this._captionText = '';
    this._captionSpeaker = '';
}
```

The Alpine component passes `overlayRenderer` and `captionEngine` when constructing compositors.

---

## 6. File Changes Summary

### New Files

| File | Purpose |
|------|---------|
| `resources/js/studio/OverlayRenderer.js` | HTML overlay capture engine |
| `resources/js/studio/CaptionEngine.js` | Web Speech API + active speaker detection |

### Modified Files

| File | Changes |
|------|---------|
| `resources/js/studio/FrameCompositor.js` | Add `_drawHtmlOverlay`, `_drawCaptionOverlay`, enhanced text styles (lower-third, title, ticker), accept overlayRenderer + captionEngine in constructor |
| `resources/views/pages/events/⚡manage.blade.php` | Scene Config row with layout picker + source assignment dropdowns, Overlay panel with add/edit/toggle/remove for all 4 types, Caption controls, overlay modals |
| `resources/js/app.js` | Add `loadOverlayRenderer`, `loadCaptionEngine` lazy loaders |

### Database

No schema changes. Overlays already stored in `live_events.overlays` JSON column. Scene templates already in `live_event_scene_templates` table.

---

## 7. Verification Plan

### Manual Testing

1. **Scene layout switching:** Pick Interview layout → assign Host to Slot A, Guest 1 to Slot B → verify Preview shows correct placement → TAKE → verify Program matches
2. **Source reassignment:** While live, change Slot B from Guest 1 to Screen → verify Preview updates → TAKE
3. **Text overlay:** Add lower-third → verify it appears on both monitors → toggle off → verify hidden → edit text → verify update
4. **Image overlay:** Upload logo PNG → position top-right → verify renders with transparency
5. **HTML overlay:** Add iframe URL → verify it captures and renders on canvas (with ~2s refresh)
6. **Live captions:** Enable captions → speak → verify text appears with ~1s delay → have guest speak → verify speaker name changes
7. **Persist across scenes:** Add overlay → switch scene → verify overlay still visible
8. **Save/load template:** Configure scene + overlays → save as template → load on new scene → verify matches
