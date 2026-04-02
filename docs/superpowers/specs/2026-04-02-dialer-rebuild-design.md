# Prospector Dialer Rebuild — Persistent Conference Architecture

**Date**: 2026-04-02
**Status**: Design approved, pending implementation plan

## Problem

The current dialer creates a new conference and WebRTC connection for every call. This causes race conditions (agent vs contact joining timing), fragile state management, and frequent breakage. The architecture does not match how production power dialers (Hot Prospector, PhoneBurner) work.

Additionally, the multi-Twilio-account setup prevents cross-company dialing within a single session, and there is no timezone-aware calling, no automated list progression, and no auto-dial capability.

## Solution

Rebuild around the **persistent conference model**: agent connects to one conference at session start and stays connected. Contacts are dialed in and out via the Twilio Participants API. Combined with campaign-driven lists with call rules, local presence caller ID, and timezone filtering.

---

## Architecture

### Single Twilio Account

All phone numbers live on one paid Twilio account. Companies are logical groupings in the database. Conference works with any number on the account — caller ID changes per call via the `from` parameter on the Participants API.

A2P 10DLC registration (SMS compliance) is handled per company/brand on the same account. Voice calls do not require A2P.

### Persistent Conference Flow

```
SESSION START:
  Agent selects campaign → clicks Play
  → DialerSession created
  → device.connect({ conference: 'agent-{id}-session-{ulid}' })
  → Webhook returns <Conference> TwiML
  → Agent is in the room. Stays there.

AUTO-DIAL LOOP:
  Server picks next callable contact from queue
  → Checks: timezone OK? calls_today < max? min_minutes elapsed?
  → Selects from-number (local presence or company main)
  → Participants API: dial contact INTO agent's conference
  → Contact answers → instant audio (agent already in room)
  → Call ends → disposition → update campaign_contact counters
  → Check exhaustion → move to next campaign if needed
  → Next contact dialed into SAME conference

NO ANSWER / VOICEMAIL:
  → AMD detects machine or timeout
  → Optional auto VM drop
  → Update counters → auto-advance immediately

INBOUND INTERRUPT:
  → Pause auto-dial
  → If between calls: route inbound INTO agent's conference
  → If on a call: queue as priority, handle after current call
  → Inbound ends → resume auto-dial

PAUSE:
  → Agent clicks Stop → auto-dial pauses
  → Agent stays in conference, can take manual actions
  → Click Play to resume

SESSION END:
  → Agent goes offline or closes dialer
  → Conference destroyed → DialerSession completed with stats
```

---

## Data Model Changes

### Campaign Table Extensions (prospector_campaigns)

New columns added to existing table:

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `max_calls_per_day` | int | 3 | Per contact per day limit |
| `max_total_attempts` | int | 90 | Lifetime attempts before exhaustion |
| `max_days_in_campaign` | int | 30 | Days before exhaustion |
| `min_minutes_between` | int | 240 | Spacing between calls to same contact (4hrs) |
| `next_campaign_id` | FK (nullable) | null | Where exhausted contacts move |
| `calling_hours_start` | time (nullable) | null | Company override start (e.g., 09:00) |
| `calling_hours_end` | time (nullable) | null | Company override end (e.g., 20:00) |
| `auto_dial` | bool | true | Enable auto-advance between calls |

### CampaignContact Pivot Extensions (prospector_campaign_contact)

New columns added to existing pivot:

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `calls_today` | int | 0 | Resets daily via scheduler |
| `total_attempts` | int | 0 | Lifetime attempts in this campaign |
| `entered_at` | datetime | now | When contact entered campaign |
| `last_called_at` | datetime (nullable) | null | Last call timestamp |
| `next_callable_at` | datetime (nullable) | null | Computed: last_called + min_minutes |
| `exhausted_at` | datetime (nullable) | null | When moved to next campaign |

### Area Code Timezone Lookup

New table or config file mapping ~400 US area codes to timezones. Used to auto-populate `Contact.timezone` on creation.

### Contact Model

Existing `timezone` column is populated automatically from area code on contact creation (if not already set). Can be overridden by address data.

---

## Queue Building

### Query Filters (applied at database level)

