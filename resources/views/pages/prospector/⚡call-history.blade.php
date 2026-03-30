<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Company;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Call History')]
class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $companyFilter = '';

    #[Url]
    public string $agentFilter = '';

    #[Url]
    public string $directionFilter = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $dispositionFilter = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public string $sortBy = 'started_at';

    #[Url]
    public string $sortDir = 'desc';

    public ?int $expandedCallId = null;

    public ?int $playingCallId = null;

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function calls(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Call::query()
            ->with(['agent.user', 'contact', 'company', 'campaign'])
            ->when($this->search, function ($q) {
                $q->where(function ($sub) {
                    $sub->whereHas('contact', fn ($c) => $c->where('first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('phone', 'like', '%'.$this->search.'%'))
                        ->orWhere('from_number', 'like', '%'.$this->search.'%')
                        ->orWhere('to_number', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter))
            ->when($this->agentFilter, fn ($q) => $q->where('agent_id', $this->agentFilter))
            ->when($this->directionFilter, fn ($q) => $q->where('direction', $this->directionFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dispositionFilter, fn ($q) => $q->where('disposition', $this->dispositionFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('started_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('started_at', '<=', $this->dateTo))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(25);
    }

    #[Computed]
    public function companies(): \Illuminate\Database\Eloquent\Collection
    {
        return Company::query()->orderBy('name')->get();
    }

    #[Computed]
    public function agents(): \Illuminate\Database\Eloquent\Collection
    {
        return Agent::query()
            ->with('user')
            ->when($this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter))
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function dispositions(): array
    {
        return config('prospector.dispositions', []);
    }

    #[Computed]
    public function stats(): array
    {
        $baseQuery = Call::query()
            ->when($this->search, function ($q) {
                $q->where(function ($sub) {
                    $sub->whereHas('contact', fn ($c) => $c->where('first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('phone', 'like', '%'.$this->search.'%'))
                        ->orWhere('from_number', 'like', '%'.$this->search.'%')
                        ->orWhere('to_number', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter))
            ->when($this->agentFilter, fn ($q) => $q->where('agent_id', $this->agentFilter))
            ->when($this->directionFilter, fn ($q) => $q->where('direction', $this->directionFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dispositionFilter, fn ($q) => $q->where('disposition', $this->dispositionFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('started_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('started_at', '<=', $this->dateTo));

        $total = (clone $baseQuery)->count();
        $connected = (clone $baseQuery)->where('status', 'completed')->count();
        $avgDuration = (clone $baseQuery)->whereNotNull('duration_seconds')->avg('duration_seconds');
        $totalTalk = (clone $baseQuery)->sum('talk_seconds');

        return [
            'total' => $total,
            'connected' => $connected,
            'avg_duration' => $avgDuration ? (int) round($avgDuration) : 0,
            'total_talk' => (int) $totalTalk,
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCompanyFilter(): void
    {
        $this->agentFilter = '';
        unset($this->agents);
        $this->resetPage();
    }

    public function updatedAgentFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDirectionFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDispositionFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function sortByColumn(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->companyFilter = '';
        $this->agentFilter = '';
        $this->directionFilter = '';
        $this->statusFilter = '';
        $this->dispositionFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->resetPage();
        $this->clearCaches();
    }

    public function toggleExpand(int $callId): void
    {
        $this->expandedCallId = $this->expandedCallId === $callId ? null : $callId;
    }

    public function togglePlayer(int $callId): void
    {
        $this->playingCallId = $this->playingCallId === $callId ? null : $callId;
    }

    protected function clearCaches(): void
    {
        unset($this->calls, $this->stats, $this->agents);
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;

        if ($minutes < 60) {
            return $minutes.'m '.$secs.'s';
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        return $hours.'h '.$mins.'m';
    }

    protected function getStatusBadge(string $status): array
    {
        return match ($status) {
            'completed' => ['color' => 'green', 'label' => __('Completed')],
            'in_progress' => ['color' => 'blue', 'label' => __('In Progress')],
            'no_answer', 'no-answer' => ['color' => 'amber', 'label' => __('No Answer')],
            'busy' => ['color' => 'orange', 'label' => __('Busy')],
            'failed' => ['color' => 'red', 'label' => __('Failed')],
            'voicemail' => ['color' => 'purple', 'label' => __('Voicemail')],
            'canceled' => ['color' => 'zinc', 'label' => __('Canceled')],
            'ringing' => ['color' => 'cyan', 'label' => __('Ringing')],
            default => ['color' => 'zinc', 'label' => ucfirst($status)],
        };
    }

    protected function getDirectionBadge(string $direction): array
    {
        return match ($direction) {
            'outbound' => ['color' => 'blue', 'icon' => 'arrow-up-right', 'label' => __('Outbound')],
            'inbound' => ['color' => 'green', 'icon' => 'arrow-down-left', 'label' => __('Inbound')],
            default => ['color' => 'zinc', 'icon' => 'phone', 'label' => ucfirst($direction)],
        };
    }

    protected function getDispositionBadge(?string $disposition): array
    {
        if (! $disposition) {
            return ['color' => 'zinc', 'label' => '-'];
        }

        return match ($disposition) {
            'interested' => ['color' => 'green', 'label' => __('Interested')],
            'callback' => ['color' => 'blue', 'label' => __('Callback')],
            'not_interested' => ['color' => 'red', 'label' => __('Not Interested')],
            'no_answer' => ['color' => 'amber', 'label' => __('No Answer')],
            'voicemail' => ['color' => 'purple', 'label' => __('Voicemail')],
            'wrong_number' => ['color' => 'orange', 'label' => __('Wrong Number')],
            'dnc' => ['color' => 'red', 'label' => __('DNC')],
            'busy' => ['color' => 'amber', 'label' => __('Busy')],
            'disconnected' => ['color' => 'zinc', 'label' => __('Disconnected')],
            default => ['color' => 'zinc', 'label' => ucfirst(str_replace('_', ' ', $disposition))],
        };
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Call History') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Browse and review all call records across campaigns.') }}</flux:text>
        </div>
    </div>

    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Total Calls') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ number_format($this->stats['total']) }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Connected') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ number_format($this->stats['connected']) }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Avg Duration') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->formatDuration($this->stats['avg_duration']) }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Total Talk Time') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->formatDuration($this->stats['total_talk']) }}</flux:heading>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-end gap-4">
        <div class="w-64">
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Search contact or phone...')"
            />
        </div>

        <flux:select wire:model.live="companyFilter" class="w-48" placeholder="{{ __('All Companies') }}">
            <flux:select.option value="">{{ __('All Companies') }}</flux:select.option>
            @foreach ($this->companies as $company)
                <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="agentFilter" class="w-44" placeholder="{{ __('All Agents') }}">
            <flux:select.option value="">{{ __('All Agents') }}</flux:select.option>
            @foreach ($this->agents as $agent)
                <flux:select.option value="{{ $agent->id }}">{{ $agent->user?->name ?? __('Agent #:id', ['id' => $agent->id]) }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="directionFilter" class="w-36" placeholder="{{ __('Direction') }}">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            <flux:select.option value="outbound">{{ __('Outbound') }}</flux:select.option>
            <flux:select.option value="inbound">{{ __('Inbound') }}</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="statusFilter" class="w-36" placeholder="{{ __('Status') }}">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            <flux:select.option value="completed">{{ __('Completed') }}</flux:select.option>
            <flux:select.option value="no_answer">{{ __('No Answer') }}</flux:select.option>
            <flux:select.option value="busy">{{ __('Busy') }}</flux:select.option>
            <flux:select.option value="failed">{{ __('Failed') }}</flux:select.option>
            <flux:select.option value="voicemail">{{ __('Voicemail') }}</flux:select.option>
            <flux:select.option value="canceled">{{ __('Canceled') }}</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="dispositionFilter" class="w-44" placeholder="{{ __('Disposition') }}">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            @foreach ($this->dispositions as $key => $label)
                <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        <div class="flex items-end gap-2">
            <flux:input
                wire:model.live="dateFrom"
                type="date"
                :label="__('From')"
                class="w-36"
            />
            <flux:input
                wire:model.live="dateTo"
                type="date"
                :label="__('To')"
                class="w-36"
            />
        </div>

        @if ($search || $companyFilter || $agentFilter || $directionFilter || $statusFilter || $dispositionFilter || $dateFrom || $dateTo)
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">
                {{ __('Clear') }}
            </flux:button>
        @endif
    </div>

    {{-- Calls table --}}
    @if ($this->calls->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="phone" class="mx-auto mb-3 size-12 text-zinc-400" />
            <flux:heading size="lg">{{ __('No calls found') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Call records will appear here once dialing begins.') }}</flux:text>
        </div>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortBy === 'started_at'" :direction="$sortBy === 'started_at' ? $sortDir : null" wire:click="sortByColumn('started_at')">{{ __('Date/Time') }}</flux:table.column>
                <flux:table.column>{{ __('Direction') }}</flux:table.column>
                <flux:table.column>{{ __('Agent') }}</flux:table.column>
                <flux:table.column>{{ __('Contact') }}</flux:table.column>
                <flux:table.column>{{ __('Company') }}</flux:table.column>
                <flux:table.column>{{ __('From') }}</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'duration_seconds'" :direction="$sortBy === 'duration_seconds' ? $sortDir : null" wire:click="sortByColumn('duration_seconds')">{{ __('Duration') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Disposition') }}</flux:table.column>
                <flux:table.column>{{ __('AMD') }}</flux:table.column>
                <flux:table.column>{{ __('Recording') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->calls as $call)
                    <flux:table.row wire:key="call-{{ $call->id }}" wire:click="toggleExpand({{ $call->id }})" class="cursor-pointer">
                        <flux:table.cell>
                            @if ($call->started_at)
                                <span class="text-sm" title="{{ $call->started_at->format('M j, Y g:i:s A') }}">
                                    {{ $call->started_at->format('M j, g:i A') }}
                                </span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($call->direction)
                                @php $dirBadge = $this->getDirectionBadge($call->direction); @endphp
                                <flux:badge size="sm" :color="$dirBadge['color']" :icon="$dirBadge['icon']">{{ $dirBadge['label'] }}</flux:badge>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($call->agent?->user)
                                <span class="text-sm">{{ $call->agent->user->name }}</span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($call->contact)
                                <div>
                                    <span class="text-sm font-medium">{{ $call->contact->getFullName() }}</span>
                                    <span class="block font-mono text-xs text-zinc-500">{{ $call->contact->getDisplayPhone() }}</span>
                                </div>
                            @else
                                <span class="font-mono text-sm text-zinc-400">{{ $call->to_number ?: '-' }}</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($call->company)
                                <span class="text-sm">{{ $call->company->name }}</span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="font-mono text-xs">{{ $call->from_number ?: '-' }}</span>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($call->duration_seconds)
                                <span class="text-sm">{{ $this->formatDuration($call->duration_seconds) }}</span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($call->status)
                                @php $statusBadge = $this->getStatusBadge($call->status); @endphp
                                <flux:badge size="sm" :color="$statusBadge['color']">{{ $statusBadge['label'] }}</flux:badge>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @php $dispBadge = $this->getDispositionBadge($call->disposition); @endphp
                            @if ($call->disposition)
                                <flux:badge size="sm" :color="$dispBadge['color']">{{ $dispBadge['label'] }}</flux:badge>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($call->amd_result)
                                <flux:badge size="sm" color="{{ $call->amd_result === 'human' ? 'green' : 'amber' }}">
                                    {{ ucfirst($call->amd_result) }}
                                </flux:badge>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($call->recording_url)
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="play-circle"
                                    wire:click.stop="togglePlayer({{ $call->id }})"
                                    title="{{ __('Play Recording') }}"
                                />
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>

                    {{-- Recording player (inline) --}}
                    @if ($playingCallId === $call->id && $call->recording_url)
                        <flux:table.row wire:key="player-{{ $call->id }}">
                            <flux:table.cell colspan="11">
                                <div class="flex items-center gap-3 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-900">
                                    <flux:icon name="musical-note" class="size-5 text-zinc-500" />
                                    <audio controls autoplay preload="auto" class="h-8 flex-1">
                                        <source src="{{ $call->recording_url }}" type="audio/mpeg" />
                                        {{ __('Your browser does not support the audio element.') }}
                                    </audio>
                                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="togglePlayer({{ $call->id }})" />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endif

                    {{-- Expanded detail row --}}
                    @if ($expandedCallId === $call->id)
                        <flux:table.row wire:key="detail-{{ $call->id }}">
                            <flux:table.cell colspan="11">
                                <div class="grid gap-6 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900 sm:grid-cols-3">
                                    {{-- Contact info --}}
                                    <div>
                                        <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Contact Info') }}</flux:text>
                                        @if ($call->contact)
                                            <div class="space-y-1 text-sm">
                                                <div><span class="font-medium">{{ $call->contact->getFullName() }}</span></div>
                                                <div class="font-mono text-zinc-600 dark:text-zinc-400">{{ $call->contact->getDisplayPhone() }}</div>
                                                @if ($call->contact->email)
                                                    <div class="text-zinc-600 dark:text-zinc-400">{{ $call->contact->email }}</div>
                                                @endif
                                                @if ($call->contact->address)
                                                    <div class="text-zinc-600 dark:text-zinc-400">{{ $call->contact->address }}</div>
                                                @endif
                                            </div>
                                        @else
                                            <flux:text class="text-sm text-zinc-400">{{ __('No contact linked') }}</flux:text>
                                        @endif
                                    </div>

                                    {{-- Disposition notes --}}
                                    <div>
                                        <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Disposition Notes') }}</flux:text>
                                        @if ($call->disposition_notes)
                                            <flux:text class="text-sm">{{ $call->disposition_notes }}</flux:text>
                                        @else
                                            <flux:text class="text-sm text-zinc-400">{{ __('No notes recorded.') }}</flux:text>
                                        @endif

                                        @if ($call->campaign)
                                            <flux:text class="mt-3 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Campaign') }}</flux:text>
                                            <flux:text class="text-sm">{{ $call->campaign->name }}</flux:text>
                                        @endif
                                    </div>

                                    {{-- Call timeline --}}
                                    <div>
                                        <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Call Timeline') }}</flux:text>
                                        <div class="space-y-2 text-sm">
                                            @if ($call->started_at)
                                                <div class="flex items-center gap-2">
                                                    <span class="inline-block size-2 rounded-full bg-blue-500"></span>
                                                    <span class="text-zinc-500">{{ __('Started') }}</span>
                                                    <span>{{ $call->started_at->format('g:i:s A') }}</span>
                                                </div>
                                            @endif
                                            @if ($call->answered_at)
                                                <div class="flex items-center gap-2">
                                                    <span class="inline-block size-2 rounded-full bg-green-500"></span>
                                                    <span class="text-zinc-500">{{ __('Answered') }}</span>
                                                    <span>{{ $call->answered_at->format('g:i:s A') }}</span>
                                                </div>
                                            @endif
                                            @if ($call->ended_at)
                                                <div class="flex items-center gap-2">
                                                    <span class="inline-block size-2 rounded-full bg-red-500"></span>
                                                    <span class="text-zinc-500">{{ __('Ended') }}</span>
                                                    <span>{{ $call->ended_at->format('g:i:s A') }}</span>
                                                </div>
                                            @endif
                                            @if ($call->duration_seconds)
                                                <div class="mt-1 text-xs text-zinc-500">
                                                    {{ __('Total: :duration', ['duration' => $this->formatDuration($call->duration_seconds)]) }}
                                                    @if ($call->talk_seconds)
                                                        &middot; {{ __('Talk: :talk', ['talk' => $this->formatDuration($call->talk_seconds)]) }}
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endif
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="mt-6">
            {{ $this->calls->links() }}
        </div>
    @endif
</div>
