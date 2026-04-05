<?php

use App\Events\Prospector\SupervisorJoined;
use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\DialerSession;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\Pipeline;
use App\Models\Prospector\SmsMessage;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\ConferenceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Prospector Dashboard')]
class extends Component
{
    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function onboardingSteps(): array
    {
        return [
            ['label' => 'Connect Twilio Account', 'done' => TwilioAccount::where('is_active', true)->exists(), 'route' => 'prospector.settings'],
            ['label' => 'Create a Company', 'done' => Company::where('is_active', true)->exists(), 'route' => 'prospector.companies'],
            ['label' => 'Assign Phone Numbers', 'done' => PhoneNumber::whereNotNull('company_id')->exists(), 'route' => 'prospector.phone-numbers'],
            ['label' => 'Add Contacts', 'done' => Contact::exists(), 'route' => 'prospector.contacts'],
            ['label' => 'Create a Campaign', 'done' => Campaign::where('status', 'active')->exists(), 'route' => 'prospector.campaigns'],
            ['label' => 'Set Up Pipeline', 'done' => Pipeline::exists(), 'route' => 'prospector.pipelines'],
            ['label' => 'Start Dialing!', 'done' => DialerSession::exists(), 'route' => 'prospector.dialer'],
        ];
    }

    #[Computed]
    public function isOnboardingComplete(): bool
    {
        return collect($this->onboardingSteps)->every(fn (array $step) => $step['done']);
    }

    #[Computed]
    public function todayStats(): array
    {
        $todayCalls = Call::today();
        $yesterdayCalls = Call::whereDate('started_at', today()->subDay());

        $totalToday = $todayCalls->count();
        $totalYesterday = $yesterdayCalls->count();

        $connectedToday = (clone $todayCalls)->whereNotNull('answered_at')->count();
        $connectedYesterday = (clone $yesterdayCalls)->whereNotNull('answered_at')->count();

        $talkSecondsToday = (clone $todayCalls)->sum('talk_seconds');

        $connectionRate = $totalToday > 0
            ? round(($connectedToday / $totalToday) * 100, 1)
            : 0;
        $yesterdayRate = $totalYesterday > 0
            ? round(($connectedYesterday / $totalYesterday) * 100, 1)
            : 0;

        return [
            'total_calls' => $totalToday,
            'total_calls_trend' => $totalToday - $totalYesterday,
            'connected' => $connectedToday,
            'connected_trend' => $connectedToday - $connectedYesterday,
            'connection_rate' => $connectionRate,
            'rate_trend' => round($connectionRate - $yesterdayRate, 1),
            'talk_time_seconds' => (int) $talkSecondsToday,
        ];
    }

    #[Computed]
    public function bookingStats(): array
    {
        $bookedDispositions = ['booked', 'interested', 'appointment_set'];

        // Today
        $todayCalls = Call::today()->where('direction', 'outbound');
        $todayTotal = (clone $todayCalls)->whereNotNull('answered_at')->count();
        $todayBooked = (clone $todayCalls)->whereIn('disposition', $bookedDispositions)->count();
        $todayRate = $todayTotal > 0 ? round(($todayBooked / $todayTotal) * 100, 1) : 0;

        // This week
        $weekCalls = Call::where('direction', 'outbound')->where('started_at', '>=', now()->startOfWeek());
        $weekTotal = (clone $weekCalls)->whereNotNull('answered_at')->count();
        $weekBooked = (clone $weekCalls)->whereIn('disposition', $bookedDispositions)->count();
        $weekRate = $weekTotal > 0 ? round(($weekBooked / $weekTotal) * 100, 1) : 0;

        // This month
        $monthCalls = Call::where('direction', 'outbound')->where('started_at', '>=', now()->startOfMonth());
        $monthTotal = (clone $monthCalls)->whereNotNull('answered_at')->count();
        $monthBooked = (clone $monthCalls)->whereIn('disposition', $bookedDispositions)->count();
        $monthRate = $monthTotal > 0 ? round(($monthBooked / $monthTotal) * 100, 1) : 0;

        return [
            'today' => ['booked' => $todayBooked, 'connected' => $todayTotal, 'rate' => $todayRate],
            'week' => ['booked' => $weekBooked, 'connected' => $weekTotal, 'rate' => $weekRate],
            'month' => ['booked' => $monthBooked, 'connected' => $monthTotal, 'rate' => $monthRate],
        ];
    }

