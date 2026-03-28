# Live Production Studio — Design Spec

## Context

The Live Event system works well for single-camera broadcasts with chat, Q&A, and resources. But to produce compelling content — interviews with remote guests, screen shares, branded overlays — the host needs production studio capabilities built into the existing control room.

The old Stream Studio (`StreamSession` model, `studio/⚡studio.blade.php`) attempted this but never worked. This design replaces it by enhancing the working Live Event system with production features: remote guest participation, scene switching, canvas-based frame compositing, and overlays.

**The goal:** A single producer can run a professional multi-feed livestream from a browser — switching between their camera, remote guests on phones, and screen shares — with branded overlays and text, all built into the existing `events/{slug}/manage` page.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                   PRODUCER BROWSER                       │
│                                                          │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐               │
│  │ Local    │  │ Guest 1  │  │ Guest 2  │  ... (up to 8)│
│  │ Camera   │  │ Feed     │  │ Feed     │               │
│  └────┬─────┘  └────┬─────┘  └────┬─────┘               │
│       │              │              │                    │
│       ▼              ▼              ▼                    │
│  ┌──────────────────────────────────────────┐            │
│  │         CANVAS COMPOSITOR                 │            │
│  │  - Draws selected feeds at scene positions│            │
│  │  - Renders text overlays (topic, names)   │            │
│  │  - Renders image overlays (logo)          │            │
│  │  - captureStream() → MediaStream          │            │
│  └────────────────┬─────────────────────────┘            │
│                   │                                      │
│          OR (raw camera mode)                            │
│                   │                                      │
│                   ▼                                      │
│  ┌──────────────────────────────────────────┐            │
│  │     LiveKit Room (publish composed track) │            │
│  └────────────────┬─────────────────────────┘            │
│                   │                                      │
└───────────────────┼──────────────────────────────────────┘
                    │
                    ▼
┌──────────────────────────────────────────────┐
│           LiveKit SFU Server                  │
│  Routes composed track to all viewers         │
│  Routes guest tracks to producer (subscribe)  │
└──────────────────────────────────────────────┘
                    │
                    ▼
            Public Viewers (read-only)
