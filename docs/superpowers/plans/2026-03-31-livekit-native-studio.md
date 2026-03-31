# LiveKit-Native Studio Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace all custom WebRTC code (PeerManager, GuestPeerConnection, whisper signaling) with LiveKit for guest connections, giving persistent real-time connections with dynamic bring-on/take-off. Add embeddable viewer player component. Keep existing compositor, overlay, scene, and template systems untouched.

**Architecture:** One LiveKit room per event. Host, guests, and viewers all join the same room. Guests join with `canPublish: false` (green room). Host brings guest on via `UpdateParticipant(canPublish: true)` — LiveKit handles all signaling. Host receives guest tracks via `room.on(TrackSubscribed)` and feeds them to the existing SourceManager → Compositor pipeline. Viewers subscribe to the host's canvas track.

**Tech Stack:** LiveKit SFU (self-hosted), `agence104/livekit-server-sdk` PHP, `livekit-client` JS SDK, Laravel 12, Livewire 4 SFC, Alpine.js

**Key conventions:**
- Livewire SFC with `⚡` prefix, PHP at top, Blade below
- `#[Renderless]` on ALL methods called during live production
- Run `vendor/bin/pint --dirty` after PHP changes
- Run `npm run build` after JS changes
- NEVER run `migrate:fresh` or `migrate:refresh`

**Spec:** `docs/superpowers/specs/2026-03-31-livekit-native-studio-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `app/Services/LiveKitService.php` | Modify | Add `updateParticipantPermissions()`, `grantPublish()`, `revokePublish()` |
| `resources/views/pages/events/⚡manage.blade.php` | Modify | Replace `_setupPeerManager()` with LiveKit `TrackSubscribed` listeners. Remove whisper signaling. Add LiveKit-based guest track handling. |
| `resources/views/pages/events/⚡guest-room.blade.php` | Modify | Replace `GuestPeerConnection` with LiveKit room join. Publish camera on permission grant. Remove whisper-based goLive(). |
| `resources/views/components/live-player.blade.php` | Create | Embeddable viewer component with `<video>`, volume control, LIVE badge |
| `resources/js/studio/LivePlayer.js` | Create | Lightweight LiveKit subscriber for viewer pages |
| `resources/js/studio/PeerManager.js` | Delete | Replaced by LiveKit |
| `resources/js/studio/GuestPeerConnection.js` | Delete | Replaced by LiveKit |
| `resources/js/app.js` | Modify | Remove PeerManager/GuestPeerConnection loaders, add LivePlayer loader |
| `tests/Feature/LiveKitGuestTest.php` | Create | Tests for permission management |

---

### Task 1: LiveKitService — Guest Permission Management

**Files:**
- Modify: `app/Services/LiveKitService.php`
- Test: `tests/Feature/LiveKitGuestTest.php`

- [ ] **Step 1: Add updateParticipantPermissions method**

```php
// Add to app/Services/LiveKitService.php

use Livekit\ParticipantPermission;

/**
 * Update a participant's permissions in a LiveKit room.
 * Used to bring guests on/off air by toggling canPublish.
 */
public function updateParticipantPermissions(
    string $roomName,
    string $identity,
    bool $canPublish,
    bool $canSubscribe = true,
): void {
    $permission = new ParticipantPermission();
    $permission->setCanPublish($canPublish);
    $permission->setCanSubscribe($canSubscribe);
    $permission->setCanPublishData($canPublish);

    $this->getRoomClient()->updateParticipant(
        $roomName,
        $identity,
        permission: $permission,
    );

    Log::info('LiveKit: Updated participant permissions', [
        'room' => $roomName,
        'identity' => $identity,
        'canPublish' => $canPublish,
    ]);
}

/**
 * Grant publish permission to a guest (bring on air).
 */
public function grantPublish(LiveEvent $event, LiveEventGuest $guest): void
{
    $this->updateParticipantPermissions(
        $event->livekit_room_name,
        $guest->getLivekitIdentity(),
        canPublish: true,
    );
}

/**
 * Revoke publish permission from a guest (take off air).
 * LiveKit automatically unpublishes all their tracks.
 */
public function revokePublish(LiveEvent $event, LiveEventGuest $guest): void
{
    $this->updateParticipantPermissions(
        $event->livekit_room_name,
        $guest->getLivekitIdentity(),
        canPublish: false,
    );
}
```

- [ ] **Step 2: Write tests**

```php
// tests/Feature/LiveKitGuestTest.php
<?php

