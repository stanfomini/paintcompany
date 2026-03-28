# Livestream Events — Design Spec

## Context

Apex Negotiations (Painting Made Easy Denver) wants to host live events for neighborhoods and services — e.g., "Highland Spring Home Prep Workshop" or "Exterior Painting Tips for Park Hill." The feature enables livestreaming to up to 50 viewers (initially, expandable to 300+), with live chat, pinned resources, and a Q&A queue.

**Why:** Community engagement, lead generation, and local authority building. Events tie directly into the existing ServiceArea + Service local SEO infrastructure, creating a live content layer on top of the 527 area+service combo pages already built.

**Streaming backbone:** LiveKit (open-source Go-based SFU), self-hosted on the production Digital Ocean server alongside Laravel + Python. Browser-based streaming (no OBS required). WebRTC delivers sub-second latency to all viewers.

**Why LiveKit over Bunny CDN:** Bunny CDN does not support livestreaming (VOD only). LiveKit is a self-hosted Go binary — no per-viewer fees, full control, and efficient enough to run on the existing production server for small audiences.

---

## Data Models

### LiveEvent

| Field | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `title` | string(255) | Event title |
| `slug` | string(255), unique | URL-friendly identifier |
| `description` | text | Full event description |
| `service_area_id` | FK nullable | Links to ServiceArea (neighborhood) |
| `service_id` | FK nullable | Links to Service |
| `host_user_id` | FK | Internal user hosting the event |
| `scheduled_at` | datetime | When the event is scheduled to start |
| `started_at` | datetime nullable | When it actually went live |
| `ended_at` | datetime nullable | When it ended |
| `status` | string | `draft`, `scheduled`, `live`, `ended`, `cancelled` |
| `access_type` | string | `public` or `registration` |
| `max_viewers` | integer, default 50 | Viewer cap |
| `enable_recording` | boolean, default false | Whether to record for replay |
| `livekit_room_name` | string, unique | LiveKit room identifier |
| `recording_path` | string nullable | Path to recording file after event |
| `cover_image` | string nullable | Event banner/thumbnail |
| `chat_thread_id` | FK nullable | Links to ChatThread for event chat |
| `timestamps` | | created_at, updated_at |

**Casts:** `scheduled_at` → datetime, `started_at` → datetime, `ended_at` → datetime, `enable_recording` → boolean

**Relationships:**
- `belongsTo` ServiceArea (nullable)
- `belongsTo` Service (nullable)
- `belongsTo` User (host)
- `belongsTo` ChatThread (nullable)
- `hasMany` LiveEventResource
- `hasMany` LiveEventQuestion
- `hasMany` LiveEventRegistration

**Scopes:** `scopeUpcoming()`, `scopeLive()`, `scopeEnded()`, `scopeForArea($areaId)`, `scopeForService($serviceId)`

### LiveEventResource

| Field | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `live_event_id` | FK | Parent event |
| `title` | string(255) | Resource title |
| `url` | string(500) nullable | External link |
| `description` | text nullable | Brief description |
| `type` | string | `link`, `document`, `offer` |
| `is_pinned` | boolean, default true | Show in sidebar during live |
| `sort_order` | integer, default 0 | Display order |
| `timestamps` | | |

### LiveEventQuestion

| Field | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `live_event_id` | FK | Parent event |
| `viewer_name` | string(255) | Display name of asker |
| `user_id` | FK nullable | If registered user |
| `question` | text | The question |
| `status` | string, default 'pending' | `pending`, `answered`, `dismissed` |
| `answered_at` | datetime nullable | When host addressed it |
| `timestamps` | | |

### LiveEventRegistration

| Field | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `live_event_id` | FK | Parent event |
| `user_id` | FK nullable | If existing user |
| `name` | string(255) | Viewer name |
| `email` | string(255) | For reminders |
| `phone` | string(50) nullable | For SMS reminders |
| `attended` | boolean, default false | Did they show up |
| `timestamps` | created_at doubles as registered_at |

---

## Services

