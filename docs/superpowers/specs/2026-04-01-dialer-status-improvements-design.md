# Dialer Status Improvements Design

## Context

The Prospector dialer has several status-related issues:
1. The softphone status bar shows "Phone Ready" regardless of which company is selected, even when that company lacks credentials
2. After a call ends, the agent status stays on "On Call" until manually changed — causing missed inbound routing
3. There's no "Nearby" status for agents who are queue-eligible but not actively power-dialing
4. The wrap-up timer (30s default) is hardcoded and not visible to the agent

## 1. Per-Company Phone Status (Traffic Light)

### Behavior

The softphone status bar becomes company-aware. When the agent switches the company filter dropdown, the bar re-evaluates that company's readiness and displays a traffic-light color.

**Green — Fully Ready**
- Active Twilio account with API Key SID + Secret
- TwiML App SID configured
- At least one active phone number
- SMS capability on at least one number

**Amber — Partially Ready**
- Voice works but SMS not available (no A2P, no SMS-capable number)
- Or: missing TwiML App SID (can receive calls via incoming handler but outbound WebRTC may have issues)

**Red — Not Functional**
- Missing API Key SID or Secret (cannot generate voice tokens)
- No active Twilio account linked to the company
- No active phone numbers

**When "All Companies" selected (no filter):** Show status based on the company that was used for the current voice token (`window.__twilio.companyId`). If no token yet, show amber "Select a company".

### UI

The status bar dot and border color reflect the traffic light. A chevron button toggles an expandable checklist panel:

```
[●] Phone Ready ▼                              [Auto] [30s ▼]
─────────────────────────────────────────────────────────────
  ✓ Voice SDK credentials
  ✓ TwiML App configured
  ✓ Active phone number (+1 720-999-9725)
  ✗ SMS not available — A2P pending         [Go to Settings →]
```

Each line: green checkmark or red X, with label. Items missing show an actionable "Go to Settings" link.

### Data Source

Create a Livewire computed property `phoneReadiness` that returns an array:

```php
#[Computed]
public function phoneReadiness(): array
{
    $companyId = $this->filterCompanyId ?: $this->agent?->company_id;
    $company = Company::with('twilioAccount', 'phoneNumbers')->find($companyId);
    // ... check each capability, return structured array
}
```

Fields returned: `status` (green/amber/red), `label` (summary text), `checks` (array of name/ok/detail items).

Recompute when `filterCompanyId` changes (already watched).

### initTwilio Response Enhancement

The `/prospector/voice/token` endpoint already returns errors when credentials are missing. The Alpine `initTwilio()` already sets `phoneError` on failure. The new computed property provides the checklist detail alongside the existing error/ready state.

## 2. "Nearby" Agent Status

### Definition

`nearby` is a new 7th agent status meaning: "I'm not actively dialing but I'm available for inbound calls and queue assignments."

### Status List (updated)

| Status | Manual? | Receives Inbound | In Queue | Auto-Dialed | Color |
|--------|---------|-------------------|----------|-------------|-------|
| `available` | Yes | Yes | Yes | Yes | Green |
| `nearby` | Yes | Yes | Yes | No | Yellow/Amber |
| `on_call` | Auto | No | No | No | Blue |
| `ringing` | Auto | No | No | No | Amber pulse |
| `wrap_up` | Auto | No | No | No | Purple |
| `break` | Yes | No | No | No | Orange |
| `offline` | Yes | No | No | No | Gray |

### Code Changes

**AgentStateService**: Add `nearby` to valid statuses array.

**Agent model**: Add `goNearby()` method.

**Dialer Livewire `setAgentStatus()`**: Add `nearby` to allowed manual statuses: `['available', 'nearby', 'break', 'offline']`.

**TwilioVoiceController `routeToAgent()`**: Change agent query from:
```php
->where('status', 'available')
```
to:
```php
->whereIn('status', ['available', 'nearby'])
```

**SmartQueueService**: If it filters by agent availability, include `nearby`.

**PowerDialerService `dialForAgent()`**: Only dial for agents with `status === 'available'` (NOT nearby). This is the key distinction.

### Pre-Call Status Tracking

Track what status the agent was in before a call started, so wrap-up returns them to the correct status.

Add a Livewire property `preCallStatus` (string, nullable). Set it when a call begins:

```php
// In dialContact(), dialNext(), onInboundCallRinging():
$this->preCallStatus = $this->agentStatus; // 'available' or 'nearby'
```

After wrap-up completes, restore:
```php
$returnStatus = $this->preCallStatus ?: 'available';
$this->preCallStatus = null;
```

### UI

Agent status bar gains a 4th button:

```
Status: [Available] [Nearby] [Break] [Offline]    On Call ●
```

- Nearby button: amber/yellow background when active
- Badge text changes: "Nearby" shown as amber badge when in that status

## 3. Wrap-Up Timer with Agent Control

### Agent-Configurable Duration

A dropdown in the softphone status bar area lets the agent choose their wrap-up time: `15s | 30s | 45s | 60s | 90s | 120s`.

Saved to `agent.settings.wrap_up_seconds` (same JSON pattern as `auto_answer_inbound`). Loaded in `mount()`, persisted on change via a `setWrapUpSeconds()` Livewire method.

### Visible Countdown

When `callStatus === 'ended'` (wrap-up/disposition phase), the call controls area shows:

```
        ⏱ Wrap Up — 23s
   [Submit Disposition ▼]
   [Go Available Now →]
```

The countdown is an Alpine timer that decrements from `wrapUpSeconds`. When it hits 0, the agent auto-transitions to available/nearby.

### Alpine Implementation

```javascript
wrapUpRemaining: 0,
wrapUpInterval: null,

startWrapUpTimer() {
    this.wrapUpRemaining = parseInt($wire.wrapUpSeconds);
    this.wrapUpInterval = setInterval(() => {
        this.wrapUpRemaining--;
        if (this.wrapUpRemaining <= 0) {
            clearInterval(this.wrapUpInterval);
            $wire.completeWrapUp();
        }
    }, 1000);
},

cancelWrapUpTimer() {
    if (this.wrapUpInterval) {
        clearInterval(this.wrapUpInterval);
        this.wrapUpInterval = null;
    }
    this.wrapUpRemaining = 0;
},
```

### Livewire Methods

```php
public function completeWrapUp(): void
{
    $returnStatus = $this->preCallStatus ?: 'available';
    $this->preCallStatus = null;
    $agent = Agent::findOrFail($this->agentId);
    app(AgentStateService::class)->setStatus($agent, $returnStatus);
    $this->agentStatus = $returnStatus;
}

public function setWrapUpSeconds(int $seconds): void
{
    $allowed = [15, 30, 45, 60, 90, 120];
    if (! in_array($seconds, $allowed)) return;
    $this->wrapUpSeconds = $seconds;
    $agent = Agent::findOrFail($this->agentId);
    $settings = $agent->settings ?? [];
    $settings['wrap_up_seconds'] = $seconds;
    $agent->update(['settings' => $settings]);
}
```

## 4. Auto Status Transitions

### Complete Status Flow

```
Available/Nearby
    ↓ [call starts - dialContact/dialNext/inbound]
    → preCallStatus saved
    → agentStatus = 'ringing'
    ↓ [call connected - onCallConnected or auto-accept]
    → agentStatus = 'on_call'
    ↓ [call ends - endCall/onCallEnded]
    → agentStatus = 'wrap_up'
    → wrap-up timer starts (visible countdown)
    ↓ [disposition submitted OR timer expires]
    → agentStatus = preCallStatus ('available' or 'nearby')
    → preCallStatus cleared
```

### Where Transitions Happen

**Call starts** (Livewire `dialContact()`, `dialNext()`, `onInboundCallRinging()`):
- Save `preCallStatus`
- Set `agentStatus = 'ringing'`
- Call `AgentStateService::setStatus($agent, 'ringing')` — broadcasts to Echo

**Call connected** (`onCallConnected()`):
- Set `agentStatus = 'on_call'`
- Call `AgentStateService::setStatus($agent, 'on_call')` — broadcasts

**Call ends** (`endCall()`, `onCallEnded()`):
- Set `agentStatus = 'wrap_up'`
- Call `AgentStateService::setStatus($agent, 'wrap_up')` — broadcasts
- Start Alpine wrap-up timer

**Disposition submitted** (`submitDisposition()`):
- Cancel wrap-up timer
- Call `completeWrapUp()` → restores to available/nearby
- Broadcasts status change

**Timer expires** (Alpine countdown hits 0):
- Call `$wire.completeWrapUp()` → restores to available/nearby

### Remove Conflicting Logic

**Remove `transitionToWrapUp()` delayed job**: The current `AgentStateService::transitionToWrapUp()` dispatches a delayed job that auto-transitions after `wrap_up_seconds`. This is now handled client-side by the Alpine timer. The delayed job is redundant and could conflict (e.g., transitioning to available while the agent is still dispositioning). Remove the delayed job dispatch from `dispositionCall()`.

**Remove `dialNext()` call from `submitDisposition()`**: Currently line 903 calls `$this->dialNext()` immediately after disposition. This fights with the wrap-up flow. Remove it — the agent returns to available/nearby and decides when to dial next.

### Server-Side Safety Net

Keep a server-side safety net: if an agent has been in `wrap_up` for more than 5 minutes (browser crash, tab closed), a scheduled command or the next `mount()` should check and reset to `available`. This prevents agents getting permanently stuck in `wrap_up`.

## Files to Modify

| File | Changes |
|------|---------|
| `resources/views/pages/prospector/⚡dialer.blade.php` | All UI changes, new properties, new methods, Alpine timer |
| `app/Services/Prospector/AgentStateService.php` | Add `nearby` to valid statuses, remove delayed job from transitionToWrapUp |
| `app/Models/Prospector/Agent.php` | Add `goNearby()` method |
| `app/Http/Controllers/Prospector/TwilioVoiceController.php` | `routeToAgent()` query includes `nearby` |
| `app/Services/Prospector/PowerDialerService.php` | Remove auto-dialNext from disposition, keep `available`-only for auto-dial |

## Verification

1. Switch company filter → status bar color and checklist update correctly
2. Set status to Nearby → receive inbound call → after disposition, return to Nearby
3. Set wrap-up to 60s → end call → see countdown → submit disposition at 45s → immediately available
4. Let wrap-up timer expire → auto-transitions to available
5. On Call badge shows during active call, disappears after
6. Agent who is on Break does NOT receive inbound calls
7. Agent who is Nearby receives inbound calls but is NOT auto-dialed