use App\Models\LiveEvent;
use App\Models\LiveEventGuest;
use App\Services\LiveKitService;

it('generates guest token with canPublish false', function () {
    $event = LiveEvent::factory()->create(['livekit_room_name' => 'test-room']);
    $guest = LiveEventGuest::factory()->create(['live_event_id' => $event->id]);

    $service = app(LiveKitService::class);
    $token = $service->generateGuestToken($event, $guest);

    expect($token)->toBeString()->not->toBeEmpty();
    // Token is a JWT — decode to verify grants
    $parts = explode('.', $token);
    $payload = json_decode(base64_decode($parts[1]), true);
    expect($payload['video']['canPublish'])->toBeFalse();
    expect($payload['video']['canSubscribe'])->toBeTrue();
});

it('generates host token with canPublish true', function () {
    $event = LiveEvent::factory()->create(['livekit_room_name' => 'test-room']);
    $user = \App\Models\User::factory()->create(['type' => 'internal']);

    $service = app(LiveKitService::class);
    $token = $service->generateHostToken($event, $user);

    $parts = explode('.', $token);
    $payload = json_decode(base64_decode($parts[1]), true);
    expect($payload['video']['canPublish'])->toBeTrue();
});

it('has updateParticipantPermissions method', function () {
    $service = app(LiveKitService::class);
    expect(method_exists($service, 'updateParticipantPermissions'))->toBeTrue();
    expect(method_exists($service, 'grantPublish'))->toBeTrue();
    expect(method_exists($service, 'revokePublish'))->toBeTrue();
});
```

- [ ] **Step 3: Run tests**

```bash
php artisan test --compact --filter=LiveKitGuestTest
```

- [ ] **Step 4: Run pint and commit**

```bash
vendor/bin/pint --dirty
git add app/Services/LiveKitService.php tests/Feature/LiveKitGuestTest.php
git commit -m "feat: LiveKit permission management for guest bring-on/take-off"
```

---

### Task 2: Host Page — Replace PeerManager with LiveKit Guest Tracks

**Files:**
- Modify: `resources/views/pages/events/⚡manage.blade.php`

This is the largest task. Replace `_setupPeerManager()` and all whisper signaling with LiveKit's `TrackSubscribed`/`TrackUnsubscribed` events.

- [ ] **Step 1: Read the full manage.blade.php** to understand current structure

- [ ] **Step 2: Update `bringGuestOn()` and `takeGuestOff()` Livewire methods**

Replace the whisper-based signaling with LiveKit API calls:

```php
// Replace existing bringGuestOn (around line 658)
#[Renderless]
public function bringGuestOn(int $guestId): void
{
    $guest = LiveEventGuest::where('live_event_id', $this->event->id)->findOrFail($guestId);

    // Grant publish permission in LiveKit — guest can now send camera/mic
    try {
        app(LiveKitService::class)->grantPublish($this->event, $guest);
    } catch (\Throwable $e) {
        Log::warning('LiveKit: Failed to grant publish to guest', [
            'guest_id' => $guest->id,
            'error' => $e->getMessage(),
        ]);
    }

    app(LiveEventService::class)->markGuestLive($guest);

    unset($this->guests);
    $this->dispatchGuestSlotMap();
    $this->dispatchGuestList();
}

