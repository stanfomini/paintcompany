<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\DialerSession;
use App\Models\Prospector\SmsMessage;
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
    public function operationalStats(): array
    {
        return [
            'active_agents' => Agent::online()->count(),
            'active_sessions' => DialerSession::active()->count(),
            'contacts_in_queue' => CampaignContact::queued()
                ->whereHas('campaign', fn ($q) => $q->active())
                ->count(),
            'sms_today' => SmsMessage::whereDate('created_at', today())->count(),
        ];
    }

    #[Computed]
    public function activeSessions(): \Illuminate\Support\Collection
    {
        return DialerSession::query()
            ->active()
            ->with(['agent.user', 'campaign'])
            ->orderByDesc('started_at')
            ->get();
    }

    #[Computed]
    public function recentCalls(): \Illuminate\Support\Collection
    {
        return Call::query()
            ->with(['agent.user', 'contact', 'campaign'])
            ->orderByDesc('started_at')
            ->limit(20)
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
        return Agent::query()
            ->online()
            ->with('user')
            ->orderByDesc('daily_call_count')
            ->get();
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
}; ?>

<div wire:poll.10s>
    <div class="mb-6">
        <flux:heading size="xl">{{ __('Prospector Dashboard') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Real-time overview of calling operations and agent performance.') }}</flux:text>
    </div>

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
            <flux:heading size="lg" class="mb-3">{{ __('Recent Activity') }}</flux:heading>
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

        {{-- Agent Performance (Today) --}}
        <div>
            <flux:heading size="lg" class="mb-3">{{ __('Agent Performance') }} <span class="text-sm font-normal text-zinc-500">({{ __('Today') }})</span></flux:heading>
            @if ($this->agentPerformance->isEmpty())
                <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:icon name="users" class="mx-auto mb-2 size-10 text-zinc-400" />
                    <flux:text>{{ __('No agents online.') }}</flux:text>
                </div>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Agent') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Calls') }}</flux:table.column>
                        <flux:table.column class="text-right">{{ __('Talk Min') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->agentPerformance as $agent)
                            <flux:table.row wire:key="agent-{{ $agent->id }}">
                                <flux:table.cell>
                                    <span class="font-medium">{{ $agent->user?->name ?? __('Unknown') }}</span>
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge size="sm" :color="$this->getStatusColor($agent->status)">
                                        {{ ucfirst(str_replace('_', ' ', $agent->status)) }}
                                    </flux:badge>
                                </flux:table.cell>

                                <flux:table.cell class="text-right">
                                    {{ number_format($agent->daily_call_count) }}
                                </flux:table.cell>

                                <flux:table.cell class="text-right">
                                    {{ number_format($agent->daily_talk_minutes) }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
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