```sql
SELECT FROM campaign_contacts
WHERE campaign_id = :selected
  AND status = 'queued'
  AND calls_today < campaign.max_calls_per_day
  AND total_attempts < campaign.max_total_attempts
  AND (next_callable_at IS NULL OR next_callable_at <= NOW)
  AND contact.do_not_call = false
  AND contact is within TCPA window (8am-9pm contact timezone)
  AND contact is within campaign calling_hours (if set)
  AND (claimed_by_agent_id IS NULL OR claimed_by_agent_id = :agent)
```

### Sort Priority

1. **Inbound interrupt** — active inbound call or unread SMS
2. **Priority flagged** — `is_priority = true` (new leads auto-flagged)
3. **Callbacks due** — `contact.follow_up_at <= now`
4. **Least recently called** — stalest first, then contacts not yet called today

### Daily Reset

Scheduler job runs at midnight to reset `calls_today = 0` on all campaign_contacts. Contacts become callable again.

### Exhaustion Rules

After each call, check:
- `total_attempts >= campaign.max_total_attempts` OR
- `days since entered_at >= campaign.max_days_in_campaign`

If exhausted:
- Set `exhausted_at = now`, `status = 'exhausted'`
- If `next_campaign_id` set: create new CampaignContact in next campaign with fresh counters
- If no next campaign: contact stops being called

### Future: Conditions Engine

A `conditions` JSON column on campaigns will allow rule-based progression (disposition triggers, tag-based routing, SMS reply detection). The exhaustion logic above is the first "condition" — JSON conditions extend the same evaluation pattern.

---

## Local Presence

### Number Selection Priority

1. Campaign has specific `phone_number_id` → use that number
2. Campaign has `use_local_presence = true` → match contact's area code from company's number pool
3. No local match → company's `main_phone_number`
4. No main number → any active number assigned to company
5. Nothing available → skip contact, log error, advance

### Implementation

Existing `NumberSelectionService::selectNumber()` handles this. Only change: all numbers must be on the single Twilio account. The `from` parameter changes per call; the API credentials are always the same.

Local presence pool: buy numbers on Twilio with target area codes, assign to company in DB with `role = 'local_presence'`.

---

## Timezone Filtering

### TCPA Floor + Company Override

- **TCPA floor (system-enforced, cannot override)**: No calls before 8am or after 9pm in the contact's local time
- **Company override (tighter only)**: Campaign-level `calling_hours_start` / `calling_hours_end` applied in contact's timezone

### Timezone Derivation

On contact creation, if `timezone` is null:
1. Extract area code from phone number
2. Look up area code → timezone mapping
3. Store on contact record

If contact has address with state/zip, use that for more accurate timezone (future enhancement).

### Queue Integration

Contacts outside their calling window are excluded from the queue query. They reappear when their local time enters the window. No manual intervention needed.

---

## Inbound Call Handling

### During Auto-Dial Session

When an inbound call arrives and the agent is in a persistent conference:

- **Agent between calls** (conference active, no contact): Route inbound caller directly into the agent's conference via Participants API. Instant connection.
- **Agent on a call** (contact in conference): Queue the inbound as priority. When current call ends, inbound is next before auto-dial resumes.

### Inbound Routing

Existing IVR and routing logic (TwilioVoiceController::handleInboundCall, routeToAgent) is unchanged. The only change: instead of creating a new conference for the inbound call, route the caller into the agent's existing persistent conference.

---

## UI Structure

### Layout