// Replace existing takeGuestOff (around line 672)
#[Renderless]
public function takeGuestOff(int $guestId): void
{
    $guest = LiveEventGuest::where('live_event_id', $this->event->id)->findOrFail($guestId);

    // Revoke publish permission — LiveKit automatically unpublishes all tracks
    try {
        app(LiveKitService::class)->revokePublish($this->event, $guest);
    } catch (\Throwable $e) {
        Log::warning('LiveKit: Failed to revoke publish from guest', [
            'guest_id' => $guest->id,
            'error' => $e->getMessage(),
        ]);
    }

    app(LiveEventService::class)->markGuestOffAir($guest);

    // Tell Alpine to remove the guest source
    $this->dispatch('guest-take-off', identity: $guest->getLivekitIdentity(), guestId: $guest->id);

    unset($this->guests);
    $this->dispatchGuestSlotMap();
    $this->dispatchGuestList();
}
```

- [ ] **Step 3: Replace `_setupPeerManager()` with LiveKit guest track listener**

In the Alpine `x-data` section, replace the entire `_setupPeerManager()` function (lines ~2343-2425) with:

```javascript
_setupGuestTracksFromLiveKit() {
    if (!room) {
        console.warn('[Studio] No LiveKit room — guest tracks disabled');
        return;
    }

    const { RoomEvent } = window.LiveKitClient;

    // When a guest starts publishing (brought on), their tracks arrive here
    room.on(RoomEvent.TrackSubscribed, (track, publication, participant) => {
        // Only handle guest participants (identity starts with "guest-")
        if (!participant.identity.startsWith('guest-')) return;

        const guestId = participant.identity;

        if (track.kind === 'video') {
            // Create a MediaStream from the LiveKit track and add to SourceManager
            const mediaStream = new MediaStream([track.mediaStreamTrack]);
            const slotKey = this._guestSlotKey(guestId);
            const name = participant.name || guestId;

            sourceManager.addSource(slotKey, mediaStream, name);
            console.log(`[Studio] Guest video from LiveKit: ${slotKey} (${name})`);

            // Update guest status in Alpine
            this._updateGuestStatus(guestId, 'live', name);
        }

        if (track.kind === 'audio') {
            // Guest audio is automatically forwarded to all subscribers by LiveKit
            // Viewers hear it directly — no need to re-publish
            console.log(`[Studio] Guest audio from LiveKit: ${participant.identity}`);
        }
    });

    // When a guest stops publishing (taken off), their tracks are removed
    room.on(RoomEvent.TrackUnsubscribed, (track, publication, participant) => {
        if (!participant.identity.startsWith('guest-')) return;

        if (track.kind === 'video') {
            const slotKey = this._guestSlotKey(participant.identity);
            if (sourceManager) sourceManager.removeSource(slotKey);
            console.log(`[Studio] Guest video removed: ${slotKey}`);
        }
    });

    // When a participant joins the room
    room.on(RoomEvent.ParticipantConnected, (participant) => {
        if (participant.identity.startsWith('guest-')) {
            console.log(`[Studio] Guest joined room: ${participant.identity} (${participant.name})`);
            this._updateGuestStatus(participant.identity, 'connected', participant.name);
            this.$wire.refreshGuestStatuses();
        }
    });

    // When a participant leaves the room
    room.on(RoomEvent.ParticipantDisconnected, (participant) => {
        if (participant.identity.startsWith('guest-')) {
            const slotKey = this._guestSlotKey(participant.identity);
            if (sourceManager) sourceManager.removeSource(slotKey);
            console.log(`[Studio] Guest left room: ${participant.identity}`);
            this._updateGuestStatus(participant.identity, 'disconnected');
            this.$wire.refreshGuestStatuses();
        }
    });

    // Check for guests already in the room (reconnection case)
    room.remoteParticipants.forEach((participant) => {
        if (participant.identity.startsWith('guest-')) {
            this._updateGuestStatus(participant.identity, 'connected', participant.name);

            // If they're already publishing (brought on before host connected), add their tracks
            participant.trackPublications.forEach((pub) => {
                if (pub.track && pub.track.kind === 'video') {
                    const mediaStream = new MediaStream([pub.track.mediaStreamTrack]);
                    const slotKey = this._guestSlotKey(participant.identity);
                    sourceManager.addSource(slotKey, mediaStream, participant.name || participant.identity);
                    this._updateGuestStatus(participant.identity, 'live', participant.name);
                }
            });
        }
    });

    console.log('[Studio] LiveKit guest track listeners set up');
},
```

- [ ] **Step 4: Update `startStudio()` — replace PeerManager setup call**

In `startStudio()`, replace:
```javascript
// OLD (remove):
await this._setupPeerManager(eventId);
```

With:
```javascript
// NEW:
this._setupGuestTracksFromLiveKit();
```

Note: This must happen AFTER `startBroadcasting()` creates the `room` object. Move the call to AFTER `startBroadcasting()` is called, or call it from within `startBroadcasting()` after `room.connect()`.

Actually, the better approach: Call `_setupGuestTracksFromLiveKit()` right after `room.connect()` inside `startBroadcasting()`:

```javascript
// In startBroadcasting(), after room.connect() (around line 2311):
await room.connect(this._livekitUrl, this._livekitToken);
this._setupGuestTracksFromLiveKit(); // Set up guest track listeners on the connected room
```

- [ ] **Step 5: Remove old PeerManager code**

Delete these sections from the Alpine x-data:
- The `_setupPeerManager()` function entirely
- The `guest-bring-on` whisper handler (lines ~2063-2070) — replace with just a log
- The `guest-take-off` whisper handler that sends whispers (lines ~2072-2079) — keep the source removal but remove whisper
- Remove `studioChannel` variable and its Echo.join() setup
- Remove `peerManager` variable

Replace the `guest-bring-on` and `guest-take-off` event listeners:

```javascript
// Replace old whisper-based handlers with simple logging
this.$wire.on('guest-bring-on', ({ identity, name, guestId }) => {
    console.log('[Studio] Guest bring-on requested via LiveKit API:', identity, name);
    // No whisper needed — LiveKit API call already happened in PHP
    // Guest's TrackSubscribed event will fire when they start publishing
});

