# Research: Google Calendar Integration for Life Commitments
*Generated: 2026-02-06*

## Requirements

Push recurring "life commitment" events (daily, weekly, monthly, quarterly, annual) to a user's Google Calendar with minimal setup friction. Ideally the user just clicks a button or pastes a URL -- no Google Cloud Console, no OAuth consent screen, no service account JSON key.

## Approaches Evaluated

---

### Approach 1: App-Hosted iCal Feed (User Subscribes via URL)

**How it works**: The Laravel app serves a `.ics` endpoint (e.g., `https://app.com/calendar/{token}.ics`) that dynamically generates an iCalendar file with all life commitments as recurring VEVENT entries. The user subscribes to this URL in Google Calendar via "Other calendars > From URL."

**User Setup**: Click a "Subscribe" button in the app, copy the URL, paste into Google Calendar (or use a one-click `webcal://` link).

**One-Click Subscribe URL**: `https://calendar.google.com/calendar/r?cid=webcal://apexnegotiations.com/calendar/{token}.ics`

**Package**: `spatie/icalendar-generator` v3.2.1
- PHP ^8.1 (compatible with our 8.3)
- 6.7M+ downloads, actively maintained (last release 2026-01-05)
- Full RRULE support for recurring events
- Supports `REFRESH-INTERVAL` property
- 675 GitHub stars

**Pros**:
- Zero Google API credentials needed -- no OAuth, no service account, no API key
- User setup is ~30 seconds (click link or paste URL)
- App controls the data -- update commitments in the app, the feed reflects changes automatically
- Works with Google Calendar, Apple Calendar, Outlook, any iCal-compatible client
- Supports all recurrence patterns (daily, weekly, monthly, yearly) via RRULE
- Per-user token provides security without auth complexity
- One well-maintained Composer package (`spatie/icalendar-generator`)
- Can include event descriptions, reminders/alarms, categories

**Cons**:
- **Google Calendar refresh delay**: Google polls subscribed feeds only every 12-24 hours (sometimes up to 48 hours). Changes are NOT instant.
- Read-only in Google Calendar -- user cannot edit events from within Google Calendar (edits must be made in the app)
- Google Calendar does not respect the `REFRESH-INTERVAL` property reliably
- No guaranteed delivery -- Google controls the polling schedule
- Workaround for forced refresh exists (unsubscribe + resubscribe with `#1` appended) but is manual

**Laravel Implementation Sketch**:
```php
// Route: /calendar/{token}.ics
Route::get('/calendar/{token}.ics', function (string $token) {
    $user = User::where('calendar_token', $token)->firstOrFail();
    $commitments = $user->company->companySetting->lifeCommitments;

    $calendar = Calendar::create('Life Commitments')
        ->refreshInterval(60); // suggest 60-minute refresh (Google may ignore)

    foreach ($commitments as $commitment) {
        $event = Event::create($commitment->commitment)
            ->description("Area: {$commitment->area} | Type: {$commitment->action_type}")
            ->startsAt(now()->startOfDay())
            ->rrule($this->buildRrule($commitment->action_type));
        $calendar->event($event);
    }

    return response($calendar->get())
        ->header('Content-Type', 'text/calendar; charset=utf-8');
});
```

**Verdict**: BEST OPTION for this use case. The 12-24hr delay is acceptable because life commitments are stable recurring events that rarely change.

---

### Approach 2: "Add to Calendar" URL Links (Per-Event)

**How it works**: Generate a Google Calendar URL for each life commitment that, when clicked, opens Google Calendar's "create event" screen pre-filled with all details including recurrence.

**Base URL**: `https://calendar.google.com/calendar/render?action=TEMPLATE`

**Parameters**:
- `text` -- event title
- `dates` -- start/end in `YYYYMMDDTHHmmss/YYYYMMDDTHHmmss` format
- `details` -- description (supports HTML subset)
- `location` -- venue
- `recur` -- RRULE string (e.g., `RRULE:FREQ=WEEKLY;BYDAY=MO`)

**Example URL**:
```
https://calendar.google.com/calendar/render?action=TEMPLATE
  &text=Weekly+Team+Meeting
  &dates=20260210T090000/20260210T100000
  &recur=RRULE:FREQ=WEEKLY;BYDAY=MO
  &details=Life+commitment:+Team+sync
```