```

### Key Architectural Decision: Canvas Compositing

The producer's browser composites all feeds + overlays onto an HTML5 Canvas, then publishes `canvas.captureStream()` as a single video track to LiveKit. Viewers always see the composed output — they never see raw individual feeds.

**Raw camera bypass:** The producer can toggle between "Frame Mode" (canvas compositing) and "Camera Mode" (raw camera published directly). This is a per-session choice, not per-scene.

### LiveKit Room Participant Roles

All participants (host, co-producers, guests) join the **same LiveKit room** for the event:

| Role | Identity Format | canPublish | canSubscribe | Notes |
|------|----------------|------------|--------------|-------|
| Host/Producer | `host-{userId}` | true | true | Publishes composed canvas stream |
| Co-Producer | `coproducer-{userId}` | true | true | Subscribes to all feeds, can also publish |
| Guest | `guest-{guestId}` | true | true | Publishes their camera, subscribes to stream preview |
| Viewer | `viewer-{userId/guestId}` | false | true | Read-only, sees composed stream |

**Guest publish gating:** Guests join with `canPublish: true` but their camera starts disabled. The producer controls when guest cameras activate via LiveKit data messages. When a guest is "brought on," a data message tells their client to enable their camera. When they're taken off, another message tells them to disable it.

---

## Data Model Changes

### LiveEvent Model — New Fields

Add production-related fields to the existing `live_events` table:

```php
// Migration: add production fields to live_events
$table->json('scenes')->nullable();           // Scene definitions (slots, overlays)
$table->integer('active_scene_index')->default(0);
$table->json('overlays')->nullable();         // Global overlays (logo, persistent text)
$table->boolean('use_frame_mode')->default(true); // Canvas compositing vs raw camera
```

### LiveEventGuest Model (New)

Tracks remote guest invitations for an event:

```php
// live_event_guests table
$table->id();
$table->foreignId('live_event_id')->constrained()->cascadeOnDelete();
$table->string('name');                       // Guest display name
$table->string('email')->nullable();          // For email invite
$table->string('phone')->nullable();          // For SMS invite
$table->text('instructions')->nullable();     // What to talk about, when they'll be called
$table->string('token', 64)->unique();        // URL token (signed, expires with event)
$table->string('status')->default('invited'); // invited, connected, live, disconnected
$table->string('livekit_identity')->nullable(); // Their LiveKit participant identity
$table->integer('slot_number')->nullable();   // Which feed slot (1-8) they're assigned to
$table->timestamp('connected_at')->nullable();
$table->timestamp('went_live_at')->nullable();
$table->timestamps();
```

### LiveEventSceneTemplate Model (New)

Reusable scene layout templates:

```php
// live_event_scene_templates table
$table->id();
$table->foreignId('user_id')->constrained()->cascadeOnDelete();
$table->string('name');                       // "Interview", "Panel", "Solo + Logo"
$table->json('scenes');                       // Scene definitions
$table->json('overlays')->nullable();         // Default overlays for this template
$table->timestamps();
```

### Scene Data Structure (JSON)

```json
[
  {
    "name": "Solo",
    "slots": [
      { "source": "host", "x": 0, "y": 0.05, "w": 1, "h": 0.9 }
    ],
    "overlays": [
      { "type": "text", "content": "Topic Title Here", "x": 0.05, "y": 0.02, "fontSize": 32, "fontWeight": "bold", "color": "#ffffff", "bg": "#000000cc" },
      { "type": "image", "src": "/storage/logo.png", "x": 0.85, "y": 0.02, "w": 0.12, "h": 0.08 }
    ]
  },
  {
    "name": "Interview",
    "slots": [
      { "source": "host", "x": 0, "y": 0.05, "w": 0.5, "h": 0.9 },
      { "source": "guest-1", "x": 0.5, "y": 0.05, "w": 0.5, "h": 0.9 }
    ],
    "overlays": [
      { "type": "text", "content": "Live Interview", "x": 0.05, "y": 0.02, "fontSize": 28, "fontWeight": "bold", "color": "#ffffff", "bg": "#000000cc" }
    ]
  },
  {
    "name": "Guest Spotlight",
    "slots": [
      { "source": "guest-1", "x": 0, "y": 0.05, "w": 1, "h": 0.9 }
    ],
    "overlays": []
  },
  {
    "name": "Screen Share",
    "slots": [
      { "source": "screen", "x": 0, "y": 0, "w": 1, "h": 1 }
    ],
    "overlays": []
  },
  {
    "name": "PiP",
    "slots": [
      { "source": "guest-1", "x": 0, "y": 0, "w": 1, "h": 1 },
      { "source": "host", "x": 0.73, "y": 0.7, "w": 0.25, "h": 0.25 }
    ],
    "overlays": []
  }
]
```

Slot sources: `"host"`, `"guest-1"` through `"guest-8"`, `"screen"` (screen share).

Positions use fractional coordinates (0-1) so they scale to any output resolution.

---

## Components & Pages

### 1. Enhanced Manage Page — Pre-Live State

**File:** `resources/views/pages/events/⚡manage.blade.php`

Add to the existing pre-live section (below camera preview, above "Go Live" button):

**Production Setup Section:**
- **Scene Configuration** — Pick a template or customize scenes. Each scene shows a visual preview of slot positions. Drag handles to resize/reposition feeds within the frame.
- **Overlay Editor** — Add/edit text overlays (topic title, lower-thirds) and image overlays (logo). Position with drag handles on a preview canvas.
- **Guest Management** — Invite guests by name + email/phone. Write instructions for each guest. Generate invite links. See connection status in real-time once guests open their links.
- **Frame Mode Toggle** — Switch between "Frame Mode" (canvas compositing) and "Camera Mode" (raw camera).
- **Backstage Chat** — Direct communication channel with connected guests (uses Reverb private channel).
- **Template Save/Load** — Save current scene+overlay config as a reusable template.

### 2. Enhanced Manage Page — Live State

**File:** Same file, live state section

The existing live state gets a major upgrade:

**Left Column (expanded):**
- **Main Preview** — Shows the composed canvas output (what viewers see)
- **Feed Thumbnails** — Row of small previews: host camera, each connected guest, screen share. Click to focus. Green border = currently in active scene. Shows guest name below each.
- **Scene Switcher Bar** — Row of scene buttons (Solo, Interview, Guest Spotlight, etc.). Click to switch. Active scene highlighted. Smooth transition.
- **Overlay Quick Controls** — Toggle overlays on/off, edit text inline.
- **Controls Bar** — Mic, camera, screen share, frame/camera toggle, end stream.

**Right Column (existing, enhanced):**
- **Backstage Chat Tab** — Private chat with guests (separate from public)
- **Live Chat Tab** — Existing public chat
- **Q&A Tab** — Existing Q&A queue
- **Resources Tab** — Existing resource manager
- **Guests Tab** — Guest connection status, "Bring On" / "Take Off" buttons, "Not Ready" indicator

### 3. Guest Green Room Page (New)

**File:** `resources/views/pages/events/⚡guest-room.blade.php`

**Route:** `GET /events/guest/{token}` — Signed URL, expires when event ends.

**Layout:** Full-screen mobile-optimized page. No app chrome.

**Sections:**
- **Event Info** — Title, host name, topic description
- **Instructions** — What the host wrote for this guest (what to discuss, when they'll be called)
- **Stream Preview** — Full audio + video preview of the livestream (subscribes to the host's published track via LiveKit). Mutes automatically when guest goes live to prevent echo.
- **Camera Preview** — Shows their own camera feed with:
  - Camera device switcher (front/rear, or multiple cameras)
  - Mic device switcher
  - Audio level indicator (so they know their mic works)
  - Mute/unmute toggle
- **Status Banner:**
  - `Waiting` (green, calm) — "You're in the green room. The host will bring you on when ready."
  - `Called On` (amber, attention) — "The host is ready for you! Tap 'Go Live' to join." + "I'm Not Ready" button
  - `Live` (red, pulsing) — "You're LIVE! Your camera is being broadcast."
  - `Finished` (gray) — "Thanks for joining! The stream has ended."
- **Backstage Chat** — Direct message thread with the producer at the bottom of the page.

### 4. Co-Producer Access

Any internal user can access the manage page for any event. All internal users who open the manage page get full co-host access (Approach A). They join the same LiveKit room as a co-producer, subscribe to all feeds, and can switch scenes, manage chat, bring guests on/off.

The `boot()` method already gates to `internal` users. Co-producers get their own LiveKit token with `canPublish: true, canSubscribe: true`.

---

## Canvas Compositor (JavaScript)

**File:** `resources/js/studio/FrameCompositor.js`

Core Alpine.js-compatible class that handles:

```
class FrameCompositor {
    constructor(canvas, options = {})