    #[Computed]
    public function dispositionBreakdown(): array
    {
        return Call::today()
            ->where('direction', 'outbound')
            ->whereNotNull('disposition')
            ->selectRaw('disposition, count(*) as total')
            ->groupBy('disposition')
            ->orderByDesc('total')
            ->pluck('total', 'disposition')
            ->toArray();
    }

    #[Computed]
    public function operationalStats(): array
    {
        return [
            'active_agents' => Agent::whereIn('status', ['available', 'on_call', 'ringing', 'wrap_up', 'break'])->distinct('user_id')->count('user_id'),
            'active_sessions' => DialerSession::whereIn('status', ['active', 'paused'])->count(),
            'contacts_in_queue' => CampaignContact::where('status', 'queued')
                ->whereHas('campaign', fn ($q) => $q->where('status', 'active'))
                ->count(),
            'sms_today' => SmsMessage::whereDate('created_at', today())->count(),
        ];
    }

    #[Computed]
    public function activeSessions(): \Illuminate\Support\Collection
    {
        return DialerSession::query()
            ->whereIn('status', ['active', 'paused'])
            ->with(['agent.user', 'campaign'])
            ->orderByDesc('started_at')
            ->get();
    }

    #[Computed]
    public function recentCalls(): \Illuminate\Support\Collection
    {
        return Call::query()
            ->where('started_at', '>=', now()->subHour())
            ->with(['agent.user', 'contact', 'campaign'])
            ->orderByDesc('started_at')
            ->limit(30)
            ->get();
    }

    #[Computed]
    public function campaignPerformance(): \Illuminate\Support\Collection
    {
        return Campaign::query()
            ->active()
            ->with('company')
            ->withCount(['campaignContacts as queued_count' => fn ($q) => $q->queued()])
            ->orderByDesc('priority')
            ->get();
    }

    #[Computed]
    public function agentPerformance(): \Illuminate\Support\Collection
    {
        $bookedDispositions = ['booked', 'interested', 'appointment_set'];

        // Get all online agent records, grouped by user to avoid duplicates
        $agents = Agent::query()
            ->with('user')
            ->whereIn('status', ['available', 'on_call', 'ringing', 'wrap_up', 'break'])
            ->get()
            ->groupBy('user_id');

        return $agents->map(function ($agentGroup) use ($bookedDispositions) {
            // Use the most "active" agent record for status display
            $primaryAgent = $agentGroup->sortBy(fn ($a) => match ($a->status) {
                'on_call' => 0, 'ringing' => 1, 'available' => 2, 'wrap_up' => 3, default => 4,
            })->first();

            // Aggregate calls across ALL agent records for this user
            $agentIds = $agentGroup->pluck('id');
            $todayCalls = Call::whereIn('agent_id', $agentIds)
                ->where('direction', 'outbound')
                ->whereDate('started_at', today());

            $totalCalls = (clone $todayCalls)->count();
            $connected = (clone $todayCalls)->whereNotNull('answered_at')->count();
            $booked = (clone $todayCalls)->whereIn('disposition', $bookedDispositions)->count();
            $talkSeconds = (clone $todayCalls)->sum('talk_seconds');

            $primaryAgent->setAttribute('stats_calls', $totalCalls);
            $primaryAgent->setAttribute('stats_connected', $connected);
            $primaryAgent->setAttribute('stats_booked', $booked);
            $primaryAgent->setAttribute('stats_book_rate', $connected > 0 ? round(($booked / $connected) * 100, 1) : 0);
            $primaryAgent->setAttribute('stats_talk_seconds', (int) $talkSeconds);

            return $primaryAgent;
        })->sortByDesc('stats_calls')->values();
    }

    /**
     * Called by wire:poll to force-refresh all dashboard data.
     */
    public function refreshDashboard(): void
    {
        unset(
            $this->todayStats,
            $this->bookingStats,
            $this->dispositionBreakdown,
            $this->operationalStats,
            $this->activeSessions,
            $this->recentCalls,
            $this->campaignPerformance,
            $this->agentPerformance,
            $this->liveCalls,
        );
    }

