<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\DialerSession;
use App\Models\Prospector\SmsMessage;
use App\Services\Prospector\ParallelDialerService;
use App\Services\Prospector\PowerDialerService;
use App\Services\Prospector\ProspectorSmsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Power Dialer')]
class extends Component
{
    // Session state
    public ?int $selectedCampaignId = null;
    public ?int $activeSessionId = null;
    public string $sessionStatus = 'idle'; // idle, active, paused
    public string $dialerMode = 'single'; // single, parallel

    // Active call state
    public ?string $activeCallUlid = null;
    public ?int $activeCallId = null;
    public ?array $activeContact = null;
    public string $callStatus = 'idle'; // idle, ringing, connected, ended
    public ?string $callingFrom = null;
    public ?string $amdResult = null;

    // Parallel dialer state
    public array $parallelLines = []; // [{call_id, contact_name, phone, status}]
    public ?int $connectedLineIndex = null;

    // Disposition
    public string $dispositionNotes = '';
    public bool $showDisposition = false;

    // Agent
    public ?int $agentId = null;
    public string $agentStatus = 'offline';

    // UI
    public bool $isMuted = false;

    // SMS
    public string $smsBody = '';
    public bool $smsOpen = false;
    public array $smsConversation = [];
    public ?int $smsContactId = null;

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    public function mount(): void
    {
        $user = Auth::user();

        // Find or create agent record for the first company
        $company = Company::where('is_active', true)->first();

        if ($company) {
            $agent = Agent::firstOrCreate(
                ['user_id' => $user->id, 'company_id' => $company->id],
                ['status' => 'offline', 'max_concurrent_lines' => 1, 'wrap_up_seconds' => 30],
            );

            $this->agentId = $agent->id;
            $this->agentStatus = $agent->status;

            // Check for active session
            $activeSession = DialerSession::where('agent_id', $agent->id)
                ->whereIn('status', ['active', 'paused'])
                ->latest()
                ->first();

            if ($activeSession) {
                $this->activeSessionId = $activeSession->id;
                $this->selectedCampaignId = $activeSession->campaign_id;
                $this->sessionStatus = $activeSession->status === 'active' ? 'active' : 'paused';
                $this->dialerMode = $activeSession->mode;
            }
        }
    }

    #[Computed]
    public function agent(): ?Agent
    {
        if (! $this->agentId) {
            return null;
        }

        return Agent::with('user', 'company')->find($this->agentId);
    }

    #[Computed]
    public function campaigns(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->agent) {
            return collect();
        }

        return Campaign::where('company_id', $this->agent->company_id)
            ->where('status', 'active')
            ->withCount(['campaignContacts as queued_count' => fn ($q) => $q->where('status', 'queued')])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function session(): ?DialerSession
    {
        if (! $this->activeSessionId) {
            return null;
        }

        return DialerSession::with('campaign', 'agent')->find($this->activeSessionId);
    }

    #[Computed]
    public function queueContacts(): \Illuminate\Support\Collection
    {
        if (! $this->selectedCampaignId) {
            return collect();
        }

        return CampaignContact::where('campaign_id', $this->selectedCampaignId)
            ->where('status', 'queued')
            ->with('contact')
            ->orderBy('sort_order')
            ->limit(25)
            ->get();
    }

    #[Computed]
    public function queuedCount(): int
    {
        if (! $this->selectedCampaignId) {
            return 0;
        }

        return CampaignContact::where('campaign_id', $this->selectedCampaignId)
            ->where('status', 'queued')
            ->count();
    }

    #[Computed]
    public function dispositions(): array
    {
        return config('prospector.dispositions', []);
    }

    #[Computed]
    public function activeCall(): ?Call
    {
        if (! $this->activeCallId) {
            return null;
        }

        return Call::with('contact', 'fromPhoneNumber')->find($this->activeCallId);
    }

    // ──────────────────────────────────────
    // Session Controls
    // ──────────────────────────────────────