    // Configuration
    setScene(sceneConfig)           // Load a scene definition
    setOverlays(overlays)           // Set overlay list
    setFeedSource(slotKey, videoEl) // Assign a video element to a slot source
    removeFeedSource(slotKey)       // Remove a feed source

    // Rendering
    start()                        // Begin requestAnimationFrame render loop
    stop()                         // Stop rendering

    // Output
    getOutputStream()              // Returns canvas.captureStream(30) MediaStream

    // Overlay manipulation
    updateOverlayText(index, text) // Update text content
    toggleOverlay(index, visible)  // Show/hide overlay

    // Internal
    _renderFrame()                 // Draws all slots + overlays each frame
    _drawSlot(slot)                // Draws a video feed in its position/size
    _drawOverlay(overlay)          // Draws text or image overlay
}
```

**Render loop (30fps):**
1. Clear canvas
2. For each slot in active scene: draw the assigned video element at (x, y, w, h) coordinates
3. For each overlay: draw text (with background, font styling) or image at position
4. requestAnimationFrame → repeat

**Output:** `canvas.captureStream(30)` produces a MediaStream. The video track from this stream is published to LiveKit instead of the raw camera track.

**Resolution:** 1280x720 (720p) default. Canvas dimensions set to match.

---

## Guest Lifecycle & Data Messages

Communication between producer and guest uses **LiveKit data messages** (reliable, ordered):

### Producer → Guest Messages

```json
{ "type": "bring-on", "guestId": 3 }      // Request guest to go live
{ "type": "take-off", "guestId": 3 }       // Tell guest they're off
{ "type": "cancel-bring-on", "guestId": 3 } // Cancel pending bring-on
```

### Guest → Producer Messages

```json
{ "type": "not-ready", "guestId": 3 }     // Guest declines bring-on
{ "type": "going-live", "guestId": 3 }    // Guest confirms, camera activating
```

### Guest Lifecycle Flow

```
invited → [opens link] → connected → [producer clicks "Bring On"] → called_on
    → [guest taps "Go Live"] → live → [producer clicks "Take Off"] → connected
    → [can be brought on again]

