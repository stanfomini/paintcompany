# LiveKit-Native Live Production Studio

## Problem

The current live studio uses two separate WebRTC systems: LiveKit for host→viewer broadcast, and a custom whisper-based peer connection system (PeerManager/GuestPeerConnection) for guests. The custom system is fundamentally broken — connections die on page transitions, there's no reconnection, signaling depends on both parties listening at the exact same moment, and the entire system fails if any initialization step errors.

## Solution

Replace ALL custom WebRTC code with LiveKit. One LiveKit room per event handles guests, host, viewers, and RTMP egress. Delete PeerManager.js, GuestPeerConnection.js, and all whisper-based signaling.

## Architecture

```
GUESTS (≤10)              HOST                    VIEWERS (≤100)        SOCIAL
┌──────────┐          ┌──────────────┐          ┌───────────┐       ┌──────────┐
│ Camera   │          │              │          │           │       │ YouTube  │
│ + Mic    │──WebRTC──│  Canvas      │──WebRTC──│ <video>   │       │ Facebook │
│          │  via     │  Compositor  │  publish │ element   │       │ Twitch   │
│ LiveKit  │  LiveKit │  (browser)   │  canvas  │ via LK    │       │          │
│ Room     │  SFU     │              │  track   │ subscribe │       │ LiveKit  │
└──────────┘          └──────────────┘          │ ~100ms    │       │ Egress   │
                                                └───────────┘       │ → RTMP   │
                                                                    └──────────┘
```

### One LiveKit Room Per Event

Every participant (host, guests, viewers) joins the **same LiveKit room**:

| Role | canPublish | canSubscribe | What they do |
|------|-----------|-------------|-------------|
| Host | true | true | Publishes canvas track + host audio. Subscribes to all guest tracks. |
| Guest (green room) | false | true | Can see broadcast. Cannot send video. Waiting. |
| Guest (on air) | true | true | Publishing camera + mic. Host receives their tracks. |
| Viewer | false | true | Subscribes to canvas track only. Volume control. Read-only. |

### Green Room → On Air Flow

```
Guest joins room (canPublish: false)
    → Host sees guest in participant list (real-time, no polling)
    → Host clicks "Bring On"
    → PHP calls LiveKit RoomService API: UpdateParticipant(canPublish: true)
    → LiveKit automatically enables guest's camera/mic publishing
    → Host receives guest tracks via room.on(TrackSubscribed)
    → Guest video appears in SourceManager → Compositor renders it

Host clicks "Take Off"
    → PHP calls UpdateParticipant(canPublish: false)
    → LiveKit automatically unpublishes all guest tracks instantly
    → Host's TrackUnsubscribed event fires → SourceManager removes source
```

No whispers. No manual WebRTC. No PeerManager. No GuestPeerConnection. LiveKit handles all signaling, ICE, reconnection, and track management.

### Viewer Player Component

Embeddable Blade component for any page:

```blade
<x-live-player :event="$event" />
```

Renders a standard video player with:
- `<video>` element receiving the LiveKit canvas track
- Volume slider
- LIVE badge (with viewer count)
- Fullscreen button
- Auto-connect when page loads (if event is live)
- Auto-disconnect on page leave
- Lazy-loads LiveKit SDK only when needed

### RTMP Egress to Social Channels

LiveKit Egress pushes the canvas track to RTMP endpoints:

```php
// Start streaming to YouTube
$egressService->startTrackCompositeEgress(
    roomName: $event->livekit_room_name,
    output: new StreamOutput(urls: ['rtmp://a.rtmp.youtube.com/live2/YOUR_KEY']),
    audioTrackId: $hostAudioTrackId,
    videoTrackId: $canvasTrackId,
);
```

### Token Generation

```php
// Host token
$token = LiveKitService::generateHostToken($event);
// Grants: canPublish, canSubscribe, canPublishData, roomAdmin

// Guest token (green room)
$token = LiveKitService::generateGuestToken($event, $guest);
// Grants: canSubscribe (NOT canPublish)
// When brought on, PHP calls UpdateParticipant to grant canPublish

// Viewer token
$token = LiveKitService::generateViewerToken($event, $viewerName);
// Grants: canSubscribe only
```

### Scaling

| Scale | Setup |
|-------|-------|
| ≤50 viewers | Single LiveKit node (current server) |
| 50-500 | Add second LiveKit node + shared Redis |
| 500-5000 | LiveKit Cloud ($50/mo) or 3+ nodes + Redis |
| 5000+ | LiveKit Cloud + HLS egress to Bunny CDN as fallback tier |

## What Gets Deleted

- `resources/js/studio/PeerManager.js` — DELETE entirely
- `resources/js/studio/GuestPeerConnection.js` — DELETE entirely
- All whisper-based signaling in manage.blade.php (`_setupPeerManager`, `studioChannel.whisper()`)
- All whisper-based signaling in guest-room.blade.php (`goLive()` WebRTC flow)
- `EventGuestAuthController.php` — simplified (guests auth via LiveKit tokens, not Pusher presence)

## What Gets Modified

- **`manage.blade.php` (host)**:
  - Replace PeerManager with LiveKit `room.on(TrackSubscribed)` for guest tracks
  - Guest list from LiveKit participant events (real-time)
  - Bring On/Take Off calls `LiveKitService::updateParticipantPermissions()`
  - Keep: FrameCompositor, SourceManager, OverlayRenderer, scenes, overlays

- **`guest-room.blade.php` (guest)**:
  - Replace GuestPeerConnection with LiveKit room join (canPublish: false)
  - Remove "Go Live" WebRTC flow — publishing happens automatically when host grants permission
  - Keep: camera preview, backstage chat, green room UI

- **`LiveKitService.php`**:
  - Add: `updateParticipantPermissions()`, `generateViewerToken()`, `startRtmpEgress()`, `stopEgress()`
  - Modify: `generateGuestToken()` — canSubscribe only (no canPublish)

## What Gets Created

- **`resources/views/components/live-player.blade.php`** — embeddable viewer component
- **`resources/js/studio/LivePlayer.js`** — lightweight viewer (connect to room, attach track to video, volume control)

## Files Summary

| File | Action |
|------|--------|
| `resources/js/studio/PeerManager.js` | DELETE |
| `resources/js/studio/GuestPeerConnection.js` | DELETE |
| `resources/js/studio/LivePlayer.js` | CREATE |
| `resources/views/components/live-player.blade.php` | CREATE |
| `resources/views/pages/events/⚡manage.blade.php` | MODIFY (replace PeerManager with LiveKit tracks) |
| `resources/views/pages/events/⚡guest-room.blade.php` | MODIFY (replace GuestPeerConnection with LiveKit) |
| `app/Services/LiveKitService.php` | MODIFY (add permission management, viewer tokens, egress) |
| `resources/js/app.js` | MODIFY (remove loadPeerManager/loadGuestPeerConnection, add loadLivePlayer) |

## Verification

1. Create event → go live → verify host camera appears in compositor
2. Invite guest → guest joins green room → verify guest appears in host's participant list
3. Bring guest on → verify guest camera appears as source in compositor
4. Take guest off → verify guest camera disappears
5. Guest refreshes page → verify guest reconnects and can be brought on again
6. Open viewer page → verify video plays with volume control
7. Embed player on home page → verify it works
8. Start RTMP egress → verify stream appears on YouTube/Facebook
9. 10 guests + 50 viewers simultaneously → verify performance
