# Dialer Status Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the dialer's phone status company-aware (traffic-light), add a "Nearby" agent status, add a visible wrap-up countdown timer with agent-configurable duration, and auto-transition agent status through the full call lifecycle.

**Architecture:** All 4 features modify the dialer Livewire SFC (`⚡dialer.blade.php`) plus supporting service/model files. The phone readiness check is a Livewire computed property. Agent status transitions are driven by Livewire methods that call `AgentStateService::setStatus()` which broadcasts via Echo. The wrap-up timer is an Alpine.js countdown that calls a Livewire method on expiry.

**Tech Stack:** Livewire 4 SFC, Alpine.js, Flux UI components, Laravel Echo/Reverb, Redis

**Spec:** `docs/superpowers/specs/2026-04-01-dialer-status-improvements-design.md`

---

### Task 1: Add "Nearby" Status to Backend

**Files:**
- Modify: `app/Services/Prospector/AgentStateService.php:14-21`
- Modify: `app/Models/Prospector/Agent.php:115-173`
- Modify: `app/Http/Controllers/Prospector/TwilioVoiceController.php:278-280`

- [ ] **Step 1: Add `nearby` to `VALID_STATUSES` in AgentStateService**

In `app/Services/Prospector/AgentStateService.php`, change lines 14-21:

```php
private const VALID_STATUSES = [
    'offline',
    'available',
    'nearby',
    'on_call',
    'wrap_up',
    'break',
    'ringing',
];
```

- [ ] **Step 2: Add `goNearby()` method to Agent model**

In `app/Models/Prospector/Agent.php`, add after `goAvailable()` (after line 129):

```php
/**
 * Set the agent status to nearby.
 */
public function goNearby(): void
{
    $this->update([
        'status' => 'nearby',
        'status_changed_at' => now(),
    ]);
}
```

- [ ] **Step 3: Update `isAvailable()` on Agent model to include nearby for inbound routing**

In `app/Services/Prospector/AgentStateService.php`, update `isAvailable()` (line 91-94):

```php
public function isAvailable(Agent $agent): bool
{
    return in_array($this->getStatus($agent), ['available', 'nearby'], true);
}
```

- [ ] **Step 4: Update `routeToAgent()` to include nearby agents**

In `app/Http/Controllers/Prospector/TwilioVoiceController.php`, change lines 278-280:

```php
$agents = Agent::whereHas('companies', fn ($q) => $q->where('prospector_companies.id', $company->id))
    ->whereIn('status', ['available', 'nearby'])
    ->get();
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter='TwilioVoice|InboundCallRouting|AgentState' 2>&1 | tail -10`
Expected: All tests pass (existing tests don't assert against exact status list).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Prospector/AgentStateService.php app/Models/Prospector/Agent.php app/Http/Controllers/Prospector/TwilioVoiceController.php
git commit -m "feat(prospector): add 'nearby' agent status for passive inbound availability"
```

---

### Task 2: Add "Nearby" Button to Agent Status Bar + Pre-Call Status Tracking

**Files:**
- Modify: `resources/views/pages/prospector/⚡dialer.blade.php`

- [ ] **Step 1: Add `preCallStatus` and `wrapUpSeconds` properties**

Near the top of the Livewire PHP section (after `public bool $autoAnswerInbound = false;` around line 63), add:

```php
public ?string $preCallStatus = null;
public int $wrapUpSeconds = 30;
```

- [ ] **Step 2: Load `wrapUpSeconds` in `mount()`**

After the line `$this->autoAnswerInbound = ($agent->settings['auto_answer_inbound'] ?? false);`, add:

```php
$this->wrapUpSeconds = ($agent->settings['wrap_up_seconds'] ?? 30);
```

- [ ] **Step 3: Add `nearby` to `setAgentStatus()` allowed list**

In `setAgentStatus()`, change the `$validStatuses` array:

```php
$validStatuses = ['available', 'nearby', 'break', 'offline'];
```

- [ ] **Step 4: Add Nearby button to the agent presence bar**

Replace the agent presence bar (lines 2648-2667) with:

```blade
<div class="flex items-center gap-3 border-t border-zinc-200 bg-white px-4 py-2 dark:border-zinc-700 dark:bg-zinc-800 sticky bottom-0 z-10 mt-4 rounded-lg border shadow-lg">
    <span class="text-xs font-medium text-zinc-500">Status:</span>
    <div class="flex gap-1">
        <button wire:click="setAgentStatus('available')"
            class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors {{ $agentStatus === 'available' ? 'bg-green-500 text-white' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400' }}">
            Available
        </button>
        <button wire:click="setAgentStatus('nearby')"
            class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors {{ $agentStatus === 'nearby' ? 'bg-yellow-500 text-white' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400' }}">
            Nearby
        </button>
        <button wire:click="setAgentStatus('break')"
            class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors {{ $agentStatus === 'break' ? 'bg-amber-500 text-white' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400' }}">
            Break
        </button>
        <button wire:click="setAgentStatus('offline')"
            class="rounded-md px-3 py-1.5 text-xs font-medium transition-colors {{ $agentStatus === 'offline' ? 'bg-zinc-500 text-white' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400' }}">
            Offline
        </button>
    </div>
    @if ($agentStatus === 'on_call')
        <flux:badge color="blue" size="sm">On Call</flux:badge>
    @elseif ($agentStatus === 'ringing')
        <flux:badge color="amber" size="sm">Ringing</flux:badge>
    @elseif ($agentStatus === 'wrap_up')
        <flux:badge color="purple" size="sm">
            Wrap Up <span x-show="wrapUpRemaining > 0" class="ml-1 font-mono" x-text="wrapUpRemaining + 's'"></span>
        </flux:badge>
    @elseif ($agentStatus === 'nearby')
        <flux:badge color="yellow" size="sm">Nearby</flux:badge>
    @endif
</div>
```

- [ ] **Step 5: Commit**

```bash
git add "resources/views/pages/prospector/⚡dialer.blade.php"
git commit -m "feat(prospector): add Nearby button and pre-call status tracking to dialer"
```

---

### Task 3: Auto Status Transitions Through Call Lifecycle

**Files:**
- Modify: `resources/views/pages/prospector/⚡dialer.blade.php`
- Modify: `app/Services/Prospector/PowerDialerService.php:403-404`

- [ ] **Step 1: Save `preCallStatus` when calls start**

In `dialContact()`, just before the line `$this->agentStatus = 'ringing';` (around line 634), add:

```php
$this->preCallStatus = $this->agentStatus;
```

In `dialNext()`, just before `$this->agentStatus = 'ringing';` (around line 770), add:

```php
$this->preCallStatus = $this->agentStatus;
```

In `onInboundCallRinging()`, just before `$this->agentStatus = 'ringing';`, add:

```php
$this->preCallStatus = $this->agentStatus;
```

- [ ] **Step 2: Update `onCallConnected()` to set on_call status server-side**

In `onCallConnected()`, after the line `$this->agentStatus = 'on_call';`, add:

```php
if ($this->agentId) {
    $agent = Agent::findOrFail($this->agentId);
    app(AgentStateService::class)->setStatus($agent, 'on_call');
}
```

- [ ] **Step 3: Update `endCall()` to set wrap_up status server-side and start timer**

In `endCall()`, replace the last 3 lines:

```php
$this->callStatus = 'ended';
$this->showDisposition = true;
$this->agentStatus = 'wrap_up';
```

with:

```php
$this->callStatus = 'ended';
$this->showDisposition = true;
$this->agentStatus = 'wrap_up';

$agent = Agent::findOrFail($this->agentId);
app(AgentStateService::class)->setStatus($agent, 'wrap_up');

// Start client-side wrap-up countdown timer
$this->dispatch('start-wrap-up-timer');
```

- [ ] **Step 4: Update `onCallEnded()` to start wrap-up timer**

Replace `onCallEnded()`:

```php
public function onCallEnded(array $data): void
{
    $this->callStatus = 'ended';
    $this->showDisposition = true;
    $this->supervisorName = null;
    $this->supervisorMode = null;
    $this->agentStatus = 'wrap_up';

    if ($this->agentId) {
        $agent = Agent::findOrFail($this->agentId);
        app(AgentStateService::class)->setStatus($agent, 'wrap_up');
    }

    $this->dispatch('start-wrap-up-timer');
    $this->clearCaches();
}
```

- [ ] **Step 5: Add `completeWrapUp()` and `setWrapUpSeconds()` Livewire methods**

Add after the `endCall()` method:

```php
public function completeWrapUp(): void
{
    $returnStatus = in_array($this->preCallStatus, ['available', 'nearby']) ? $this->preCallStatus : 'available';
    $this->preCallStatus = null;
    $this->agentStatus = $returnStatus;

    if ($this->agentId) {
        $agent = Agent::findOrFail($this->agentId);
        app(AgentStateService::class)->setStatus($agent, $returnStatus);
    }
}

public function setWrapUpSeconds(int $seconds): void
{
    $allowed = [15, 30, 45, 60, 90, 120];
    if (! in_array($seconds, $allowed)) {
        return;
    }
    $this->wrapUpSeconds = $seconds;

    if ($this->agentId) {
        $agent = Agent::findOrFail($this->agentId);
        $settings = $agent->settings ?? [];
        $settings['wrap_up_seconds'] = $seconds;
        $agent->update(['settings' => $settings]);
    }
}
```

- [ ] **Step 6: Update `submitDisposition()` to complete wrap-up immediately**

Replace the current `submitDisposition()` (lines 870-903) with:

```php
public function submitDisposition(string $disposition, ?string $callbackAt = null): void
{
    if (! $this->activeCallId) {
        return;
    }

    $call = Call::findOrFail($this->activeCallId);
    $service = app(PowerDialerService::class);
    $service->dispositionCall($call, $disposition, $this->dispositionNotes ?: null);

    // Handle callback scheduling
    if ($disposition === 'callback' && $callbackAt) {
        $contactId = $this->activeContact['id'] ?? null;

        if ($contactId) {
            Contact::where('id', $contactId)->update([
                'follow_up_at' => $callbackAt,
            ]);
        }
    }

    $this->resetCallState();
    $this->dispositionNotes = '';
    $this->callbackDate = null;
    $this->parallelLines = [];
    $this->connectedLineIndex = null;

    // Cancel wrap-up timer and transition to available/nearby immediately
    $this->dispatch('cancel-wrap-up-timer');
    $this->completeWrapUp();
    $this->clearCaches();
}
```

Key changes: removed `$this->agentStatus = 'wrap_up'` (was wrong — disposition should END wrap-up), removed `$this->dialNext()` auto-advance (agent decides when to dial next), added `completeWrapUp()` call.

- [ ] **Step 7: Remove conflicting `transitionToWrapUp` call from PowerDialerService**

In `app/Services/Prospector/PowerDialerService.php`, replace line 404:

```php
$this->agentStateService->transitionToWrapUp($call->agent);
```

with:

```php
// Wrap-up transition is now handled by the dialer UI (client-side timer).
// The Livewire endCall()/onCallEnded() method sets wrap_up status.
```

This removes the server-side delayed job that conflicts with the client-side timer.

- [ ] **Step 8: Run Pint and tests**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter='Prospector' 2>&1 | tail -10
```

- [ ] **Step 9: Commit**

```bash
git add "resources/views/pages/prospector/⚡dialer.blade.php" app/Services/Prospector/PowerDialerService.php
git commit -m "feat(prospector): auto status transitions through full call lifecycle with wrap-up timer"
```

---

### Task 4: Alpine.js Wrap-Up Timer (Client-Side Countdown)

**Files:**
- Modify: `resources/views/pages/prospector/⚡dialer.blade.php` (Alpine x-data section)

- [ ] **Step 1: Add wrap-up timer properties to Alpine x-data**

In the x-data object, after `pendingInit: null,`, add:

```javascript
wrapUpRemaining: 0,
wrapUpInterval: null,

startWrapUpTimer() {
    this.cancelWrapUpTimer();
    this.wrapUpRemaining = parseInt($wire.wrapUpSeconds) || 30;
    this.wrapUpInterval = setInterval(() => {
        this.wrapUpRemaining--;
        if (this.wrapUpRemaining <= 0) {
            this.cancelWrapUpTimer();
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

- [ ] **Step 2: Add Livewire event listeners for wrap-up timer**

In the `init()` method, after the `dial-error` listener, add:

```javascript
this.$wire.on('start-wrap-up-timer', () => {
    this.startWrapUpTimer();
});

this.$wire.on('cancel-wrap-up-timer', () => {
    this.cancelWrapUpTimer();
});
```

- [ ] **Step 3: Clean up timer in `destroy()`**

In the `destroy()` method, after `this.stopTimer();`, add:

```javascript
this.cancelWrapUpTimer();
```

- [ ] **Step 4: Add wrap-up seconds dropdown to softphone status bar**

In the softphone status bar, next to the auto-answer toggle button, add a wrap-up seconds selector:

```blade
<select
    wire:model.live="wrapUpSeconds"
    wire:change="setWrapUpSeconds($event.target.value)"
    class="rounded border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-xs text-zinc-600 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-400"
    title="Wrap-up time after calls"
>
    <option value="15">15s</option>
    <option value="30">30s</option>
    <option value="45">45s</option>
    <option value="60">60s</option>
    <option value="90">90s</option>
    <option value="120">120s</option>
</select>
```

Place this after the auto-answer toggle button, before the closing `</div>` of the right-side controls.

- [ ] **Step 5: Add "Go Available" button to disposition/wrap-up area**

In the disposition section (when `$callStatus === 'ended'`), add a visible countdown and "Go Available" shortcut. Find the existing disposition UI and add above it:

```blade
@if ($agentStatus === 'wrap_up')
    <div class="mb-3 flex items-center justify-between rounded-lg border border-purple-200 bg-purple-50 px-4 py-2 dark:border-purple-700 dark:bg-purple-900/20">
        <div class="flex items-center gap-2">
            <flux:icon name="clock" class="size-4 text-purple-500" />
            <span class="text-sm font-medium text-purple-700 dark:text-purple-300">Wrap Up</span>
            <span x-show="wrapUpRemaining > 0" class="font-mono text-sm font-bold text-purple-600 dark:text-purple-400" x-text="wrapUpRemaining + 's'"></span>
        </div>
        <flux:button size="xs" variant="primary" wire:click="completeWrapUp" x-on:click="cancelWrapUpTimer()">
            Go Available
        </flux:button>
    </div>
@endif
```

- [ ] **Step 6: Build and test**

```bash
npm run build
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter='TwilioVoice|ProspectorWebhook' 2>&1 | tail -10
```

- [ ] **Step 7: Commit**

```bash
git add "resources/views/pages/prospector/⚡dialer.blade.php"
git commit -m "feat(prospector): visible wrap-up countdown timer with agent-configurable duration"
```

---

### Task 5: Per-Company Phone Readiness (Traffic Light)

**Files:**
- Modify: `resources/views/pages/prospector/⚡dialer.blade.php`

- [ ] **Step 1: Add `phoneReadiness` computed property**

In the Livewire PHP section, add a computed property:

```php
#[Computed]
public function phoneReadiness(): array
{
    $companyId = $this->filterCompanyId;

    if (! $companyId) {
        // No filter — check the company used for the current voice token
        if ($this->agentId) {
            $agent = Agent::find($this->agentId);
            $companyId = $agent?->company_id;
        }
    }

    if (! $companyId) {
        return ['status' => 'amber', 'label' => 'Select a company', 'checks' => []];
    }

    $company = Company::with(['twilioAccount', 'phoneNumbers' => fn ($q) => $q->where('is_active', true)])
        ->find($companyId);

    if (! $company) {
        return ['status' => 'red', 'label' => 'Company not found', 'checks' => []];
    }

    $account = $company->twilioAccount;
    $numbers = $company->phoneNumbers;

    $checks = [];
    $hasVoice = false;
    $hasSms = false;

    // Check 1: Twilio account exists and active
    $accountOk = $account && $account->is_active;
    $checks[] = ['name' => 'Twilio Account', 'ok' => $accountOk, 'detail' => $accountOk ? $account->name : 'No active account'];

    // Check 2: Voice SDK credentials
    $hasConfigCreds = config('prospector.twilio.api_key_sid') && config('prospector.twilio.api_key_secret');
    $hasDbCreds = $account && $account->api_key_sid && $account->api_key_secret;
    $credsOk = $hasDbCreds || $hasConfigCreds;
    $checks[] = ['name' => 'Voice SDK Credentials', 'ok' => $credsOk, 'detail' => $credsOk ? 'API Key configured' : 'Missing API Key SID/Secret'];
    $hasVoice = $accountOk && $credsOk;

    // Check 3: TwiML App
    $hasConfigTwiml = (bool) config('prospector.twilio.twiml_app_sid');
    $hasDbTwiml = $account && $account->twiml_app_sid;
    $twimlOk = $hasDbTwiml || $hasConfigTwiml;
    $checks[] = ['name' => 'TwiML App', 'ok' => $twimlOk, 'detail' => $twimlOk ? 'Configured' : 'Missing TwiML App SID'];

    // Check 4: Active phone number
    $numberOk = $numbers->isNotEmpty();
    $numberDetail = $numberOk ? $numbers->first()->phone_number : 'No active phone numbers';
    $checks[] = ['name' => 'Phone Number', 'ok' => $numberOk, 'detail' => $numberDetail];

    // Check 5: SMS capability
    $smsNumber = $numbers->first(fn ($n) => ($n->capabilities['sms'] ?? false));
    $hasSms = (bool) $smsNumber;
    $checks[] = ['name' => 'SMS Capability', 'ok' => $hasSms, 'detail' => $hasSms ? 'SMS enabled' : 'No SMS-capable number — A2P pending'];

    // Determine traffic light
    if ($hasVoice && $numberOk && $twimlOk && $hasSms) {
        $status = 'green';
        $label = 'Phone Ready';
    } elseif ($hasVoice && $numberOk) {
        $status = 'amber';
        $label = 'Voice Only';
    } else {
        $status = 'red';
        $label = 'Not Configured';
    }

    return ['status' => $status, 'label' => $label, 'checks' => $checks];
}
```

- [ ] **Step 2: Replace the softphone status bar with company-aware traffic light**

Replace the entire softphone status bar div. The key changes:
- Use `$this->phoneReadiness` for colors instead of Alpine `deviceReady`
- Combine with Alpine `deviceReady` for the WebRTC device state
- Add expandable checklist via Alpine toggle

```blade
{{-- Softphone Status Bar — company-aware traffic light --}}
@php $readiness = $this->phoneReadiness; @endphp
<div class="rounded-lg border bg-white dark:bg-zinc-800"
    :class="{
        'border-green-300 dark:border-green-700': deviceReady && '{{ $readiness['status'] }}' === 'green',
        'border-amber-300 dark:border-amber-700': deviceReady && '{{ $readiness['status'] }}' !== 'green' || (!deviceReady && !phoneError),
        'border-red-300 dark:border-red-700': phoneError || '{{ $readiness['status'] }}' === 'red',
    }"
    x-data="{ showChecks: false }"
>
    <div class="flex items-center justify-between px-4 py-2.5">
        <div class="flex items-center gap-2">
            <span class="inline-flex size-2.5 rounded-full"
                :class="{
                    'bg-green-500': deviceReady && '{{ $readiness['status'] }}' === 'green',
                    'bg-amber-500': deviceReady && '{{ $readiness['status'] }}' === 'amber',
                    'bg-red-500': phoneError || '{{ $readiness['status'] }}' === 'red',
                    'bg-amber-500 animate-pulse': !deviceReady && !phoneError && '{{ $readiness['status'] }}' !== 'red',
                }"
            ></span>
            <span class="text-sm font-medium"
                :class="{
                    'text-green-700 dark:text-green-400': deviceReady && '{{ $readiness['status'] }}' === 'green',
                    'text-amber-700 dark:text-amber-400': deviceReady && '{{ $readiness['status'] }}' === 'amber',
                    'text-red-700 dark:text-red-400': phoneError || '{{ $readiness['status'] }}' === 'red',
                    'text-amber-700 dark:text-amber-400': !deviceReady && !phoneError,
                }"
                x-text="deviceReady ? '{{ $readiness['label'] }}' : (phoneError ? 'Phone Error' : 'Phone Connecting...')"
            ></span>
            <button type="button" x-on:click="showChecks = !showChecks" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                <flux:icon name="chevron-down" class="size-3.5 transition-transform" ::class="showChecks ? 'rotate-180' : ''" />
            </button>
            <template x-if="phoneError">
                <span class="text-xs text-red-500 dark:text-red-400" x-text="phoneError"></span>
            </template>
            <template x-if="connectionDebug && !phoneError">
                <span class="text-xs text-blue-500 dark:text-blue-400" x-text="connectionDebug"></span>
            </template>
        </div>
        <div class="flex items-center gap-2">
            @if ($callingFrom)
                <span class="text-xs text-zinc-400">{{ $callingFrom }}</span>
            @endif
            @if ($callStatus === 'connected')
                <span class="font-mono text-sm font-bold text-green-600 dark:text-green-400" x-text="formatTime(callTimer)">00:00</span>
            @endif
            <template x-if="!deviceReady">
                <button type="button" x-on:click="initTwilio()" class="text-xs font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400">Retry</button>
            </template>
            <button
                type="button"
                wire:click="toggleAutoAnswer"
                class="flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium transition {{ $autoAnswerInbound ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400' }}"
                title="{{ $autoAnswerInbound ? 'Auto-answer ON — inbound calls connect automatically' : 'Auto-answer OFF — you must click Answer for inbound calls' }}"
            >
                <flux:icon name="{{ $autoAnswerInbound ? 'phone-arrow-down-left' : 'bell-alert' }}" class="size-3.5" />
                {{ $autoAnswerInbound ? 'Auto' : 'Ring' }}
            </button>
            <select
                wire:change="setWrapUpSeconds($event.target.value)"
                class="rounded border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-xs text-zinc-600 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-400"
                title="Wrap-up time after calls"
            >
                @foreach ([15, 30, 45, 60, 90, 120] as $sec)
                    <option value="{{ $sec }}" {{ $wrapUpSeconds == $sec ? 'selected' : '' }}>{{ $sec }}s</option>
                @endforeach
            </select>
        </div>
    </div>
    {{-- Expandable capability checklist --}}
    <div x-show="showChecks" x-collapse class="border-t border-zinc-200 px-4 py-2.5 dark:border-zinc-700">
        <div class="space-y-1.5">
            @foreach ($readiness['checks'] as $check)
                <div class="flex items-center gap-2 text-xs">
                    @if ($check['ok'])
                        <flux:icon name="check-circle" class="size-4 text-green-500" />
                    @else
                        <flux:icon name="x-circle" class="size-4 text-red-500" />
                    @endif
                    <span class="{{ $check['ok'] ? 'text-zinc-600 dark:text-zinc-400' : 'text-red-600 dark:text-red-400' }}">
                        {{ $check['name'] }} — {{ $check['detail'] }}
                    </span>
                    @if (! $check['ok'])
                        <a href="/prospector/settings" class="ml-auto text-blue-600 hover:underline dark:text-blue-400">Settings</a>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
```

- [ ] **Step 3: Clear phoneReadiness cache when company filter changes**

In `clearCaches()`, add `$this->phoneReadiness` to the `unset()` call:

```php
unset(
    $this->agent,
    $this->campaigns,
    $this->assignedCompanies,
    $this->queueContacts,
    $this->queuedCount,
    $this->activeCall,
    $this->contactDetail,
    $this->contactCallHistory,
    $this->bookingLinks,
    $this->pipelineStages,
    $this->rvmTemplates,
    $this->activeScript,
    $this->phoneReadiness,
);
```

- [ ] **Step 4: Build, format, and test**

```bash
npm run build
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter='TwilioVoice|InboundCallRouting' 2>&1 | tail -10
```

- [ ] **Step 5: Commit**

```bash
git add "resources/views/pages/prospector/⚡dialer.blade.php"
git commit -m "feat(prospector): per-company phone readiness traffic light with expandable checklist"
```

---

### Task 6: Final Integration Testing & Push

**Files:** None (testing only)

- [ ] **Step 1: Run full Prospector test suite**

```bash
php artisan test --compact --filter='Prospector' 2>&1 | tail -15
```

Expected: All pre-existing passing tests still pass. The 2 DialerSessionlessTest UniqueConstraint failures are pre-existing and unrelated.

- [ ] **Step 2: Build frontend**

```bash
npm run build
```

Expected: Build succeeds with no errors.

- [ ] **Step 3: Push to main**

```bash
git push origin main
```