called_on → [guest taps "Not Ready"] → connected (back to waiting)
```

### Echo/Reverb for Backstage Chat

The backstage chat between producer and guests uses a **Reverb private channel** separate from the public event chat:

- **Channel:** `private:event-backstage.{eventId}`
- **Auth:** Host/co-producers (internal users) + guests with valid tokens
- **Model:** Uses the existing `ChatMessage` model with a dedicated `ChatThread` for backstage

---

## LiveKitService Changes

Add methods to `app/Services/LiveKitService.php`:

```php
// Generate token for a guest participant (can publish + subscribe)
public function generateGuestToken(LiveEvent $event, LiveEventGuest $guest): string

// Generate token for a co-producer (can publish + subscribe)
public function generateCoproducerToken(LiveEvent $event, User $user): string
```

Guest tokens use identity `guest-{guest->id}` with `canPublish: true, canSubscribe: true`.

---

## Backstage Chat

A new `ChatThread` is created per event for backstage communication (separate from the existing public chat thread).

- **Field on LiveEvent:** `backstage_chat_thread_id` (new nullable FK)
- **Created when:** First guest connects or event goes live (whichever first)
- **Participants:** Host, co-producers, all guests
- **Broadcasting:** `BackstageChatMessageSent` event on `private:event-backstage.{eventId}`
- **Expires:** Thread stops accepting messages when event ends

---

## Routes

### New Routes

```php
// Guest green room (signed URL, no auth required)
Route::get('/events/guest/{token}', GuestRoomPage::class)
    ->name('events.guest-room');

// Guest auth for Reverb presence/private channels
Route::post('/broadcasting/event-guest-auth', [EventGuestAuthController::class, 'authenticate'])
    ->name('events.guest-auth');
