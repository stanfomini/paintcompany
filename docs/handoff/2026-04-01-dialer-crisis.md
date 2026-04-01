# Dialer Crisis Handoff — 2026-04-01

## What Happened

The dialer softphone was working at the start of this session. During IVR/queue feature work, 20+ commits were made to the dialer file throughout the day by multiple agents. These progressively broke the phone. Attempts to fix it made it worse. A "rewrite" was attempted that introduced a new architecture (SoftphoneManager + webhook orchestrator) but this also doesn't work correctly yet.

## Current State (BROKEN)

- **Production server**: `paintingmadeeasydenver.com` (SSH: `forge@paintingmadeeasydenver.com`)
- **Production code path**: `/home/forge/paintingmadeeasydenver.com`
- **Dev server/repo**: `apexnegotiations.com` at `/home/forge/apexnegotiations.com`
- **Current HEAD**: `cd18f584`
- **LOG_LEVEL on production**: `error` (only `Log::error` shows in logs)

### What's deployed now (the rewrite attempt):
1. `resources/js/softphone.js` — NEW SoftphoneManager class (standalone Twilio Device manager)
2. `resources/js/app.js` — `window.loadSoftphone` lazy loader added
3. `app/Http/Controllers/Prospector/TwilioVoiceController.php` — `handleIncoming()` rewritten as outbound call orchestrator. When `device.connect({ params: { To, contactId } })` fires, the webhook creates the Call record, returns `<Conference>` TwiML, and synchronously dials the contact via Participants API.
4. `resources/views/pages/prospector/⚡dialer.blade.php` — Inline Twilio code replaced with SoftphoneManager. `dialContact()` and `dialNext()` now return `{ phone, contactId, companyId }` arrays instead of orchestrating calls directly. Alpine calls `softphone.dial()` which triggers the webhook.
5. `app/Jobs/Prospector/DialContactIntoConferenceJob.php` — Created but NOT currently used (switched back to synchronous)

### Current symptoms:
- Phone initializes (shows ready)
- Agent clicks Dial → contact's phone rings → contact answers
- Contact hears hold music (conference waiting room) — agent never connects
- Dialer UI doesn't update to show "connected" state
- Hang up button doesn't work
- Queue doesn't show there's someone waiting

## The Core Problem

The Twilio call flow requires precise timing:
1. Agent's WebRTC MUST connect to the conference first
2. Contact is dialed into the same conference second
3. When both are in the conference, audio flows

The current code does this synchronously in the webhook — the `dialContactIntoConference()` API call (~1-2 seconds) blocks the TwiML response. By the time TwiML is returned to Twilio, the agent's WebRTC connection may have timed out or the contact is already in the conference waiting.

### What was tried and failed:
- `defer()` — crashes on Swoole/Octane production server
- Queued job — `default` queue worker has `--sleep=10` (10 second delay, way too slow)
- Synchronous — blocks TwiML response, agent may not connect in time

## Last Known Working State

**Commit `67a6e7eb`** (2026-04-01 05:31 UTC) — "per-company phone readiness traffic light"

This commit had the OLD architecture where:
- `dialContact()` in Livewire dispatched `join-conference` and `ensure-voice-company` events
- Alpine `connectToConference()` called `device.connect({ params: { conference: conferenceName } })`
- The webhook `handleIncoming` just read the `conference` param and returned `<Conference>` TwiML
- The Livewire method ALSO called `dialContactIntoConference()` synchronously

This had a race condition (contact could be dialed before agent joins) but it WORKED most of the time because the Livewire events + API calls were fast enough.

## What Was Built Today (Working, Should Keep)

### IVR System (commits `711f71e8` through `3493b524`)
- Per-company dual IVR config (business hours / after hours)
- 4 action types: route_agent, voicemail, play_info, forward
- Business hours check on `Company::getActiveIvrFlow()`
- IVR config UI on company-detail page (tabbed, digit options)
- `InboundCallRinging` event for multi-agent notification
- Agent lookup via pivot table (not just FK)
- Tests: 45 passing

### Multi-Company Queue (same commits)
- "All Companies" select fixed
- Queue sorts by urgent → pipeline stage sort_order → staleness
- Company color dots in queue items
- `SmartQueueService` updated with LEFT JOIN for stage sorting

### AI Call Intelligence Refactor (commits `e24a8291` through `c55cd3ef`)
- Prism replaced with Laravel AI SDK `CallAnalystAgent`
- `AiSetting` model stores provider/model/API key in DB (encrypted)
- AI Intelligence tab on Prospector Settings page
- Auto-trigger: `ProcessCallIntelligenceJob` dispatched on call completion