    public function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }

    public function formatTalkTime(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    protected function getStatusColor(string $status): string
    {
        return match ($status) {
            'available' => 'green',
            'on_call' => 'blue',
            'wrap_up' => 'amber',
            'break' => 'yellow',
            'offline' => 'zinc',
            default => 'zinc',
        };
    }

    protected function getDispositionColor(string $disposition): string
    {
        return match ($disposition) {
            'interested', 'appointment_set' => 'green',
            'callback', 'callback_requested' => 'blue',
            'not_interested', 'do_not_call' => 'red',
            'no_answer', 'voicemail' => 'zinc',
            'busy', 'wrong_number' => 'amber',
            default => 'zinc',
        };
    }

    // ──────────────────────────────────────
    // Supervisor Features
    // ──────────────────────────────────────

    public ?string $supervisorMode = null; // null, listen, whisper, barge
    public ?int $supervisorSessionId = null;
    public ?string $supervisorCallSid = null;

    #[Computed]
    public function liveCalls(): \Illuminate\Support\Collection
    {
        return Call::query()
            ->whereIn('status', ['ringing', 'in-progress', 'connected'])
            ->whereNotNull('twilio_conference_sid')
            ->with(['agent.user', 'contact'])
            ->orderByDesc('started_at')
            ->get();
    }

    public function listenToCall(int $callId): void
    {
        $this->supervisorAction($callId, 'listen');
    }

    public function whisperToAgent(int $callId): void
    {
        $this->supervisorAction($callId, 'whisper');
    }

    public function bargeIntoCall(int $callId): void
    {
        $this->supervisorAction($callId, 'barge');
    }

    public function leaveSupervisorMode(): void
    {
        if ($this->supervisorCallSid && $this->supervisorSessionId) {
            $call = Call::find($this->supervisorSessionId);

            if ($call && $call->twilio_conference_sid) {
                app(ConferenceService::class)->removeParticipant(
                    $call->twilio_conference_sid,
                    $this->supervisorCallSid
                );
            }
        }

        $this->supervisorMode = null;
        $this->supervisorSessionId = null;
        $this->supervisorCallSid = null;
    }

    protected function supervisorAction(int $callId, string $mode): void
    {
        $call = Call::findOrFail($callId);

        if (! $call->twilio_conference_sid || ! $call->agent_id) {
            return;
        }

        $user = Auth::user();
        $company = Company::where('is_active', true)->first();

        if (! $company) {
            return;
        }

        $supervisor = Agent::firstOrCreate(
            ['user_id' => $user->id, 'company_id' => $company->id],
            ['status' => 'offline', 'max_concurrent_lines' => 1, 'wrap_up_seconds' => 30],
        );

        // Leave existing supervisor mode if active
        if ($this->supervisorCallSid) {
            $this->leaveSupervisorMode();
        }

        $service = app(ConferenceService::class);

        $this->supervisorCallSid = match ($mode) {
            'listen' => $service->supervisorListen($call->twilio_conference_sid, $supervisor),
            'whisper' => $service->supervisorWhisper($call->twilio_conference_sid, $supervisor, $call->twilio_call_sid),
            'barge' => $service->supervisorBarge($call->twilio_conference_sid, $supervisor),
        };

        $this->supervisorMode = $mode;
        $this->supervisorSessionId = $callId;

        broadcast(new SupervisorJoined($supervisor, $call->agent_id, $mode));
    }
}; ?>