```

### Existing Routes (No Changes)

```php
Route::get('/events/{liveEvent}/manage', ManagePage::class)->name('events.manage');
```

---

## What Gets Removed

The old Stream Studio system that never worked:

- `app/Models/StreamSession.php` — Replace with LiveEvent fields
- `resources/views/components/studio/⚡studio.blade.php` — Replace with enhanced manage page
- `stream_sessions` migration/table — Can be dropped
- Studio routes in `routes/web.php` — Remove

The `StreamSession::defaultScenes()` pattern is a good reference — we'll port the scene data structure concept into `LiveEvent`.

---

## Implementation Phases

### Phase 1: Foundation (Guest System + Data Model)
1. Add production fields to `live_events` migration (scenes, overlays, active_scene_index, use_frame_mode, backstage_chat_thread_id)
2. Create `LiveEventGuest` model + migration + factory
3. Create `LiveEventSceneTemplate` model + migration + factory
4. Add `generateGuestToken()` and `generateCoproducerToken()` to `LiveKitService`
5. Create guest invite link generation (signed URLs tied to event)
6. Add backstage chat thread creation logic to `LiveEventService`
7. Tests for all models and service methods

### Phase 2: Guest Green Room
1. Build `⚡guest-room.blade.php` — full guest experience page
2. Guest auth controller for Reverb channels
3. LiveKit connection: subscribe to host track (stream preview), publish own camera
4. Camera/mic device switching + audio level indicator
5. Status indicator system (waiting → called on → live → finished)
6. "Not Ready" safety valve button
7. Backstage chat UI at bottom
8. Link expiration (returns "Event has ended" after event ends)
9. Tests for guest room access, auth, expiration

### Phase 3: Canvas Compositor
1. Build `FrameCompositor.js` — canvas rendering engine
2. Video feed drawing at configurable positions
3. Text overlay rendering (bold topic text, lower-thirds, TikTok-style)
4. Image overlay rendering (logo placement)
5. `captureStream()` output as publishable MediaStream
6. Frame mode vs camera mode toggle
7. Integration with LiveKit: publish canvas stream instead of raw camera

### Phase 4: Enhanced Control Room — Pre-Live
1. Scene configuration UI in pre-live state
2. Overlay editor (add/edit text, upload logo, position with preview)
3. Guest management panel (invite, write instructions, see status)
4. Backstage chat in pre-live state
5. Template save/load system
6. Frame mode toggle
7. Feed thumbnail previews for connected guests

### Phase 5: Enhanced Control Room — Live State
1. Scene switcher bar with active scene highlighting
2. Feed thumbnail row (host + all guests + screen share) with live previews
3. "Bring On" / "Take Off" buttons per guest
4. Guest status indicators (waiting, called on, live, not ready)
5. Overlay quick toggle controls
6. Screen share integration as a feed source
7. Backstage chat tab (alongside existing public chat, Q&A, resources)
8. Co-producer support (multiple internal users in same control room)

### Phase 6: Polish & Cleanup
1. Remove old StreamSession model, migration, studio components, routes
2. Scene transition animations (fade/cut)
3. Notification system for guest invites (email + SMS)
4. Mobile optimization for guest green room
5. Full test coverage for all new features

---

## Verification Plan

### Manual Testing
1. Create event → configure scenes and overlays in pre-live → verify preview renders correctly
2. Invite a guest → open guest link on phone → verify green room shows instructions, stream preview, camera controls
3. Go live → verify canvas compositor publishes to LiveKit → public viewer sees composed frame
4. Switch scenes → verify viewers see the switch in real-time
5. Click "Bring On" for guest → verify guest sees "Called On" status → guest confirms → their camera appears in scene
6. Click "Take Off" → guest camera stops, guest returns to waiting state
7. Test "Not Ready" → guest declines, returns to waiting
8. Test backstage chat → messages flow between producer and guest
9. Test co-producer → second internal user opens manage page, can control everything
10. Test frame mode toggle → switch between canvas and raw camera
11. End event → verify guest link shows "Event ended" page

### Automated Tests
- Guest model CRUD, invite link generation and expiration
- LiveKit token generation for guests and co-producers
- Guest room access control (valid token, expired token, ended event)
- Scene template CRUD
- Backstage chat creation and message sending
- Guest status lifecycle transitions
- Broadcasting events for backstage chat
- Co-producer access (internal users only)

---

## Key Files to Modify

| File | Changes |
|------|---------|
| `app/Models/LiveEvent.php` | Add guests(), sceneTemplates(), backstageChatThread() relations; scene helper methods |
| `app/Services/LiveKitService.php` | Add generateGuestToken(), generateCoproducerToken() |
| `app/Services/LiveEventService.php` | Add guest invite, backstage chat, scene management methods |
| `resources/views/pages/events/⚡manage.blade.php` | Major enhancement: production setup (pre-live), scene switcher + feed grid (live) |
| `resources/js/app.js` | Enhance livekitHost Alpine component with compositor integration, guest feed management |
| `resources/js/studio/FrameCompositor.js` | New: canvas compositing engine |

## New Files

| File | Purpose |
|------|---------|
| `app/Models/LiveEventGuest.php` | Guest invitation model |
| `app/Models/LiveEventSceneTemplate.php` | Reusable scene template model |
| `resources/views/pages/events/⚡guest-room.blade.php` | Guest green room page |
| `app/Http/Controllers/EventGuestAuthController.php` | Guest auth for Reverb channels |
| `app/Events/BackstageChatMessageSent.php` | Broadcasting event for backstage chat |
| `resources/js/studio/FrameCompositor.js` | Canvas compositing engine |
| `database/migrations/xxxx_add_production_fields_to_live_events.php` | Schema changes |
| `database/migrations/xxxx_create_live_event_guests_table.php` | Guest table |
| `database/migrations/xxxx_create_live_event_scene_templates_table.php` | Templates table |