## Key Files

| File | State | Notes |
|------|-------|-------|
| `resources/js/softphone.js` | NEW (rewrite) | Standalone SoftphoneManager — may be good architecture but not wired correctly |
| `resources/views/pages/prospector/⚡dialer.blade.php` | REWRITTEN | Currently uses SoftphoneManager. May need to revert to `67a6e7eb` version |
| `app/Http/Controllers/Prospector/TwilioVoiceController.php` | MODIFIED | `handleIncoming` is now the orchestrator. `handleInboundCall`/IVR unchanged |
| `app/Services/Prospector/ConferenceService.php` | UNCHANGED | All conference methods still work |
| `app/Services/Prospector/SmartQueueService.php` | MODIFIED (good) | Stage sorting + company color |
| `app/Services/Prospector/CallIntelligenceService.php` | MODIFIED (good) | Uses Laravel AI SDK now |

## Twilio Account Info (Production)

| Account | Name | SID | TwiML App | Notes |
|---------|------|-----|-----------|-------|
| 1 | PME DENVER | AC3df92d... | NONE | No Voice SDK credentials |
| 2 | Marketing Dialer | AC0778d1... | AP2bc12... | Has TwiML App, IS primary, **TRIAL ACCOUNT** |

- TwiML App Voice URL: `https://paintingmadeeasydenver.com/prospector/webhook/twilio/voice/incoming`
- Phone numbers on Account 2: `+18335733773` (main for Company 2)
- **Trial account limitation**: Can only call verified numbers

## Architecture Options for Next Session

### Option A: Revert Dialer to Working State, Keep IVR/Queue/AI
Restore `⚡dialer.blade.php` from commit `67a6e7eb`. Keep the IVR controller changes but restore `handleIncoming()` to the simple version (just reads `conference` param). Accept the race condition — it worked well enough.

```bash
git show 67a6e7eb:"resources/views/pages/prospector/⚡dialer.blade.php" > "resources/views/pages/prospector/⚡dialer.blade.php"
```

Then restore `handleIncoming` to the simple version:
```php
public function handleIncoming(Request $request): Response
{
    $to = $request->input('To', '');
    $conferenceName = $request->input('conference', '');
    $response = new VoiceResponse;
    if ($conferenceName) {
        $dial = $response->dial();
        $dial->conference($conferenceName, [
            'startConferenceOnEnter' => true,
            'endConferenceOnExit' => true,
            'beep' => false,
        ]);
    } elseif (str_starts_with($to, 'client:')) {
        $dial = $response->dial();
        $dial->client(substr($to, 7));
    } else {
        $response->say('No conference specified.');
        $response->hangup();
    }
    return $this->twimlResponse($response);
}
```

### Option B: Fix the SoftphoneManager Approach
The SoftphoneManager architecture is cleaner but needs:
1. A way to dial the contact AFTER the TwiML response (not `defer`, not slow queue)
   - Option: Add a `prospector` queue worker with `--sleep=1` on Forge
   - Option: Use Redis pub/sub to trigger the dial from a separate process
   - Option: Fire the Participants API from JavaScript after `device.connect()` succeeds
2. Proper UI state updates (the Echo events need to flow correctly)
3. The hang up button needs to call `softphone.hangup()` properly

### Option C: Fire Participants API from JavaScript
Instead of the webhook dialing the contact, have the browser do it:
1. `device.connect()` → webhook returns `<Conference>` TwiML → agent in conference
2. Alpine detects the WebRTC connection is established
3. Alpine calls a Livewire method `$wire.dialContactIntoConference(contactId, conferenceName)`
4. Livewire method calls `ConferenceService::dialContactIntoConference()`
5. Contact's phone rings → answers → joins conference

This keeps the webhook simple (just TwiML) and triggers the Participants API from the browser only AFTER the agent is confirmed connected. No race condition, no queue delay.

## Debug Logging Currently Active

There are `Log::error('DEBUG ...')` lines in `TwilioVoiceController::handleIncoming()` and `handleOutboundDial()`. These should be removed once the issue is resolved.

## Production Environment Notes

- **Swoole/Octane**: `defer()` does NOT work — crashes with "API must be called in the coroutine"
- **Queue workers**: No `prospector` queue worker. Use `default` queue or add a new worker via Forge.
- **LOG_LEVEL**: Set to `error` — only `Log::error()` shows in logs
- **Auto-deploy**: Pushes to `main` auto-deploy via Forge
- **npm run build**: Must be run on production after JS changes — Forge deploy script may not include it