    public function startSession(): void
    {
        if (! $this->selectedCampaignId || ! $this->agentId) {
            return;
        }

        $agent = Agent::findOrFail($this->agentId);
        $campaign = Campaign::findOrFail($this->selectedCampaignId);

        // Use campaign's dialer mode and parallel lines settings
        $mode = $campaign->dialer_mode ?? 'single';
        $parallelLines = $campaign->parallel_lines ?? 3;

        $service = app(PowerDialerService::class);
        $session = $service->startSession($agent, $campaign, $mode, $parallelLines);

        $this->activeSessionId = $session->id;
        $this->sessionStatus = 'active';
        $this->agentStatus = 'available';
        $this->dialerMode = $mode;

        $this->clearCaches();

        // Auto-dial first contact(s)
        $this->dialNext();
    }

    public function pauseSession(): void
    {
        if (! $this->activeSessionId) {
            return;
        }

        $session = DialerSession::findOrFail($this->activeSessionId);
        $service = app(PowerDialerService::class);
        $service->pauseSession($session);

        $this->sessionStatus = 'paused';
        $this->agentStatus = 'break';
        $this->clearCaches();
    }

    public function resumeSession(): void
    {
        if (! $this->activeSessionId) {
            return;
        }

        $session = DialerSession::findOrFail($this->activeSessionId);
        $service = app(PowerDialerService::class);
        $service->resumeSession($session);

        $this->sessionStatus = 'active';
        $this->agentStatus = 'available';
        $this->clearCaches();
    }

    public function endSession(): void
    {
        if (! $this->activeSessionId) {
            return;
        }

        $session = DialerSession::findOrFail($this->activeSessionId);
        $service = app(PowerDialerService::class);
        $service->endSession($session);

        $this->activeSessionId = null;
        $this->sessionStatus = 'idle';
        $this->agentStatus = 'offline';
        $this->dialerMode = 'single';
        $this->parallelLines = [];
        $this->connectedLineIndex = null;
        $this->resetCallState();
        $this->clearCaches();
    }

    // ──────────────────────────────────────
    // Call Controls
    // ──────────────────────────────────────

    public function dialNext(): void
    {
        if (! $this->activeSessionId) {
            return;
        }

        $session = DialerSession::findOrFail($this->activeSessionId);

        if ($this->dialerMode === 'parallel') {
            $this->dialNextParallel($session);
        } else {
            $this->dialNextSingle($session);
        }
    }

    private function dialNextSingle(DialerSession $session): void
    {
        $service = app(PowerDialerService::class);
        $call = $service->dialNext($session);

        if ($call) {
            $call->load('contact');
            $this->setActiveCallFromCall($call);
            $this->agentStatus = 'ringing';
            $this->showDisposition = false;
        } else {
            $this->resetCallState();
        }

        $this->clearCaches();
    }

    private function dialNextParallel(DialerSession $session): void
    {
        $service = app(ParallelDialerService::class);
        $calls = $service->dialNextBatch($session);

        $this->parallelLines = [];
        $this->connectedLineIndex = null;

        if (! empty($calls)) {
            foreach ($calls as $i => $call) {
                $call->load('contact');
                $this->parallelLines[] = [
                    'call_id' => $call->id,
                    'contact_name' => $call->contact->getFullName(),
                    'phone' => $call->contact->getDisplayPhone(),
                    'status' => 'ringing',
                ];
            }

            // Set first call as the active one (for contact detail display)
            $firstCall = $calls[0];
            $this->setActiveCallFromCall($firstCall);
            $this->agentStatus = 'ringing';
            $this->callStatus = 'ringing';
            $this->showDisposition = false;
        } else {
            $this->resetCallState();
        }

        $this->clearCaches();
    }