### LiveKitService (`app/Services/LiveKitService.php`)

Wraps the `agence104/livekit-server-sdk-php` SDK.

```
createRoom(LiveEvent $event): void
    → Creates LiveKit room with metadata (event title, max participants)

deleteRoom(string $roomName): void
    → Tears down room when event ends

generateHostToken(LiveEvent $event, User $user): string
    → JWT with roomJoin + canPublish + canSubscribe grants
    → Identity: "host-{user_id}", Name: user display name
    → TTL: 4 hours

generateViewerToken(LiveEvent $event, string $viewerName, ?int $userId = null): string
    → JWT with roomJoin + canSubscribe (NO canPublish) grants
    → Identity: "viewer-{userId}" or "guest-{random}"
    → TTL: 4 hours

getParticipantCount(string $roomName): int
    → Current viewer count via Room API

listParticipants(string $roomName): array
    → List of current participants with metadata

removeParticipant(string $roomName, string $identity): void
    → Kick a viewer (moderation)
```

**Recording** is deferred to Phase 2 (requires LiveKit Egress service, separate process). For v1, recording toggle exists in the UI but shows "Coming soon."

### LiveEventService (`app/Services/LiveEventService.php`)

Business logic for event lifecycle.

```
createEvent(array $data): LiveEvent
    → Creates event + LiveKit room name + ChatThread (type: 'live_event')

goLive(LiveEvent $event): void
    → Creates LiveKit room, updates status to 'live', sets started_at
    → Dispatches LiveEventStarted notification to registered attendees

endEvent(LiveEvent $event): void
    → Updates status to 'ended', sets ended_at
    → Deletes LiveKit room
    → Marks registered attendees as attended/not based on join records

cancelEvent(LiveEvent $event): void
    → Updates status to 'cancelled'
    → Notifies registered attendees

registerViewer(LiveEvent $event, array $data): LiveEventRegistration
    → Creates registration record
    → For existing users, links user_id

submitQuestion(LiveEvent $event, string $question, string $viewerName, ?int $userId): LiveEventQuestion
    → Creates question in pending status
    → Broadcasts to host via Reverb

answerQuestion(LiveEventQuestion $question): void
    → Marks as answered, sets answered_at

dismissQuestion(LiveEventQuestion $question): void
    → Marks as dismissed
```

---

## Configuration

**`.env` additions:**
```env
LIVEKIT_URL=wss://stream.paintingmadeeasydenver.com
LIVEKIT_API_KEY=
LIVEKIT_API_SECRET=
LIVEKIT_MAX_VIEWERS=50
```

**`config/services.php` addition:**
```php
'livekit' => [
    'url' => env('LIVEKIT_URL'),
    'api_key' => env('LIVEKIT_API_KEY'),
    'api_secret' => env('LIVEKIT_API_SECRET'),
    'max_viewers' => (int) env('LIVEKIT_MAX_VIEWERS', 50),
],
```

**Composer:** `agence104/livekit-server-sdk-php`
**NPM:** `livekit-client`

---

## Pages & Components

### Admin Pages (Internal Users Only)

**1. Event Index** — `/events`
- Route: `Route::get('/events', ...)->name('events.index')`
- Component: `resources/views/pages/events/⚡index.blade.php`
- Features: List events, filter by status (upcoming/live/ended), filter by service area. Quick stats (viewer count for live events).

**2. Event Form** — `/events/create` and `/events/{event}/edit`
- Route: `Route::get('/events/create', ...)->name('events.create')`
- Component: `resources/views/pages/events/⚡form.blade.php`
- Fields: title, description, service area (dropdown from ServiceArea model), service (dropdown from Service model), scheduled_at (datetime picker), access_type (public/registration toggle), max_viewers, enable_recording, cover_image upload.
- Auto-generates slug from title.

