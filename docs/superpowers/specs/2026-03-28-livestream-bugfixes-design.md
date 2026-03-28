# Livestream Events Bugfixes — Design Spec

## Context

Two bugs prevent the livestream events feature from being usable:

1. Events are always created with `status='draft'` (migration default) and never transition to `scheduled`
2. The "Go Live" button in the control room doesn't appear for draft events, so there's no way to start streaming

## Fix 1: Status on Creation

**File:** `app/Services/LiveEventService.php` — `createEvent()`

Set `$data['status'] = 'scheduled'` before `LiveEvent::create()`. Every event created through the form has a `scheduled_at` date, so it should be `scheduled` immediately.

## Fix 2: Go Live Flow

**File:** `resources/views/pages/events/⚡manage.blade.php`

The pre-live state (camera preview + "Go Live" button) must render for both `draft` and `scheduled` status. Currently the template condition likely excludes `draft`.

Verify:
- Template shows pre-live controls for `draft` OR `scheduled`
- `goLive()` Livewire action works (already accepts both statuses in service layer)
- After clicking Go Live, page transitions to live state with video + controls

## Verification

1. Create a new event via `/events/create` — should be `scheduled` status
2. Go to `/events/{event}/manage` — should see camera preview + "Go Live" button
3. Click "Go Live" — stream should start, page shows live controls
4. Open `/live/{slug}` in another browser — viewer should see the stream
5. Click "End Stream" — event transitions to `ended`

## Out of Scope

Recording integration deferred — will be a separate spec when basic livestream is working.