    private function setActiveCallFromCall(Call $call): void
    {
        $this->activeCallId = $call->id;
        $this->activeCallUlid = $call->ulid;
        $this->callStatus = 'ringing';
        $this->callingFrom = $call->from_number;
        $this->activeContact = [
            'id' => $call->contact->id,
            'name' => $call->contact->getFullName(),
            'phone' => $call->contact->getDisplayPhone(),
            'email' => $call->contact->email,
            'city' => $call->contact->city,
            'state' => $call->contact->state,
            'tags' => $call->contact->tags,
            'last_contacted_at' => $call->contact->last_contacted_at?->diffForHumans(),
            'total_calls' => $call->contact->total_calls,
            'total_sms' => $call->contact->total_sms,
        ];

        // Load SMS conversation for the contact
        $this->loadConversation($call->contact->id);
    }

    public function skipContact(): void
    {
        $this->resetCallState();
        $this->dialNext();
    }

    public function submitDisposition(string $disposition): void
    {
        if (! $this->activeCallId) {
            return;
        }

        $call = Call::findOrFail($this->activeCallId);
        $service = app(PowerDialerService::class);
        $service->dispositionCall($call, $disposition, $this->dispositionNotes ?: null);

        // Clear parallel batch lock if in parallel mode
        if ($this->dialerMode === 'parallel' && $this->activeSessionId) {
            $session = DialerSession::find($this->activeSessionId);

            if ($session) {
                app(ParallelDialerService::class)->clearBatchLock($session);
            }
        }

        $this->resetCallState();
        $this->agentStatus = 'wrap_up';
        $this->dispositionNotes = '';
        $this->parallelLines = [];
        $this->connectedLineIndex = null;
        $this->clearCaches();
    }

    // ──────────────────────────────────────
    // SMS Methods
    // ──────────────────────────────────────

    public function loadConversation(?int $contactId = null): void
    {
        $contactId ??= $this->activeContact['id'] ?? null;

        if (! $contactId) {
            $this->smsConversation = [];
            $this->smsContactId = null;

            return;
        }

        $this->smsContactId = $contactId;

        $contact = Contact::find($contactId);

        if (! $contact) {
            $this->smsConversation = [];

            return;
        }

        $service = app(ProspectorSmsService::class);
        $messages = $service->getConversation($contact, 50);

        $this->smsConversation = $messages->reverse()->values()->map(fn (SmsMessage $msg) => [
            'id' => $msg->id,
            'direction' => $msg->direction,
            'body' => $msg->body,
            'status' => $msg->status,
            'created_at' => $msg->created_at->format('g:i A'),
            'created_at_full' => $msg->created_at->format('M j, Y g:i A'),
        ])->toArray();
    }

    public function sendSms(): void
    {
        $body = trim($this->smsBody);

        if (! $body || ! $this->smsContactId) {
            return;
        }

        $contact = Contact::find($this->smsContactId);

        if (! $contact) {
            return;
        }

        $agent = $this->agentId ? Agent::find($this->agentId) : null;
        $company = $agent?->company ?? Company::where('is_active', true)->first();

        if (! $company) {
            return;
        }

        try {
            $service = app(ProspectorSmsService::class);
            $service->sendSms($company, $contact, $body, $agent);

            $this->smsBody = '';
            $this->loadConversation($this->smsContactId);
        } catch (\Exception $e) {
            // Error is logged by the service
        }
    }

    public function onSmsReceived(array $data): void
    {
        $contactId = $data['contact_id'] ?? null;

        if ($contactId && $contactId === $this->smsContactId) {
            $this->loadConversation($this->smsContactId);
        }
    }

    // ──────────────────────────────────────
    // Echo Listeners (called from JS)
    // ──────────────────────────────────────

    public function onCallRinging(array $data): void
    {
        $this->callStatus = 'ringing';
        $this->activeCallUlid = $data['call_ulid'] ?? null;
        $this->activeContact = $data['contact'] ?? null;
        $this->agentStatus = 'ringing';
    }