**3. Event Control Room** — `/events/{event}/manage`
- Route: `Route::get('/events/{event}/manage', ...)->name('events.manage')`
- Component: `resources/views/pages/events/⚡manage.blade.php`
- **Pre-live state:** Preview camera, check settings, "Go Live" button
- **Live state:**
  - Camera preview (your own stream)
  - Viewer count (real-time via LiveKit API polling or Reverb broadcast)
  - Q&A queue: list of pending questions with [Answer] and [Dismiss] buttons
  - Resource manager: add/remove/reorder pinned resources
  - Chat with moderation (delete messages, kick viewers)
  - [End Stream] button with confirmation
- **Post-live state:** Summary stats (peak viewers, questions answered, registrations)

### Public Pages (Viewers)

**4. Live Events Listing** — `/live`
- Route: `Route::get('/live', ...)->name('live.index')`
- Component: `resources/views/pages/public/⚡live-events.blade.php`
- Shows: Upcoming events (with countdown/register), currently live events (with "Watch Now" CTA), past events with recordings.
- Filterable by service area and service.
- No auth required.

**5. Event Page** — `/live/{slug}`
- Route: `Route::get('/live/{slug}', ...)->name('live.show')`
- Component: `resources/views/pages/public/⚡live-event.blade.php`
- **Before event starts:** Event details, countdown timer, register button (if registration required), host bio
- **During live:**
  - LiveKit video player (WebRTC, attached via `livekit-client` JS)
  - Live chat panel (existing ChatThread + Reverb, guest mode for public events)
  - Pinned resources sidebar (links, documents, offers)
  - Q&A submission form ("Ask a question" input)
  - Viewer count badge
- **After event ends:** "Event has ended" message, recording player (if enabled, Phase 2), related upcoming events
- **Access control:**
  - `public`: Enter display name → get viewer token → watch
  - `registration`: Must register (name + email) → get viewer token → watch

**Layout (Desktop):**
```
┌──────────────────────────────────────────────────────┐
│  Event Title                          👥 47 viewers  │
├───────────────────────────┬──────────────────────────┤
│                           │  📌 Resources            │
│                           │  ├─ Free Consultation     │
│    VIDEO PLAYER           │  ├─ Color Guide PDF       │
│    (LiveKit WebRTC)       │  └─ Book Appointment      │
│                           │                          │
│                           │  ❓ Ask a Question        │
│                           │  [________________]       │
├───────────────────────────┤  [Submit]                │
│  💬 Live Chat             │                          │
│  Jane: Love these tips!   │  Answered Questions:     │
│  Mike: What about primer? │  ✓ "Best primer for..."  │
│  [Type a message...]      │                          │
└───────────────────────────┴──────────────────────────┘
```

**Layout (Mobile):** Video full-width top, tabbed bottom panel (Chat | Resources | Q&A).

---

## Real-Time Communication

### Reverb Broadcasting Channels

**Event presence channel** — `live-event.{eventId}`
- Used for: viewer count updates, new questions broadcast, resource updates, chat messages
- Host joins as authenticated user
- Viewers join via guest auth (similar to existing video-call auth pattern)

**Event host channel** — `private-live-event-host.{eventId}`
- Used for: Q&A notifications to host only, moderation alerts
- Only the host user can join

### LiveKit Integration Flow

```
Host clicks "Go Live"
    → Livewire calls LiveEventService::goLive()
    → LiveKitService::createRoom() creates the room
    → LiveKitService::generateHostToken() returns JWT
    → Token passed to Alpine.js component
    → livekit-client connects, publishes camera/mic

Viewer opens /live/{slug}
    → If public: enters display name
    → If registration: must register first
    → Livewire endpoint generates viewer token via LiveKitService::generateViewerToken()
    → Token passed to Alpine.js component
    → livekit-client connects, subscribes to host's tracks
    → Video element attached to DOM

Viewer submits question
    → Livewire calls LiveEventService::submitQuestion()
    → Broadcasts on private-live-event-host.{eventId}
    → Host sees question in Q&A queue

Host clicks "Answer"
    → Livewire calls LiveEventService::answerQuestion()
    → Broadcasts on live-event.{eventId} that question was answered
    → Viewers see it move to "Answered" section
```