this.$wire.on('guest-take-off', ({ identity, guestId }) => {
    const slotKey = this._guestSlotKey(identity);
    if (sourceManager) sourceManager.removeSource(slotKey);
    this._updateGuestStatus(identity, 'connected');
    console.log('[Studio] Guest taken off:', slotKey);
    // No whisper needed — LiveKit API already revoked publish permission
});
```

- [ ] **Step 6: Update `disconnect()` cleanup**

Remove PeerManager cleanup, keep everything else:
```javascript
disconnect() {
    // Stop compositors
    if (previewCompositor) { previewCompositor.stop(); previewCompositor.destroy(); previewCompositor = null; }
    if (programCompositor) { programCompositor.stop(); programCompositor.destroy(); programCompositor = null; }

    // Stop overlay renderer
    if (overlayRenderer) { overlayRenderer.destroy(); overlayRenderer = null; }

    // Stop caption engine
    if (captionEngine) { captionEngine.stop(); captionEngine = null; }

    // Stop canvas editor
    if (canvasEditor) { canvasEditor.destroy(); canvasEditor = null; }

    // Stop source manager
    if (sourceManager) { sourceManager.destroy(); sourceManager = null; }

    // Stop host audio
    if (hostAudioTrack) { hostAudioTrack.stop(); hostAudioTrack = null; }

    // Disconnect LiveKit room
    if (room) { room.disconnect(); room = null; }

    this.connected = false;
    this.broadcasting = false;
    console.log('[Studio] Disconnected');
},
```

- [ ] **Step 7: Remove `studioChannel` / Echo studio channel join from startStudio()**

The `studioChannel` Echo presence channel was only used for WebRTC signaling. It's no longer needed since LiveKit handles all guest connections. Remove the Echo.join() call and all studioChannel references.

Keep the `event-studio` channel ONLY if backstage chat uses it. Check: backstage chat uses `event-backstage.{eventId}` private channel (separate from studio). So `event-studio` can be removed entirely from the host page.

- [ ] **Step 8: Run pint, build, and commit**

```bash
vendor/bin/pint --dirty
npm run build
git add "resources/views/pages/events/⚡manage.blade.php"
git commit -m "feat: replace PeerManager with LiveKit guest tracks on host page"
```

---

### Task 3: Guest Room — Replace GuestPeerConnection with LiveKit Publishing

**Files:**
- Modify: `resources/views/pages/events/⚡guest-room.blade.php`

- [ ] **Step 1: Read the full guest-room.blade.php**

- [ ] **Step 2: Update Livewire `mount()` — generate token with canPublish: false**

The token is already `canPublish: false` (line 134 of LiveKitService). No change needed here. Guest joins as subscriber-only. When host grants permission, guest can publish.

- [ ] **Step 3: Replace `setupStudioChannel()` with LiveKit room join**

The guest currently joins an Echo presence channel for whisper signaling. Replace with joining the LiveKit room directly:

```javascript
async setupGuestRoom(config) {
    const { Room, RoomEvent } = await window.loadLiveKit();

    this.guestRoom = new Room({
        adaptiveStream: true,
        dynacast: true,
    });

    // When permissions change (host brings us on/off), react
    this.guestRoom.on(RoomEvent.ParticipantPermissionsChanged, (prevPermissions, participant) => {
        if (participant.identity !== config.guestIdentity) return;

        const canPublish = participant.permissions?.canPublish ?? false;
        console.log('[GuestRoom] Permissions changed. canPublish:', canPublish);

        if (canPublish && this.guestStatus !== 'live') {
            // Host brought us on — publish our camera + mic
            this.guestStatus = 'live';
            this._publishLocalTracks();
        } else if (!canPublish && this.guestStatus === 'live') {
            // Host took us off — stop publishing (LiveKit auto-unpublishes)
            this.guestStatus = 'waiting';
            this._muteViewerAudio(false); // Unmute viewer audio
        }
    });

    // Subscribe to host's canvas track to see the broadcast preview
    this.guestRoom.on(RoomEvent.TrackSubscribed, (track, pub, participant) => {
        // Only show host/coproducer video+audio (not other guests)
        if (!participant.identity.startsWith('host') && !participant.identity.startsWith('coproducer')) return;

        if (track.kind === 'video') {
            const el = track.attach();
            el.style.cssText = 'width:100%;height:100%;object-fit:contain;';
            const container = this.$refs.streamPreview;
            if (container) {
                container.querySelectorAll('video').forEach(v => v.remove());
                container.appendChild(el);
            }
            this.viewerConnected = true;
        }
        if (track.kind === 'audio') {
            const el = track.attach();
            el.style.display = 'none';
            el.id = 'host-audio-preview';
            document.body.appendChild(el);
        }
    });

    this.guestRoom.on(RoomEvent.Disconnected, () => {
        console.log('[GuestRoom] Disconnected from room');
        this.guestStatus = 'finished';
    });

    // Connect to the LiveKit room
    await this.guestRoom.connect(config.livekitUrl, config.livekitToken);
    console.log('[GuestRoom] Connected to LiveKit room');
},