    public function onCallConnected(array $data): void
    {
        $this->callStatus = 'connected';
        $this->amdResult = $data['amd_result'] ?? null;
        $this->agentStatus = 'on_call';

        // In parallel mode, find and highlight the winning line
        if ($this->dialerMode === 'parallel') {
            $connectedCallId = $data['call_id'] ?? null;

            foreach ($this->parallelLines as $i => &$line) {
                if ($line['call_id'] == $connectedCallId) {
                    $line['status'] = 'connected';
                    $this->connectedLineIndex = $i;
                    $this->activeCallId = $connectedCallId;

                    // Update active contact to the winning line's contact
                    $call = Call::with('contact')->find($connectedCallId);

                    if ($call) {
                        $this->setActiveCallFromCall($call);
                        $this->callStatus = 'connected';
                    }
                } else {
                    $line['status'] = 'canceled';
                }
            }

            unset($line);
        }
    }

    public function onCallEnded(array $data): void
    {
        $this->callStatus = 'ended';
        $this->showDisposition = true;
        $this->clearCaches();
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    protected function resetCallState(): void
    {
        $this->activeCallId = null;
        $this->activeCallUlid = null;
        $this->activeContact = null;
        $this->callStatus = 'idle';
        $this->callingFrom = null;
        $this->amdResult = null;
        $this->showDisposition = false;
        $this->isMuted = false;
        $this->smsConversation = [];
        $this->smsContactId = null;
        $this->smsBody = '';
    }

    protected function clearCaches(): void
    {
        unset($this->agent, $this->campaigns, $this->session, $this->queueContacts, $this->queuedCount, $this->activeCall);
    }

    protected function getStatusColor(string $status): string
    {
        return match ($status) {
            'available' => 'green',
            'on_call' => 'blue',
            'ringing' => 'amber',
            'wrap_up' => 'purple',
            'break' => 'orange',
            'offline' => 'zinc',
            default => 'zinc',
        };
    }

    protected function getStatusDotClass(string $status): string
    {
        return match ($status) {
            'available' => 'bg-green-500',
            'on_call' => 'bg-blue-500',
            'ringing' => 'bg-amber-500 animate-pulse',
            'wrap_up' => 'bg-purple-500',
            'break' => 'bg-orange-500',
            'offline' => 'bg-zinc-400',
            default => 'bg-zinc-400',
        };
    }
}; ?>

<div
    x-data="{
        callTimer: 0,
        timerInterval: null,
        muted: $wire.entangle('isMuted'),
        smsOpen: $wire.entangle('smsOpen'),

        startTimer() {
            this.callTimer = 0;
            this.timerInterval = setInterval(() => { this.callTimer++ }, 1000);
        },

        stopTimer() {
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }
        },

        formatTime(seconds) {
            const m = Math.floor(seconds / 60).toString().padStart(2, '0');
            const s = (seconds % 60).toString().padStart(2, '0');
            return m + ':' + s;
        },

        init() {
            if (typeof Echo !== 'undefined' && $wire.agentId) {
                Echo.private('prospector.agent.' + $wire.agentId)
                    .listen('.dialer.call.ringing', (e) => {
                        $wire.onCallRinging(e);
                    })
                    .listen('.dialer.call.connected', (e) => {
                        $wire.onCallConnected(e);
                        this.startTimer();
                    })
                    .listen('.dialer.call.ended', (e) => {
                        this.stopTimer();
                        $wire.onCallEnded(e);
                    });

                // Listen for inbound SMS on company channel
                const companyId = $wire.agent?.company_id;
                if (companyId) {
                    Echo.private('prospector.company.' + companyId)
                        .listen('.sms.received', (e) => {
                            $wire.onSmsReceived(e);
                        });
                }
            }
        },

        destroy() {
            this.stopTimer();
            if (typeof Echo !== 'undefined' && $wire.agentId) {
                Echo.leave('prospector.agent.' + $wire.agentId);
            }
        }
    }"