<div
    x-data="{
        init() {
            if (typeof Echo !== 'undefined') {
                // Listen to all company channels for real-time updates
                @foreach (\App\Models\Prospector\Company::where('is_active', true)->pluck('id') as $companyId)
                    Echo.private('prospector.company.{{ $companyId }}')
                        .listen('.queue.updated', () => { $wire.refreshDashboard(); })
                        .listen('.lead.arrived', () => { $wire.refreshDashboard(); })
                        .listen('.sms.received', () => { $wire.refreshDashboard(); });
                @endforeach

                // Listen to all agent channels for call events
                @foreach (\App\Models\Prospector\Agent::pluck('id') as $agentId)
                    Echo.private('prospector.agent.{{ $agentId }}')
                        .listen('.dialer.call.ringing', () => { $wire.refreshDashboard(); })
                        .listen('.dialer.call.connected', () => { $wire.refreshDashboard(); })
                        .listen('.dialer.call.ended', () => { $wire.refreshDashboard(); })
                        .listen('.inbound.call.ringing', () => { $wire.refreshDashboard(); });
                @endforeach
            }

            // Backup refresh every 30 seconds in case websocket misses something
            setInterval(() => { $wire.refreshDashboard(); }, 30000);
        }
    }"
>
    <div class="mb-6">
        <flux:heading size="xl">{{ __('Prospector Dashboard') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Real-time overview of calling operations and agent performance.') }}</flux:text>
    </div>

    {{-- Onboarding Checklist (hidden when all complete) --}}
    @unless ($this->isOnboardingComplete)
        <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-5 dark:border-blue-800 dark:bg-blue-900/20">
            <div class="mb-3 flex items-center gap-2">
                <flux:icon name="rocket-launch" variant="mini" class="text-blue-600 dark:text-blue-400" />
                <flux:heading size="sm" class="text-blue-900 dark:text-blue-100">{{ __('Getting Started') }}</flux:heading>
                <span class="ml-auto text-sm text-blue-600 dark:text-blue-400">
                    {{ collect($this->onboardingSteps)->filter(fn ($s) => $s['done'])->count() }}/{{ count($this->onboardingSteps) }} {{ __('complete') }}
                </span>
            </div>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($this->onboardingSteps as $step)
                    <a
                        href="{{ route($step['route']) }}"
                        wire:navigate
                        class="flex items-center gap-2 rounded-md px-3 py-2 text-sm transition {{ $step['done'] ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 'bg-white text-zinc-700 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}"
                    >
                        @if ($step['done'])
                            <flux:icon name="check-circle" variant="mini" class="size-5 shrink-0 text-green-600 dark:text-green-400" />
                        @else
                            <span class="flex size-5 shrink-0 items-center justify-center rounded-full border-2 border-zinc-300 dark:border-zinc-600"></span>
                        @endif
                        <span class="{{ $step['done'] ? 'line-through opacity-70' : 'font-medium' }}">{{ $step['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endunless

    {{-- Top Row: Today's Call Stats --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Total Calls Today') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ number_format($this->todayStats['total_calls']) }}</flux:heading>
            @if ($this->todayStats['total_calls_trend'] !== 0)
                <div class="mt-1 text-xs {{ $this->todayStats['total_calls_trend'] > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                    {{ $this->todayStats['total_calls_trend'] > 0 ? '+' : '' }}{{ $this->todayStats['total_calls_trend'] }} {{ __('vs yesterday') }}
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Calls Connected') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ number_format($this->todayStats['connected']) }}</flux:heading>
            @if ($this->todayStats['connected_trend'] !== 0)
                <div class="mt-1 text-xs {{ $this->todayStats['connected_trend'] > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                    {{ $this->todayStats['connected_trend'] > 0 ? '+' : '' }}{{ $this->todayStats['connected_trend'] }} {{ __('vs yesterday') }}
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Connection Rate') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->todayStats['connection_rate'] }}%</flux:heading>
            @if ($this->todayStats['rate_trend'] != 0)
                <div class="mt-1 text-xs {{ $this->todayStats['rate_trend'] > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                    {{ $this->todayStats['rate_trend'] > 0 ? '+' : '' }}{{ $this->todayStats['rate_trend'] }}% {{ __('vs yesterday') }}
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Total Talk Time') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->formatTalkTime($this->todayStats['talk_time_seconds']) }}</flux:heading>
        </div>
    </div>

    {{-- Second Row: Operational Stats --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Active Agents') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->operationalStats['active_agents'] }}</flux:heading>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Active Sessions') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->operationalStats['active_sessions'] }}</flux:heading>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Contacts in Queue') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ number_format($this->operationalStats['contacts_in_queue']) }}</flux:heading>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('SMS Sent Today') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ number_format($this->operationalStats['sms_today']) }}</flux:heading>
        </div>
    </div>

    {{-- Booking Rate Stats --}}
    <div class="mb-6 grid grid-cols-3 gap-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Booked Today') }}</flux:text>
            <div class="mt-1 flex items-baseline gap-2">
                <flux:heading size="xl" class="text-green-600 dark:text-green-400">{{ $this->bookingStats['today']['rate'] }}%</flux:heading>
                <span class="text-sm text-zinc-500">{{ $this->bookingStats['today']['booked'] }}/{{ $this->bookingStats['today']['connected'] }} {{ __('connected') }}</span>
            </div>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Booked This Week') }}</flux:text>
            <div class="mt-1 flex items-baseline gap-2">
                <flux:heading size="xl" class="text-blue-600 dark:text-blue-400">{{ $this->bookingStats['week']['rate'] }}%</flux:heading>
                <span class="text-sm text-zinc-500">{{ $this->bookingStats['week']['booked'] }}/{{ $this->bookingStats['week']['connected'] }}</span>
            </div>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Booked This Month') }}</flux:text>
            <div class="mt-1 flex items-baseline gap-2">
                <flux:heading size="xl" class="text-purple-600 dark:text-purple-400">{{ $this->bookingStats['month']['rate'] }}%</flux:heading>
                <span class="text-sm text-zinc-500">{{ $this->bookingStats['month']['booked'] }}/{{ $this->bookingStats['month']['connected'] }}</span>
            </div>
        </div>
    </div>

    {{-- Disposition Breakdown + Agent Performance side by side --}}
    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        {{-- Disposition Breakdown --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <flux:heading size="sm">{{ __("Today's Disposition Breakdown") }}</flux:heading>
            </div>
            <div class="p-4">
                @if (empty($this->dispositionBreakdown))
                    <flux:text class="py-4 text-center text-zinc-400">{{ __('No dispositions yet today.') }}</flux:text>
                @else
                    <div class="space-y-2">
                        @php $dispTotal = array_sum($this->dispositionBreakdown); @endphp
                        @foreach ($this->dispositionBreakdown as $disposition => $count)
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <flux:badge size="sm" color="{{ $this->getDispositionColor($disposition) }}">{{ str_replace('_', ' ', ucfirst($disposition)) }}</flux:badge>
                                </div>
                                <div class="flex items-center gap-3">
                                    <div class="h-2 w-24 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700">
                                        <div class="h-full rounded-full bg-zinc-400" style="width: {{ round(($count / $dispTotal) * 100) }}%"></div>
                                    </div>
                                    <span class="w-8 text-right font-mono text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $count }}</span>
                                    <span class="w-10 text-right text-xs text-zinc-400">{{ round(($count / $dispTotal) * 100) }}%</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Agent Performance --}}
        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <flux:heading size="sm">{{ __('Agent Performance Today') }}</flux:heading>
            </div>
            <div class="p-4">
                @if ($this->agentPerformance->isEmpty())
                    <flux:text class="py-4 text-center text-zinc-400">{{ __('No agents online.') }}</flux:text>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Agent') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Calls') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Connected') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Booked') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Book %') }}</flux:table.column>
                            <flux:table.column class="text-right">{{ __('Talk Time') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->agentPerformance as $agent)
                                <flux:table.row wire:key="perf-{{ $agent->id }}">
                                    <flux:table.cell>
                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex size-2 rounded-full {{ match($agent->status) { 'available' => 'bg-green-500', 'on_call' => 'bg-blue-500', 'ringing' => 'bg-amber-500', default => 'bg-zinc-400' } }}"></span>
                                            <span class="font-medium">{{ $agent->user?->name ?? 'Unknown' }}</span>
                                        </div>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-right font-mono">{{ $agent->stats_calls }}</flux:table.cell>
                                    <flux:table.cell class="text-right font-mono">{{ $agent->stats_connected }}</flux:table.cell>
                                    <flux:table.cell class="text-right font-mono text-green-600 dark:text-green-400">{{ $agent->stats_booked }}</flux:table.cell>
                                    <flux:table.cell class="text-right font-mono font-bold {{ $agent->stats_book_rate >= 10 ? 'text-green-600 dark:text-green-400' : 'text-zinc-500' }}">{{ $agent->stats_book_rate }}%</flux:table.cell>
                                    <flux:table.cell class="text-right font-mono">{{ $this->formatTalkTime($agent->stats_talk_seconds) }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </div>
        </div>
    </div>

    {{-- Live Calls Panel (Supervisor) --}}
    <div class="mb-6">
        <div class="mb-3 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <flux:heading size="lg">{{ __('Live Calls') }}</flux:heading>
                @if ($this->liveCalls->isNotEmpty())
                    <flux:badge size="sm" color="green">{{ $this->liveCalls->count() }}</flux:badge>
                @endif
            </div>
            @if ($supervisorMode)
                <div class="flex items-center gap-2">
                    <flux:badge size="sm" color="{{ $supervisorMode === 'barge' ? 'red' : ($supervisorMode === 'whisper' ? 'amber' : 'blue') }}">
                        {{ ucfirst($supervisorMode) }} Mode
                    </flux:badge>
                    <flux:button size="sm" variant="danger" icon="x-mark" wire:click="leaveSupervisorMode" wire:loading.attr="disabled" wire:target="leaveSupervisorMode">
                        {{ __('Leave') }}
                    </flux:button>
                </div>
            @endif
        </div>

        @if ($this->liveCalls->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <flux:icon name="phone" class="mx-auto mb-2 size-10 text-zinc-400" />
                <flux:text>{{ __('No live calls at the moment.') }}</flux:text>
                <flux:text class="mt-1 text-xs text-zinc-400">{{ __('Active calls will appear here with listen, whisper, and barge controls.') }}</flux:text>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($this->liveCalls as $liveCall)
                    <div
                        wire:key="live-{{ $liveCall->id }}"
                        class="flex items-center justify-between rounded-lg border px-4 py-3
                            {{ $supervisorSessionId === $liveCall->id
                                ? 'border-blue-300 bg-blue-50 dark:border-blue-700 dark:bg-blue-900/20'
                                : 'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800'
                            }}"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex size-2.5 rounded-full {{ $liveCall->status === 'connected' || $liveCall->status === 'in-progress' ? 'bg-green-500' : 'bg-amber-500 animate-pulse' }}"></span>
                                <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $liveCall->agent?->user?->name ?? __('Unknown Agent') }}
                                </span>
                                <span class="text-zinc-400">--</span>
                                <span class="text-sm text-zinc-600 dark:text-zinc-300">
                                    {{ ucfirst($liveCall->status) }}
                                </span>
                            </div>
                            <div class="mt-0.5 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($liveCall->contact)
                                    <span>{{ $liveCall->contact->getFullName() }}</span>
                                    <span class="text-zinc-300 dark:text-zinc-600">|</span>
                                    <span class="font-mono">{{ $liveCall->contact->getDisplayPhone() }}</span>
                                @endif
                                @if ($liveCall->started_at)
                                    <span class="text-zinc-300 dark:text-zinc-600">|</span>
                                    <span>{{ $liveCall->started_at->diffForHumans(syntax: true) }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="ml-3 flex shrink-0 items-center gap-1">
                            <flux:button
                                size="sm"
                                variant="{{ $supervisorSessionId === $liveCall->id && $supervisorMode === 'listen' ? 'primary' : 'ghost' }}"
                                icon="eye"
                                wire:click="listenToCall({{ $liveCall->id }})"
                                wire:loading.attr="disabled"
                                wire:target="listenToCall({{ $liveCall->id }})"
                                title="{{ __('Listen') }}"
                            />
                            <flux:button
                                size="sm"
                                variant="{{ $supervisorSessionId === $liveCall->id && $supervisorMode === 'whisper' ? 'primary' : 'ghost' }}"
                                icon="chat-bubble-bottom-center-text"
                                wire:click="whisperToAgent({{ $liveCall->id }})"
                                wire:loading.attr="disabled"
                                wire:target="whisperToAgent({{ $liveCall->id }})"
                                title="{{ __('Whisper') }}"
                            />
                            <flux:button
                                size="sm"
                                variant="{{ $supervisorSessionId === $liveCall->id && $supervisorMode === 'barge' ? 'danger' : 'ghost' }}"
                                icon="megaphone"
                                wire:click="bargeIntoCall({{ $liveCall->id }})"
                                wire:loading.attr="disabled"
                                wire:target="bargeIntoCall({{ $liveCall->id }})"
                                title="{{ __('Barge') }}"
                            />
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Online Agents --}}
    <div class="mb-6">
        <div class="mb-3 flex items-center gap-2">
            <flux:heading size="lg">{{ __('Online Agents') }}</flux:heading>
            @if ($this->agentPerformance->isNotEmpty())
                <flux:badge size="sm" color="green">{{ $this->agentPerformance->count() }}</flux:badge>
            @endif
        </div>

        @if ($this->agentPerformance->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <flux:icon name="users" class="mx-auto mb-2 size-10 text-zinc-400" />
                <flux:text>{{ __('No agents online.') }}</flux:text>
            </div>
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->agentPerformance as $agent)
                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex size-3 rounded-full {{ match($agent->status) { 'available' => 'bg-green-500', 'on_call' => 'bg-blue-500 animate-pulse', 'ringing' => 'bg-amber-500 animate-pulse', 'break' => 'bg-orange-500', default => 'bg-zinc-400' } }}"></span>
                            <div>
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $agent->user?->name ?? 'Unknown' }}</div>
                                <div class="text-xs text-zinc-500">{{ ucfirst(str_replace('_', ' ', $agent->status)) }}</div>
                            </div>
                        </div>
                        <div class="text-right text-xs text-zinc-500">
                            <div>{{ $agent->stats_calls }} {{ __('calls') }}</div>
                            <div class="font-medium {{ $agent->stats_booked > 0 ? 'text-green-600 dark:text-green-400' : '' }}">{{ $agent->stats_booked }} {{ __('booked') }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Active Sessions Panel --}}
    <div class="mb-6">
        <flux:heading size="lg" class="mb-3">{{ __('Active Sessions') }}</flux:heading>
        @if ($this->activeSessions->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <flux:icon name="phone-x-mark" class="mx-auto mb-2 size-10 text-zinc-400" />
                <flux:text>{{ __('No active dialer sessions right now.') }}</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Agent') }}</flux:table.column>
                    <flux:table.column>{{ __('Campaign') }}</flux:table.column>
                    <flux:table.column>{{ __('Mode') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Attempted') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Connected') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Talk Time') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Duration') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->activeSessions as $session)
                        <flux:table.row wire:key="session-{{ $session->id }}">
                            <flux:table.cell>
                                <span class="font-medium">{{ $session->agent?->user?->name ?? __('Unknown') }}</span>
                            </flux:table.cell>

                            <flux:table.cell>
                                {{ $session->campaign?->name ?? __('N/A') }}
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($session->mode === 'parallel')
                                    <flux:badge size="sm" color="purple">{{ __('Parallel') }} ({{ $session->parallel_lines }}x)</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Single') }}</flux:badge>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:badge size="sm" color="green">{{ ucfirst($session->status) }}</flux:badge>
                            </flux:table.cell>

                            <flux:table.cell class="text-right">
                                {{ number_format($session->calls_attempted) }}
                            </flux:table.cell>

                            <flux:table.cell class="text-right">
                                {{ number_format($session->calls_connected) }}
                            </flux:table.cell>

                            <flux:table.cell class="text-right">
                                {{ $this->formatDuration($session->total_talk_seconds ?? 0) }}
                            </flux:table.cell>

                            <flux:table.cell class="text-right">
                                @if ($session->started_at)
                                    <span title="{{ $session->started_at->format('g:i A') }}">
                                        {{ $session->started_at->diffForHumans(syntax: true) }}
                                    </span>
                                @else
                                    <span class="text-zinc-400">--</span>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        {{-- Recent Activity Feed --}}
        <div>
            <flux:heading size="lg" class="mb-3">{{ __('Last Hour Activity') }}</flux:heading>
            @if ($this->recentCalls->isEmpty())
                <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:icon name="phone" class="mx-auto mb-2 size-10 text-zinc-400" />
                    <flux:text>{{ __('No calls recorded yet.') }}</flux:text>
                </div>
            @else
                <div class="space-y-2">
                    @foreach ($this->recentCalls as $call)
                        <div wire:key="call-{{ $call->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $call->agent?->user?->name ?? __('Unknown') }}
                                    </span>
                                    <span class="text-zinc-400">→</span>
                                    <span class="truncate text-sm text-zinc-600 dark:text-zinc-300">
                                        @if ($call->contact)
                                            {{ $call->contact->first_name }} {{ $call->contact->last_name }}
                                        @else
                                            {{ $call->to_number }}
                                        @endif
                                    </span>
                                </div>
                                <div class="mt-0.5 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                    @if ($call->campaign)
                                        <span>{{ $call->campaign->name }}</span>
                                        <span class="text-zinc-300 dark:text-zinc-600">|</span>
                                    @endif
                                    @if ($call->started_at)
                                        <span>{{ $call->started_at->format('g:i A') }}</span>
                                    @endif
                                    @if ($call->duration_seconds)
                                        <span class="text-zinc-300 dark:text-zinc-600">|</span>
                                        <span>{{ $this->formatDuration($call->duration_seconds) }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="ml-3 flex shrink-0 items-center gap-2">
                                @if ($call->disposition)
                                    <flux:badge size="sm" :color="$this->getDispositionColor($call->disposition)">
                                        {{ str_replace('_', ' ', ucfirst($call->disposition)) }}
                                    </flux:badge>
                                @elseif ($call->status)
                                    <flux:badge size="sm" color="zinc">{{ ucfirst($call->status) }}</flux:badge>
                                @endif
                                @if ($call->recording_url)
                                    <flux:button size="sm" variant="ghost" icon="play" title="{{ __('Play Recording') }}" />
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    </div>

    {{-- Campaign Performance Summary --}}
    <div class="mb-6">
        <flux:heading size="lg" class="mb-3">{{ __('Active Campaigns') }}</flux:heading>
        @if ($this->campaignPerformance->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <flux:icon name="megaphone" class="mx-auto mb-2 size-10 text-zinc-400" />
                <flux:text>{{ __('No active campaigns.') }}</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Campaign') }}</flux:table.column>
                    <flux:table.column>{{ __('Company') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Queued') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Attempted') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Connected') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Rate') }}</flux:table.column>
                    <flux:table.column>{{ __('Progress') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Priority') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->campaignPerformance as $campaign)
                        <flux:table.row wire:key="campaign-{{ $campaign->id }}">
                            <flux:table.cell>
                                <a href="{{ route('prospector.campaigns.show', $campaign->id) }}" class="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                    {{ $campaign->name }}
                                </a>
                            </flux:table.cell>

                            <flux:table.cell>
                                {{ $campaign->company?->name ?? __('N/A') }}
                            </flux:table.cell>

                            <flux:table.cell class="text-right">
                                {{ number_format($campaign->queued_count) }}
                            </flux:table.cell>

                            <flux:table.cell class="text-right">
                                {{ number_format($campaign->calls_attempted) }}
                            </flux:table.cell>

                            <flux:table.cell class="text-right">
                                {{ number_format($campaign->calls_connected) }}
                            </flux:table.cell>

                            <flux:table.cell class="text-right">
                                @if ($campaign->calls_attempted > 0)
                                    {{ $campaign->getConnectionRate() }}%
                                @else
                                    <span class="text-zinc-400">--</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                @php
                                    $total = $campaign->contact_count ?: 1;
                                    $completed = $campaign->calls_attempted;
                                    $pct = min(100, round(($completed / $total) * 100));
                                @endphp
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-full max-w-[100px] overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                        <div class="h-2 rounded-full bg-blue-500" style="width: {{ $pct }}%"></div>
                                    </div>
                                    <span class="text-xs text-zinc-500">{{ $pct }}%</span>
                                </div>
                            </flux:table.cell>

                            <flux:table.cell class="text-right">
                                {{ $campaign->priority }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</div>