async _publishLocalTracks() {
    if (!localStream || !this.guestRoom) return;

    this._muteViewerAudio(true); // Mute viewer audio to prevent echo

    try {
        const videoTrack = localStream.getVideoTracks()[0];
        const audioTrack = localStream.getAudioTracks()[0];

        if (videoTrack) {
            await this.guestRoom.localParticipant.publishTrack(videoTrack, {
                name: 'camera',
                simulcast: false,
            });
            console.log('[GuestRoom] Camera published');
        }
        if (audioTrack) {
            await this.guestRoom.localParticipant.publishTrack(audioTrack, {
                name: 'microphone',
            });
            console.log('[GuestRoom] Microphone published');
        }
    } catch (err) {
        console.error('[GuestRoom] Failed to publish tracks:', err);
    }
},
```

- [ ] **Step 4: Remove old goLive() and GuestPeerConnection code**

Delete:
- The entire `goLive()` function that creates GuestPeerConnection
- All `peerConnection` references
- All `studioChannel.whisper()` calls
- The `setupStudioChannel()` function (replaced by `setupGuestRoom()`)
- Remove `connectAsViewer()` — the guest room's LiveKit connection now handles both viewing AND publishing

- [ ] **Step 5: Update init() to use new setup**

```javascript
async init() {
    await this.startLocalPreview();
    await this.setupGuestRoom({
        livekitUrl: @js($livekitUrl),
        livekitToken: @js($livekitToken),
        guestIdentity: @js($guest->getLivekitIdentity()),
    });
    this.setupBackstageChat();
},
```

- [ ] **Step 6: Keep device switching but update track replacement**

When guest switches camera/mic, replace the track in LiveKit instead of PeerConnection:

```javascript
async switchCamera(deviceId) {
    // ... get new stream with deviceId ...
    if (this.guestStatus === 'live' && this.guestRoom) {
        const sender = this.guestRoom.localParticipant;
        const videoTrack = newStream.getVideoTracks()[0];
        // Find existing video publication and replace
        for (const pub of sender.videoTrackPublications.values()) {
            if (pub.track) {
                await sender.publishTrack(videoTrack, { name: 'camera' });
                break;
            }
        }
    }
},
```

- [ ] **Step 7: Remove "Go Live" button — guest goes live automatically when host brings them on**

The green room UI changes:
- Remove the "Go Live" button entirely
- Guest sees: "Waiting for host to bring you on"
- When `ParticipantPermissionsChanged` fires with `canPublish: true`, status changes to "live" automatically
- Show "You're LIVE!" indicator

- [ ] **Step 8: Run pint, build, commit**

```bash
vendor/bin/pint --dirty
npm run build
git add "resources/views/pages/events/⚡guest-room.blade.php"
git commit -m "feat: replace GuestPeerConnection with LiveKit on guest page"
```

---

### Task 4: Embeddable Viewer Player

**Files:**
- Create: `resources/js/studio/LivePlayer.js`
- Create: `resources/views/components/live-player.blade.php`
- Modify: `resources/js/app.js`

- [ ] **Step 1: Create LivePlayer.js**

```javascript
// resources/js/studio/LivePlayer.js