```
┌──────────────────────────────────────────────────────────────┐
│ Campaign: [New Leads ▼]  ▶ Play  ⏸ Pause   Auto-dialing... │
│                                                              │
│ Queue (horizontal scroll):                                   │
│ ┌──────────┐ ┌──────────┐ ┌──────────┐                      │
│ │ ● John S │ │ ● Maria  │ │ ● Unknown│  ← scroll →          │
│ │ (303)... │ │ (720)... │ │ (719)... │                      │
│ │ Call 1/3 │ │ Call 2/3 │ │ Call 1/3 │                      │
│ │ PME Den  │ │ Test Co  │ │ PME Den  │                      │
│ └──────────┘ └──────────┘ └──────────┘                      │
├──────────────────────────┬───────────────────────────────────┤
│ Active Call              │ Contact Detail                    │
│                          │                                   │
│ ● Session active 14:32  │ John Smith                        │
│ John Smith               │ (303) 555-1234                   │
│ (303) 555-1234           │ PME Denver                       │
│ Call 2/3 today · 15/90  │                                   │
│                          │ Notes                             │
│ [Mute] [DTMF] [Hold]    │ SMS Conversation                  │
│ [Transfer] [VM] [Hangup]│ Call History                       │
│                          │ Pipeline / Follow-up              │
│ ┌── Disposition ───────┐ │ Booking Links                     │
│ │ Interested │ Callback│ │                                   │
│ │ Not Int.   │ DNC     │ │                                   │
│ └──────────────────────┘ │                                   │
├──────────────────────────┴───────────────────────────────────┤
│ Status: [Available] [Break] [Offline]                        │
└──────────────────────────────────────────────────────────────┘
```

### Queue Cards (Horizontal)

Compact cards showing next 3 contacts, scrollable for full list. First card = next up. Belt advances as contacts are dialed.

Each card shows:
- Company color dot
- Contact name (truncated)
- Phone (area code visible)
- Call step: "Call 1/3 · 8/90"

### Session State Indicator

| State | Display |
|-------|---------|
| Idle | "Select a campaign and press Play" |
| Auto-dialing | "Auto-dialing · New Leads · 12 remaining" |
| Paused | "Paused · Conference active · 00:14:32" |
| On call | "Connected · John Smith · (303) 555-1234 · 01:23" |
| Inbound | "Inbound · (720) 440-2586 · Priority" |

---

## Component Architecture

### Livewire (PHP)

One SFC per convention: `resources/views/pages/prospector/⚡dialer.blade.php`

Livewire handles: session CRUD, campaign selection, queue queries, disposition, contact state, SMS, notes, transfers.

### JavaScript Modules

```
resources/js/softphone.js        → Twilio Device lifecycle (exists, minimal changes)
resources/js/dialer-session.js   → NEW: auto-dial state machine, queue sync, session orchestration
```

`dialer-session.js` manages:
- Auto-dial loop (timer between calls, AMD handling)
- Queue state sync (poll or Echo-driven)
- Session state machine (idle → dialing → on_call → disposition → dialing)
- Coordination between SoftphoneManager events and Livewire methods

### Services (PHP)

| Service | Status | Changes |
|---------|--------|---------|
| ConferenceService | Reuse | Minor: add method to route inbound into existing conference |
| NumberSelectionService | Reuse | None — already supports local presence |
| SmartQueueService | Rewrite | Campaign-driven query with call rules, timezone filter |
| PowerDialerService | Rewrite | Persistent conference session, auto-dial orchestration |
| AgentStateService | Reuse | None |
| TcpaComplianceService | Extend | Add timezone-based calling window check |
| ParallelDialerService | Defer | Not in initial rebuild (future enhancement) |

### Webhook (TwilioVoiceController)

`handleIncoming()` stays simple: reads `conference` param, returns `<Conference>` TwiML. Called once at session start. No orchestration.

---

## Key Files

| File | Action |
|------|--------|
| `resources/views/pages/prospector/⚡dialer.blade.php` | Rebuild |
| `resources/js/dialer-session.js` | Create |
| `resources/js/softphone.js` | Minor updates |
| `app/Services/Prospector/PowerDialerService.php` | Rewrite |
| `app/Services/Prospector/SmartQueueService.php` | Rewrite |
| `app/Http/Controllers/Prospector/TwilioVoiceController.php` | Simplify |
| `database/migrations/xxxx_add_call_rules_to_campaigns.php` | Create |
| `database/migrations/xxxx_add_tracking_to_campaign_contact.php` | Create |
| `database/migrations/xxxx_create_area_code_timezones.php` | Create |
| `config/prospector-timezones.php` or seeder | Create |

---

## What's NOT In This Rebuild

- Parallel dialing (3-line mode) — future enhancement
- AI call intelligence integration — exists separately, unchanged
- Drip sequences — exists separately, triggered by disposition
- Supervisor listen/whisper/barge — exists, works with persistent conference
- Call recording — exists, unchanged
- RVM drops — exists, works within conference model