---

## ServiceArea / Service Integration

- Event creation form includes dropdowns for ServiceArea and Service (both optional)
- The `/live` listing page supports filtering by area and service
- Area pages (`/areas/{slug}`) show upcoming live events for that area in a new section
- Service pages (`/services/{slug}`) show upcoming live events for that service
- Area+service combo pages (`/areas/{areaSlug}/{serviceSlug}`) show relevant events
- Event pages include Event structured data (JSON-LD) for search engines
- Events with recordings become content on the area/service pages

---

## Notifications

### LiveEventReminder (1 hour before)
- **Channel:** SMS (Twilio) + Email
- **Recipients:** All LiveEventRegistration records for the event
- **Content:** "Your event '{title}' starts in 1 hour! Watch here: {url}"
- **Trigger:** Scheduled command or queued job dispatched at event creation

### LiveEventStarted
- **Channel:** SMS + Email
- **Recipients:** All registered attendees
- **Content:** "'{title}' is LIVE now! Join: {url}"
- **Trigger:** When host clicks "Go Live"

### LiveEventFollowUp (post-event)
- **Channel:** Email
- **Recipients:** All registered attendees (+ anyone who watched if public)
- **Content:** Thank you + recording link (if available) + booking CTA
- **Trigger:** 1 hour after event ends

---

## LiveKit Server Setup (Production)

LiveKit runs as a systemd service on the production server (same as Python service pattern):

1. Download LiveKit binary to `/usr/local/bin/livekit-server`
2. Create config at `/etc/livekit.yaml`
3. Create systemd service `livekit.service` (runs as `forge` user)
4. Configure Nginx reverse proxy for `stream.paintingmadeeasydenver.com`
5. Open UDP ports 50000-60000 for WebRTC media
6. SSL via Let's Encrypt (Forge handles this)

**Config (`/etc/livekit.yaml`):**
```yaml
keys:
    <API_KEY>: <API_SECRET>
port: 7880
rtc:
    port_range_start: 50000
    port_range_end: 60000
    use_external_ip: true
turn:
    enabled: true
    domain: stream.paintingmadeeasydenver.com
    tls_port: 5349
logging:
    level: info
```

**Nginx proxy (stream.paintingmadeeasydenver.com):**
```nginx
server {
    server_name stream.paintingmadeeasydenver.com;

    location / {
        proxy_pass http://127.0.0.1:7880;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

This mirrors the existing Python service deployment pattern. Install happens after all Laravel code is built and tested locally.

---

## Phased Delivery

### Phase 1 (This Spec): Core Livestream Events
- LiveEvent, LiveEventResource, LiveEventQuestion, LiveEventRegistration models + migrations
- LiveKitService + LiveEventService
- Admin: event CRUD, control room (go live, Q&A, resources, chat moderation)
- Public: event listing, event page with video + chat + resources + Q&A
- .env + config for LiveKit
- JS integration with `livekit-client`
- Basic notifications (event started)
- Recording toggle in UI (shows "Coming soon" - actual recording in Phase 2)

### Phase 2 (Future): Recording + Scale
- LiveKit Egress service for recording
- Automatic VOD → existing VideoChannel infrastructure
- Post-event follow-up notifications with recording link
- HLS fallback for 300+ viewers (LiveKit → HLS → Bunny CDN)

### Phase 3 (Future): Advanced Features
- Polls/reactions during stream
- Co-host support (multiple publishers)
- Screen sharing during events
- Recurring event schedules
- Event analytics dashboard

---

## Verification

1. **Unit tests:** LiveKitService token generation, LiveEventService lifecycle methods
2. **Feature tests:** Event CRUD, registration flow, access control (public vs registration)
3. **Browser test:** Full flow — create event → go live → viewer joins → chat → Q&A → end
4. **LiveKit integration:** Test locally with LiveKit dev server (`livekit-server --dev`)
5. **Chat:** Verify ChatThread creation, guest messaging, moderation
6. **Mobile:** Responsive layout — video + tabbed panel on small screens