/**
 * LivePlayer — Lightweight LiveKit subscriber for viewer pages.
 *
 * Connects to a LiveKit room as a subscribe-only participant,
 * attaches the host's canvas track to a <video> element.
 */
export default class LivePlayer {
    constructor(videoElement, options = {}) {
        this._videoEl = videoElement;
        this._room = null;
        this._connected = false;
        this._onStatusChange = options.onStatusChange ?? (() => {});
    }

    async connect(url, token) {
        const { Room, RoomEvent } = await window.loadLiveKit();

        this._room = new Room({
            adaptiveStream: true,
            dynacast: true,
        });

        this._room.on(RoomEvent.TrackSubscribed, (track, pub, participant) => {
            if (track.kind === 'video' && participant.identity.startsWith('host')) {
                track.attach(this._videoEl);
                this._connected = true;
                this._onStatusChange('playing');
            }
            if (track.kind === 'audio') {
                const audioEl = track.attach();
                audioEl.id = 'live-player-audio';
                document.body.appendChild(audioEl);
            }
        });

        this._room.on(RoomEvent.TrackUnsubscribed, (track) => {
            track.detach().forEach(el => el.remove());
        });

        this._room.on(RoomEvent.Disconnected, () => {
            this._connected = false;
            this._onStatusChange('disconnected');
        });

        this._room.on(RoomEvent.ParticipantConnected, () => this._onStatusChange('connected'));

        await this._room.connect(url, token);
        this._onStatusChange('connected');

        // Check for existing tracks (host already publishing)
        this._room.remoteParticipants.forEach((p) => {
            if (p.identity.startsWith('host')) {
                p.trackPublications.forEach((pub) => {
                    if (pub.track && pub.track.kind === 'video') {
                        pub.track.attach(this._videoEl);
                        this._connected = true;
                        this._onStatusChange('playing');
                    }
                    if (pub.track && pub.track.kind === 'audio') {
                        const audioEl = pub.track.attach();
                        audioEl.id = 'live-player-audio';
                        document.body.appendChild(audioEl);
                    }
                });
            }
        });
    }

    setVolume(volume) {
        const audioEl = document.getElementById('live-player-audio');
        if (audioEl) audioEl.volume = Math.max(0, Math.min(1, volume));
    }

    disconnect() {
        if (this._room) {
            this._room.disconnect();
            this._room = null;
        }
        this._connected = false;
    }

    get isConnected() {
        return this._connected;
    }
}
```

- [ ] **Step 2: Add lazy loader to app.js**

```javascript
// Add to resources/js/app.js
window.loadLivePlayer = async () => {
    if (!window.LivePlayer) {
        const module = await import('./studio/LivePlayer.js');
        window.LivePlayer = module.default;
    }
    return window.LivePlayer;
};
```

- [ ] **Step 3: Create the Blade component**

```blade
{{-- resources/views/components/live-player.blade.php --}}
@props(['event', 'token' => null, 'url' => null, 'autoplay' => true])

@php
    $livekitUrl = $url ?? config('services.livekit.url', '');
    $viewerToken = $token;
    if (!$viewerToken && $event->status === 'live') {
        try {
            $viewerToken = app(\App\Services\LiveKitService::class)
                ->generateViewerToken($event, 'Viewer', auth()->id());
        } catch (\Throwable $e) {
            // Token generation failed — show offline state
        }
    }
@endphp

<div
    x-data="{
        status: '{{ $event->status === 'live' ? 'connecting' : 'offline' }}',
        volume: 1,
        muted: false,
        fullscreen: false,
        player: null,

        async init() {
            @if ($viewerToken && $event->status === 'live')
                const LivePlayer = await window.loadLivePlayer();
                this.player = new LivePlayer(this.$refs.video, {
                    onStatusChange: (s) => { this.status = s; },
                });
                try {
                    await this.player.connect(@js($livekitUrl), @js($viewerToken));
                } catch (err) {
                    console.error('[LivePlayer] Connection failed:', err);
                    this.status = 'error';
                }
            @endif
        },

        setVolume(v) {
            this.volume = v;
            this.muted = v == 0;
            if (this.player) this.player.setVolume(v);
        },

        toggleMute() {
            this.muted = !this.muted;
            if (this.player) this.player.setVolume(this.muted ? 0 : this.volume);
        },

        toggleFullscreen() {
            if (document.fullscreenElement) {
                document.exitFullscreen();
                this.fullscreen = false;
            } else {
                this.$refs.container.requestFullscreen();
                this.fullscreen = true;
            }
        },

        destroy() {
            if (this.player) this.player.disconnect();
        },
    }"
    x-ref="container"
    class="relative overflow-hidden rounded-lg bg-black"
    {{ $attributes }}