>
    {{-- Top Bar --}}
    <div class="mb-6 flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex items-center gap-4">
            @if ($this->agent)
                <div class="flex items-center gap-2">
                    <flux:icon name="building-office" variant="micro" class="text-zinc-400" />
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $this->agent->company->name ?? 'No Company' }}</span>
                </div>
                <div class="text-zinc-300 dark:text-zinc-600">|</div>
                <div class="flex items-center gap-2">
                    <flux:icon name="user" variant="micro" class="text-zinc-400" />
                    <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ $this->agent->user->name ?? 'Unknown' }}</span>
                </div>
                @if ($dialerMode === 'parallel')
                    <div class="text-zinc-300 dark:text-zinc-600">|</div>
                    <flux:badge size="sm" color="indigo">Parallel Mode</flux:badge>
                @endif
            @endif
        </div>

        <div class="flex items-center gap-2">
            <span class="inline-flex size-2.5 rounded-full {{ $this->getStatusDotClass($agentStatus) }}"></span>
            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ ucfirst(str_replace('_', ' ', $agentStatus)) }}</span>
        </div>
    </div>

    {{-- Main Grid --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">

        {{-- Left: Queue Panel --}}
        <div class="lg:col-span-3">
            <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <div class="flex items-center justify-between">
                        <flux:heading size="sm">Queue ({{ $this->queuedCount }})</flux:heading>
                    </div>
                </div>

                <div class="max-h-[500px] overflow-y-auto">
                    @forelse ($this->queueContacts as $entry)
                        <div
                            wire:key="q-{{ $entry->id }}"
                            class="border-b border-zinc-100 px-4 py-3 last:border-0 dark:border-zinc-700/50
                                {{ $activeContact && ($activeContact['id'] ?? null) === $entry->contact?->id ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}"
                        >
                            @if ($entry->contact)
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $entry->contact->getFullName() }}</div>
                                <div class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $entry->contact->getDisplayPhone() }}</div>
                                @if ($entry->contact->tags)
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @foreach (array_slice($entry->contact->tags, 0, 2) as $tag)
                                            <span class="inline-flex rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400">{{ $tag }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            @else
                                <span class="text-sm text-zinc-400">Deleted</span>
                            @endif
                        </div>
                    @empty
                        <div class="px-4 py-8 text-center text-sm text-zinc-400">
                            No contacts in queue
                        </div>
                    @endforelse
                </div>
            </div>

            {{-- Session Stats --}}
            @if ($this->session)
                <div class="mt-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="sm" class="mb-3">Session</flux:heading>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-zinc-500 dark:text-zinc-400">Mode</span>
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">
                                {{ ucfirst($this->session->mode) }}
                                @if ($this->session->isParallel())
                                    <span class="text-xs text-zinc-400">({{ $this->session->parallel_lines }} lines)</span>
                                @endif
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500 dark:text-zinc-400">Attempted</span>
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $this->session->calls_attempted }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500 dark:text-zinc-400">Connected</span>
                            <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $this->session->calls_connected }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-zinc-500 dark:text-zinc-400">Talk Time</span>
                            <span class="font-mono font-medium text-zinc-900 dark:text-zinc-100">
                                {{ gmdate('H:i:s', $this->session->total_talk_seconds) }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-4 flex gap-2">
                        @if ($sessionStatus === 'active')
                            <flux:button size="sm" variant="filled" icon="pause" wire:click="pauseSession" class="flex-1">
                                Pause
                            </flux:button>
                        @elseif ($sessionStatus === 'paused')
                            <flux:button size="sm" variant="primary" icon="play" wire:click="resumeSession" class="flex-1">
                                Resume
                            </flux:button>
                        @endif
                        <flux:button size="sm" variant="danger" icon="stop" wire:click="endSession" class="flex-1">
                            Stop
                        </flux:button>
                    </div>
                </div>
            @endif
        </div>

        {{-- Center: Active Call --}}
        <div class="lg:col-span-5">
            {{-- Campaign Selector (only when idle) --}}
            @if ($sessionStatus === 'idle')
                <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="lg" class="mb-4">Start Dialing</flux:heading>

                    @if ($this->campaigns->isEmpty())
                        <div class="py-8 text-center">
                            <flux:icon name="phone-x-mark" class="mx-auto mb-3 size-10 text-zinc-400" />
                            <flux:text>No active campaigns available.</flux:text>
                            <flux:button variant="ghost" :href="route('prospector.campaigns')" class="mt-3" icon="arrow-right">
                                Go to Campaigns
                            </flux:button>
                        </div>
                    @else
                        <div class="space-y-4">
                            <flux:select wire:model="selectedCampaignId" label="Campaign" placeholder="Select a campaign...">
                                @foreach ($this->campaigns as $campaign)
                                    <flux:select.option value="{{ $campaign->id }}">
                                        {{ $campaign->name }} ({{ $campaign->queued_count }} queued)
                                        @if ($campaign->dialer_mode === 'parallel')
                                            - Parallel
                                        @endif
                                    </flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:button
                                variant="primary"
                                icon="phone"
                                wire:click="startSession"
                                :disabled="! $selectedCampaignId"
                                class="w-full"
                            >
                                Start Session
                            </flux:button>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Active Call Display --}}
            @if ($sessionStatus !== 'idle')
                <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                        <flux:heading size="lg">Active Call</flux:heading>
                    </div>

                    <div class="p-6">
                        {{-- Parallel Lines Indicator --}}
                        @if ($dialerMode === 'parallel' && ! empty($parallelLines))
                            <div class="mb-6 space-y-2">
                                @foreach ($parallelLines as $i => $line)
                                    <div
                                        class="flex items-center gap-3 rounded-lg border px-4 py-3
                                            @if ($line['status'] === 'connected')
                                                border-green-300 bg-green-50 dark:border-green-700 dark:bg-green-900/20
                                            @elseif ($line['status'] === 'canceled')
                                                border-zinc-200 bg-zinc-50 opacity-50 dark:border-zinc-700 dark:bg-zinc-800/50
                                            @elseif ($line['status'] === 'ringing')
                                                border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20
                                            @else
                                                border-zinc-200 dark:border-zinc-700
                                            @endif
                                        "
                                    >
                                        <div class="flex size-7 items-center justify-center rounded-full
                                            @if ($line['status'] === 'connected') bg-green-500 text-white
                                            @elseif ($line['status'] === 'canceled') bg-zinc-300 text-zinc-500 dark:bg-zinc-600 dark:text-zinc-400
                                            @elseif ($line['status'] === 'ringing') bg-amber-500 text-white animate-pulse
                                            @else bg-zinc-200 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300
                                            @endif
                                            text-xs font-bold"
                                        >
                                            {{ $i + 1 }}
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <div class="truncate text-sm font-medium
                                                @if ($line['status'] === 'canceled') text-zinc-400 line-through
                                                @else text-zinc-900 dark:text-zinc-100
                                                @endif
                                            ">{{ $line['contact_name'] }}</div>
                                            <div class="font-mono text-xs text-zinc-500">{{ $line['phone'] }}</div>
                                        </div>
                                        <div>
                                            @if ($line['status'] === 'connected')
                                                <flux:badge size="sm" color="green">Connected</flux:badge>
                                            @elseif ($line['status'] === 'canceled')
                                                <flux:badge size="sm" color="zinc">Canceled</flux:badge>
                                            @elseif ($line['status'] === 'ringing')
                                                <flux:badge size="sm" color="amber">Ringing</flux:badge>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($callStatus === 'idle' && ! $showDisposition)
                            <div class="py-8 text-center">
                                <flux:icon name="phone" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                                <flux:text class="text-zinc-500">Ready to dial. Click below or wait for auto-dial.</flux:text>
                                <flux:button variant="primary" icon="phone-arrow-up-right" wire:click="dialNext" class="mt-4">
                                    {{ $dialerMode === 'parallel' ? 'Dial Next Batch' : 'Dial Next' }}
                                </flux:button>
                            </div>
                        @elseif ($callStatus === 'ringing' && $dialerMode !== 'parallel')
                            <div class="text-center">
                                <div class="mx-auto mb-4 flex size-16 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/30">
                                    <flux:icon name="phone" class="size-8 animate-pulse text-amber-600 dark:text-amber-400" />
                                </div>
                                <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $activeContact['name'] ?? 'Unknown' }}</div>
                                <div class="font-mono text-sm text-zinc-500">{{ $activeContact['phone'] ?? '' }}</div>
                                @if ($callingFrom)
                                    <div class="mt-2 text-xs text-zinc-400">Calling from: {{ $callingFrom }}</div>
                                @endif
                                <div class="mt-3">
                                    <flux:badge color="amber">Ringing...</flux:badge>
                                </div>
                                <div class="mt-4">
                                    <flux:button size="sm" variant="ghost" icon="forward" wire:click="skipContact">
                                        Skip
                                    </flux:button>
                                </div>
                            </div>
                        @elseif ($callStatus === 'connected')
                            <div class="text-center">
                                <div class="mx-auto mb-4 flex size-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
                                    <flux:icon name="phone" class="size-8 text-green-600 dark:text-green-400" />
                                </div>
                                <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $activeContact['name'] ?? 'Unknown' }}</div>
                                <div class="font-mono text-sm text-zinc-500">{{ $activeContact['phone'] ?? '' }}</div>
                                @if ($callingFrom)
                                    <div class="mt-2 text-xs text-zinc-400">Calling from: {{ $callingFrom }}</div>
                                @endif

                                <div class="mt-3 font-mono text-2xl font-bold text-green-600 dark:text-green-400" x-text="formatTime(callTimer)">
                                    00:00
                                </div>

                                <div class="mt-4 flex justify-center gap-3">
                                    <flux:button
                                        size="sm"
                                        :variant="$isMuted ? 'danger' : 'filled'"
                                        :icon="$isMuted ? 'speaker-x-mark' : 'speaker-wave'"
                                        x-on:click="muted = !muted"
                                    >
                                        <span x-text="muted ? 'Unmute' : 'Mute'">Mute</span>
                                    </flux:button>
                                </div>
                            </div>
                        @endif

                        {{-- Disposition Panel --}}
                        @if ($showDisposition || $callStatus === 'connected')
                            <div class="mt-6 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                                <flux:heading size="sm" class="mb-3">Disposition</flux:heading>

                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    @foreach ($this->dispositions as $key => $label)
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            wire:click="submitDisposition('{{ $key }}')"
                                            class="justify-start"
                                        >
                                            {{ $label }}
                                        </flux:button>
                                    @endforeach
                                </div>

                                <div class="mt-3">
                                    <flux:textarea
                                        wire:model="dispositionNotes"
                                        placeholder="Notes (optional)..."
                                        rows="2"
                                    />
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- Right: Contact Detail + SMS --}}
        <div class="lg:col-span-4">
            @if ($activeContact)
                <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <flux:heading size="sm">Contact Detail</flux:heading>
                    </div>

                    <div class="space-y-4 p-4">
                        <div>
                            <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $activeContact['name'] }}</div>
                        </div>

                        <div class="space-y-2 text-sm">
                            <div class="flex items-center gap-2">
                                <flux:icon name="phone" variant="micro" class="text-zinc-400" />
                                <span class="font-mono">{{ $activeContact['phone'] }}</span>
                            </div>

                            @if ($activeContact['email'] ?? null)
                                <div class="flex items-center gap-2">
                                    <flux:icon name="envelope" variant="micro" class="text-zinc-400" />
                                    <span>{{ $activeContact['email'] }}</span>
                                </div>
                            @endif

                            @if (($activeContact['city'] ?? null) && ($activeContact['state'] ?? null))
                                <div class="flex items-center gap-2">
                                    <flux:icon name="map-pin" variant="micro" class="text-zinc-400" />
                                    <span>{{ $activeContact['city'] }}, {{ $activeContact['state'] }}</span>
                                </div>
                            @endif
                        </div>

                        @if ($activeContact['tags'] ?? null)
                            <div class="flex flex-wrap gap-1">
                                @foreach ($activeContact['tags'] as $tag)
                                    <flux:badge size="sm" color="zinc">{{ $tag }}</flux:badge>
                                @endforeach
                            </div>
                        @endif

                        <div class="border-t border-zinc-200 pt-3 dark:border-zinc-700">
                            <flux:heading size="xs" class="mb-2">History</flux:heading>
                            <div class="space-y-1 text-sm text-zinc-500 dark:text-zinc-400">
                                <div class="flex justify-between">
                                    <span>Total Calls</span>
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $activeContact['total_calls'] ?? 0 }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Total SMS</span>
                                    <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $activeContact['total_sms'] ?? 0 }}</span>
                                </div>
                                @if ($activeContact['last_contacted_at'] ?? null)
                                    <div class="flex justify-between">
                                        <span>Last Contact</span>
                                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $activeContact['last_contacted_at'] }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- SMS Conversation Panel --}}
                <div class="mt-4 rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700"
                        x-on:click="smsOpen = !smsOpen"
                    >
                        <div class="flex items-center gap-2">
                            <flux:icon name="chat-bubble-left-right" variant="micro" class="text-zinc-400" />
                            <flux:heading size="sm">SMS</flux:heading>
                            @if (count($smsConversation) > 0)
                                <flux:badge size="sm" color="zinc">{{ count($smsConversation) }}</flux:badge>
                            @endif
                        </div>
                        <flux:icon
                            name="chevron-down"
                            variant="micro"
                            class="text-zinc-400 transition-transform"
                            x-bind:class="smsOpen ? 'rotate-180' : ''"
                        />
                    </button>

                    <div x-show="smsOpen" x-collapse>
                        {{-- Conversation Thread --}}
                        <div class="max-h-[300px] overflow-y-auto p-4">
                            @if (empty($smsConversation))
                                <div class="py-6 text-center text-sm text-zinc-400">
                                    No messages yet
                                </div>
                            @else
                                <div class="space-y-3">
                                    @foreach ($smsConversation as $msg)
                                        <div class="flex {{ $msg['direction'] === 'outbound' ? 'justify-end' : 'justify-start' }}">
                                            <div
                                                class="max-w-[80%] rounded-lg px-3 py-2 text-sm
                                                    @if ($msg['direction'] === 'outbound')
                                                        bg-blue-600 text-white
                                                    @else
                                                        bg-zinc-100 text-zinc-900 dark:bg-zinc-700 dark:text-zinc-100
                                                    @endif
                                                "
                                                title="{{ $msg['created_at_full'] }}"
                                            >
                                                <div>{{ $msg['body'] }}</div>
                                                <div class="mt-1 text-[10px] {{ $msg['direction'] === 'outbound' ? 'text-blue-200' : 'text-zinc-400 dark:text-zinc-500' }}">
                                                    {{ $msg['created_at'] }}
                                                    @if ($msg['direction'] === 'outbound' && $msg['status'] === 'failed')
                                                        <span class="text-red-300">- Failed</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        {{-- Send SMS Input --}}
                        <div class="border-t border-zinc-200 p-3 dark:border-zinc-700">
                            <form wire:submit="sendSms" class="flex gap-2">
                                <input
                                    type="text"
                                    wire:model="smsBody"
                                    placeholder="Type a message..."
                                    class="flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-100 dark:focus:border-blue-500"
                                />
                                <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane">
                                    Send
                                </flux:button>
                            </form>
                        </div>
                    </div>
                </div>
            @else
                <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:icon name="user-circle" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                    <flux:text class="text-zinc-400">Contact details will appear here during a call.</flux:text>
                </div>
            @endif
        </div>
    </div>
</div>