**Pros**:
- Zero server infrastructure needed -- just URL generation
- Events are "real" Google Calendar events (editable, send reminders, etc.)
- Instant -- event appears immediately when user clicks
- Supports recurring events via `recur` parameter with RRULE
- No packages needed, pure URL construction
- Works on desktop and mobile

**Cons**:
- Requires one click per commitment (not batch-friendly with 20+ items)
- User must be logged into Google Calendar
- This is an **undocumented** Google feature -- could break without notice
- No sync -- if commitments change in the app, user must manually re-add
- No way to programmatically update or delete events after creation
- If user has many commitments, the UX is tedious (click, confirm, click, confirm...)

**Package**: `spatie/calendar-links` v1.x (stable)
- 6.7M+ downloads
- Note: v2.0 is alpha and recurring event support is limited
- Can generate Google Calendar URLs, Outlook URLs, and .ics downloads

**Verdict**: Good as a SUPPLEMENT to Approach 1 (e.g., "Add this single event to your calendar" button) but not practical as the primary method for bulk recurring commitments.

---

### Approach 3: Google Calendar API with OAuth 2.0

**How it works**: Full Google Calendar API integration. User authorizes via OAuth consent screen, app gets access token, app creates/updates/deletes events programmatically on the user's actual Google Calendar.

**Pros**:
- Full read/write access to user's calendar
- Real-time updates -- changes reflect immediately
- Can create, update, delete individual events
- Events are "real" calendar events (editable, reminders, color-coded)
- Bidirectional sync possible
- Official, supported API

**Cons**:
- **Requires Google Cloud Console project setup** (OAuth client ID, consent screen)
- **OAuth consent screen requires Google verification** for production (takes days/weeks)
- User must click through Google OAuth flow (permission popup)
- Must handle token refresh, storage, and expiration
- Complex implementation (token management, API error handling, rate limits)
- Google's OAuth verification process requires privacy policy, terms of service
- If tokens expire and user doesn't re-auth, events stop syncing

**Package**: `spatie/laravel-google-calendar` or `google/apiclient`

**Verdict**: The most powerful option but violates the "minimal setup" requirement. This is the "too complex" option the user wants to avoid.

---

### Approach 4: Google Calendar API with Service Account

**How it works**: Create a Google Cloud service account, share the user's calendar with the service account email, then the app can create events on the shared calendar without user OAuth.

**Pros**:
- No per-user OAuth flow
- Server-to-server auth
- Full API access

**Cons**:
- **Requires service account JSON key file** (the other "too complex" option)
- User must manually share their calendar with a service account email
- Requires Google Cloud Console setup
- Service account creates events as itself, not as the user
- Confusing UX: "Share your calendar with this email: my-app@my-project.iam.gserviceaccount.com"

**Verdict**: Explicitly ruled out by the user's requirements.

---

### Approach 5: CalDAV Protocol

**How it works**: Use the CalDAV (WebDAV extension for calendars) protocol to push events to Google Calendar's CalDAV endpoint.

**Google CalDAV Endpoint**: `https://apidata.googleusercontent.com/caldav/v2/`

**Pros**:
- Standard protocol supported by many calendar servers
- Can create, update, delete events

**Cons**:
- **Google requires OAuth 2.0 for CalDAV authentication** -- same as Approach 3
- Basic auth / password auth was deprecated and removed by Google
- No simpler than using the REST API directly
- Less documentation and tooling compared to the REST API
- Essentially the same complexity as OAuth with worse developer experience

**Verdict**: Eliminated. Google's CalDAV requires OAuth 2.0, so this offers no advantage over the REST API approach.

---

### Approach 6: Google Calendar API with API Key Only

**How it works**: Use a simple API key (no OAuth) to access the Google Calendar API.

**Pros**:
- Simpler than OAuth

**Cons**:
- **API keys only work for reading PUBLIC calendars** -- cannot write events
- Cannot access private user calendars at all
- Completely useless for our use case (we need to push events)

**Verdict**: Eliminated. API keys are read-only for public calendars. Cannot write events.

---

### Approach 7: .ics File Download (One-Time Import)

**How it works**: Generate a `.ics` file that the user downloads and imports into Google Calendar.

**Pros**:
- Simple to implement
- Events appear immediately after import