>
    {{-- Video element --}}
    <video
        x-ref="video"
        autoplay
        playsinline
        class="h-full w-full object-contain"
        :muted="muted"
    ></video>

    {{-- Offline / Connecting overlay --}}
    <div
        x-show="status === 'offline' || status === 'connecting' || status === 'error'"
        class="absolute inset-0 flex items-center justify-center bg-black/80"
    >
        <div class="text-center">
            <template x-if="status === 'connecting'">
                <div>
                    <div class="mx-auto mb-3 size-8 animate-spin rounded-full border-2 border-white/20 border-t-white"></div>
                    <p class="text-sm text-white/70">Connecting to stream...</p>
                </div>
            </template>
            <template x-if="status === 'offline'">
                <div>
                    <svg class="mx-auto mb-3 size-10 text-white/40" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                    <p class="text-sm text-white/50">Stream is offline</p>
                </div>
            </template>
            <template x-if="status === 'error'">
                <p class="text-sm text-red-400">Connection failed</p>
            </template>
        </div>
    </div>

    {{-- Controls bar --}}
    <div
        x-show="status === 'playing' || status === 'connected'"
        class="absolute inset-x-0 bottom-0 flex items-center gap-3 bg-gradient-to-t from-black/80 to-transparent px-4 py-3"
    >
        {{-- LIVE badge --}}
        <span class="rounded bg-red-600 px-2 py-0.5 text-xs font-bold uppercase text-white">Live</span>

        {{-- Volume --}}
        <button @click="toggleMute()" class="text-white/80 hover:text-white">
            <svg x-show="!muted" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" /></svg>
            <svg x-show="muted" class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 9.75 19.5 12m0 0 2.25 2.25M19.5 12l2.25-2.25M19.5 12l-2.25 2.25m-10.5-6 4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z" /></svg>
        </button>
        <input
            type="range" min="0" max="1" step="0.05"
            :value="muted ? 0 : volume"
            @input="setVolume($event.target.value)"
            class="h-1 w-20 cursor-pointer appearance-none rounded-full bg-white/30 accent-white"
        />

        <div class="flex-1"></div>

        {{-- Fullscreen --}}
        <button @click="toggleFullscreen()" class="text-white/80 hover:text-white">
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" /></svg>
        </button>
    </div>
</div>
```

- [ ] **Step 4: Build and commit**

```bash
npm run build
vendor/bin/pint --dirty
git add resources/js/studio/LivePlayer.js resources/views/components/live-player.blade.php resources/js/app.js
git commit -m "feat: embeddable live player component with volume control"
```

---

### Task 5: Delete Old WebRTC Files + Clean Up

**Files:**
- Delete: `resources/js/studio/PeerManager.js`
- Delete: `resources/js/studio/GuestPeerConnection.js`
- Modify: `resources/js/app.js` (remove lazy loaders)

- [ ] **Step 1: Delete PeerManager.js and GuestPeerConnection.js**

```bash
rm resources/js/studio/PeerManager.js
rm resources/js/studio/GuestPeerConnection.js
```

- [ ] **Step 2: Remove lazy loaders from app.js**

Remove `window.loadPeerManager` and `window.loadGuestPeerConnection` from `resources/js/app.js`.

- [ ] **Step 3: Build and commit**

```bash
npm run build
git add -A
git commit -m "chore: delete PeerManager and GuestPeerConnection — replaced by LiveKit"
```

---

### Task 6: Integration Testing

**Files:**
- Modify: `tests/Feature/LiveKitGuestTest.php`
- Modify: `tests/Feature/LiveEventProductionTest.php`

- [ ] **Step 1: Add integration tests**

```php
// Add to tests/Feature/LiveKitGuestTest.php

it('manage page loads without PeerManager references', function () {
    $user = \App\Models\User::factory()->create(['type' => 'internal']);
    $event = LiveEvent::factory()->create(['status' => 'live', 'host_user_id' => $user->id]);

    \Livewire\Livewire::actingAs($user)
        ->test('pages::events.manage', ['liveEvent' => $event])
        ->assertDontSee('PeerManager')
        ->assertStatus(200);
});

it('guest room page loads without GuestPeerConnection references', function () {
    $event = LiveEvent::factory()->create(['status' => 'live']);
    $guest = LiveEventGuest::factory()->create(['live_event_id' => $event->id]);

    \Livewire\Livewire::test('pages::events.guest-room', ['token' => $guest->token])
        ->assertDontSee('GuestPeerConnection')
        ->assertStatus(200);
});

it('bringGuestOn calls LiveKit grant publish', function () {
    $user = \App\Models\User::factory()->create(['type' => 'internal']);
    $event = LiveEvent::factory()->create(['status' => 'live', 'host_user_id' => $user->id, 'livekit_room_name' => 'test-room']);
    $guest = LiveEventGuest::factory()->create(['live_event_id' => $event->id, 'status' => 'connected']);

    // Mock LiveKitService to avoid real API call
    $mock = \Mockery::mock(LiveKitService::class)->makePartial();
    $mock->shouldReceive('grantPublish')->once();
    app()->instance(LiveKitService::class, $mock);

    \Livewire\Livewire::actingAs($user)
        ->test('pages::events.manage', ['liveEvent' => $event])
        ->call('bringGuestOn', $guest->id);

    expect($guest->fresh()->status)->toBe('live');
});

it('takeGuestOff calls LiveKit revoke publish', function () {
    $user = \App\Models\User::factory()->create(['type' => 'internal']);
    $event = LiveEvent::factory()->create(['status' => 'live', 'host_user_id' => $user->id, 'livekit_room_name' => 'test-room']);
    $guest = LiveEventGuest::factory()->create(['live_event_id' => $event->id, 'status' => 'live']);

    $mock = \Mockery::mock(LiveKitService::class)->makePartial();
    $mock->shouldReceive('revokePublish')->once();
    app()->instance(LiveKitService::class, $mock);

    \Livewire\Livewire::actingAs($user)
        ->test('pages::events.manage', ['liveEvent' => $event])
        ->call('takeGuestOff', $guest->id);

    expect($guest->fresh()->status)->not->toBe('live');
});

it('live player component renders for live event', function () {
    $event = LiveEvent::factory()->create(['status' => 'live', 'livekit_room_name' => 'test-room']);

    $view = \Illuminate\Support\Facades\Blade::render('<x-live-player :event="$event" />', ['event' => $event]);
    expect($view)->toContain('x-ref="video"');
    expect($view)->toContain('Live');
});
```

- [ ] **Step 2: Run all tests**

```bash
php artisan test --compact --filter=LiveKitGuestTest
php artisan test --compact --filter=LiveEvent
```

- [ ] **Step 3: Final pint and commit**

```bash
vendor/bin/pint --dirty
git add tests/
git commit -m "test: integration tests for LiveKit-native guest management"
```

---

## Verification

After all tasks:

```bash
# Run all tests
php artisan test --compact --filter=LiveEvent
npm run build

# Deploy to production
git push origin main
ssh forge@paintingmadeeasydenver.com "cd ~/paintingmadeeasydenver.com && git pull && npm run build"

# Manual testing:
# 1. Create event → go live → verify host camera in compositor
# 2. Invite guest → guest opens link → verify guest appears in participant list (real-time)
# 3. Click "Bring On" → verify guest camera appears as source (no whispers needed)
# 4. Click "Take Off" → verify guest camera disappears instantly
# 5. Guest refreshes page → verify guest reconnects automatically
# 6. Embed <x-live-player> on a page → verify video plays with volume control
# 7. Add text overlay → verify it renders on program canvas
# 8. Switch scenes → verify TAKE works
# 9. Save template → verify it loads correctly
# 10. 5 guests + 20 viewers → verify performance
```

## Task Dependency Graph

```
Task 1 (LiveKitService) ──┐
                           ├──→ Task 2 (Host page) ──┐
                           │                          ├──→ Task 5 (Delete old files) ──→ Task 6 (Tests)
                           └──→ Task 3 (Guest page) ──┘
                                                      │
Task 4 (Viewer player) ───────────────────────────────┘
```

**Parallelizable:** Tasks 2+3 after Task 1. Task 4 is fully independent.