**Cons**:
- One-time import only -- no ongoing sync
- If commitments change, user must re-download and re-import
- Duplicate events on re-import (Google doesn't de-duplicate well)
- Poor UX for anything that changes

**Verdict**: Eliminated. No ongoing sync makes this impractical.

---

## Recommendation

### Winner: Approach 1 -- App-Hosted iCal Feed + Approach 2 as Supplement

**Primary Method**: Host a per-user `.ics` feed endpoint that Google Calendar subscribes to. This is the industry-standard approach used by virtually every web application that integrates with external calendars (Trello, Asana, Todoist, etc.).

**Supplementary**: Offer "Add to Google Calendar" links for individual events as a convenience feature.

### Why This Is the Right Choice

1. **Zero external dependencies**: No Google Cloud Console, no OAuth, no API keys, no service account JSON
2. **Universal compatibility**: Works with Google Calendar, Apple Calendar, Outlook, and any standards-compliant calendar app
3. **Minimal user friction**: One-click subscribe via `webcal://` link or paste a URL
4. **App maintains control**: Update commitments in the app, the feed updates automatically
5. **Security via token**: Per-user unique token prevents unauthorized access
6. **Battle-tested approach**: This is how Trello, GitHub, Todoist, Jira, and thousands of other apps do calendar integration
7. **The 12-24hr delay is a non-issue**: Life commitments are recurring events set up once. They rarely change. The initial sync captures all events, and any future changes propagate within a day.

### Recommended Package

**`spatie/icalendar-generator`** v3.2.1
- Version: 3.2.1 (released 2026-01-05)
- PHP: ^8.1 (our app uses 8.3 -- compatible)
- Laravel 12 Compatible: Yes (no Laravel-specific dependencies)
- Livewire 4 Compatible: N/A (backend-only package)
- GitHub Stars: 675
- Downloads: 6.7M+
- Maintenance: Active (last release ~1 month ago)

### Installation

```bash
composer require spatie/icalendar-generator
```

### Implementation Plan

1. **Add `calendar_token` to users table** (or company_settings) -- a unique UUID per user
2. **Create route**: `GET /calendar/{token}.ics` -- serves the iCal feed
3. **Create controller/service** that:
   - Validates the token
   - Fetches life commitments for that user's company
   - Maps `action_type` to RRULE frequency:
     - `daily` -> `FREQ=DAILY`
     - `weekly` -> `FREQ=WEEKLY`
     - `monthly` -> `FREQ=MONTHLY`
     - `quarterly` -> `FREQ=MONTHLY;INTERVAL=3`
     - `annual` -> `FREQ=YEARLY`
   - Returns `text/calendar` response
4. **Add UI button** in the life commitments page:
   - "Subscribe to Calendar" button that shows the URL or opens `webcal://` link
   - Copy-to-clipboard for the URL
   - Instructions for Google Calendar / Apple Calendar / Outlook
5. **Optional**: Add individual "Add to Google Calendar" links per commitment

### User Experience Flow

```
User clicks "Subscribe to Calendar"
  -> Modal shows:
     [Copy Feed URL]  https://apexnegotiations.com/calendar/abc123.ics
     [Add to Google Calendar]  (one-click link)
     [Add to Apple Calendar]   (webcal:// link)
     [Add to Outlook]          (one-click link)
  -> User clicks "Add to Google Calendar"
  -> Google Calendar opens, confirms subscription
  -> All life commitments appear as recurring events
  -> Done. Future changes sync within 12-24 hours.
```

### RRULE Mapping Reference

| Life Commitment Type | RRULE                        | Example                        |
|---------------------|------------------------------|--------------------------------|
| Daily               | `FREQ=DAILY`                 | Every day                      |
| Weekly              | `FREQ=WEEKLY;BYDAY=MO`      | Every Monday                   |
| Monthly             | `FREQ=MONTHLY;BYMONTHDAY=1` | 1st of every month             |
| Quarterly           | `FREQ=MONTHLY;INTERVAL=3`   | Every 3 months                 |
| Annual              | `FREQ=YEARLY;BYMONTH=1`     | Every January                  |

### One-Click Subscribe URLs

```
Google Calendar:
https://calendar.google.com/calendar/r?cid=webcal://apexnegotiations.com/calendar/{token}.ics

Apple Calendar (desktop/iOS):
webcal://apexnegotiations.com/calendar/{token}.ics

Outlook Web:
https://outlook.office.com/calendar/0/addfromweb?url=webcal://apexnegotiations.com/calendar/{token}.ics
```
