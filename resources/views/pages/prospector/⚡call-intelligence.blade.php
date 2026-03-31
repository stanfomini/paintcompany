<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\CallIntelligence;
use App\Models\Prospector\Company;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Call Intelligence')]
class extends Component
{
    use WithPagination;

    #[Url]
    public string $companyFilter = '';

    #[Url]
    public string $agentFilter = '';

    #[Url]
    public string $scoreFilter = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $sortBy = 'processed_at';

    #[Url]
    public string $sortDir = 'desc';

    public ?int $expandedId = null;

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function records(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return CallIntelligence::query()
            ->with(['call.agent.user', 'call.contact', 'call.company'])
            ->whereNotNull('processed_at')
            ->when($this->search, function ($q) {
                $q->where(function ($sub) {
                    $sub->where('ai_summary', 'like', '%'.$this->search.'%')
                        ->orWhere('transcription_raw', 'like', '%'.$this->search.'%')
                        ->orWhereHas('call.contact', fn ($c) => $c->where('first_name', 'like', '%'.$this->search.'%')
                            ->orWhere('last_name', 'like', '%'.$this->search.'%'));
                });
            })
            ->when($this->companyFilter, fn ($q) => $q->whereHas('call', fn ($c) => $c->where('company_id', $this->companyFilter)))
            ->when($this->agentFilter, fn ($q) => $q->whereHas('call', fn ($c) => $c->where('agent_id', $this->agentFilter)))
            ->when($this->scoreFilter, fn ($q) => match ($this->scoreFilter) {
                'cold' => $q->whereBetween('lead_score', [0, 30]),
                'warm' => $q->whereBetween('lead_score', [31, 60]),
                'hot' => $q->whereBetween('lead_score', [61, 100]),
                default => $q,
            })
            ->when($this->dateFrom, fn ($q) => $q->whereDate('processed_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('processed_at', '<=', $this->dateTo))
            ->orderBy($this->sortBy, $this->sortDir)
            ->paginate(20);
    }

    #[Computed]
    public function stats(): array
    {
        $base = CallIntelligence::query()->whereNotNull('processed_at');

        $avgScore = (clone $base)->avg('lead_score');
        $avgSentiment = (clone $base)->avg('sentiment_overall');
        $total = (clone $base)->count();

        $topKeywords = (clone $base)->whereNotNull('keywords')
            ->pluck('keywords')
            ->flatten()
            ->countBy()
            ->sortDesc()
            ->take(10)
            ->all();

        $topObjections = (clone $base)->whereNotNull('objections')
            ->pluck('objections')
            ->flatten()
            ->countBy()
            ->sortDesc()
            ->take(5)
            ->all();

        return [
            'total' => $total,
            'avg_score' => $avgScore !== null ? (int) round($avgScore) : 0,
            'avg_sentiment' => $avgSentiment !== null ? round((float) $avgSentiment, 2) : 0,
            'top_keywords' => $topKeywords,
            'top_objections' => $topObjections,
        ];
    }

    #[Computed]
    public function companies(): \Illuminate\Support\Collection
    {
        return Company::query()->orderBy('name')->get();
    }

    #[Computed]
    public function agents(): \Illuminate\Support\Collection
    {
        return Agent::query()
            ->with('user')
            ->when($this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter))
            ->orderBy('id')
            ->get();
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

    public function updatedScoreFilter(): void
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

    public function clearFilters(): void
    {
        $this->search = '';
        $this->companyFilter = '';
        $this->agentFilter = '';
        $this->scoreFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->resetPage();
        unset($this->records, $this->stats, $this->agents);
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

    public function toggleExpand(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    protected function getScoreBadge(?int $score): array
    {
        if ($score === null) {
            return ['color' => 'zinc', 'label' => '-'];
        }

        return match (true) {
            $score <= 30 => ['color' => 'blue', 'label' => $score.' Cold'],
            $score <= 60 => ['color' => 'amber', 'label' => $score.' Warm'],
            default => ['color' => 'red', 'label' => $score.' Hot'],
        };
    }

    protected function getSentimentLabel(?float $sentiment): string
    {
        if ($sentiment === null) {
            return '-';
        }

        return match (true) {
            $sentiment <= -0.5 => 'Very Negative',
            $sentiment <= -0.1 => 'Negative',
            $sentiment <= 0.1 => 'Neutral',
            $sentiment <= 0.5 => 'Positive',
            default => 'Very Positive',
        };
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;

        return $minutes.'m '.$secs.'s';
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Call Intelligence') }}</flux:heading>
            <flux:text class="mt-1">{{ __('AI-powered analysis of call recordings — scoring, sentiment, and coaching insights.') }}</flux:text>
        </div>
    </div>

    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Analyzed Calls') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ number_format($this->stats['total']) }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Avg Lead Score') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->stats['avg_score'] }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Avg Sentiment') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->stats['avg_sentiment'] }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Top Keywords') }}</flux:text>
            <div class="mt-1 flex flex-wrap gap-1">
                @forelse (array_slice($this->stats['top_keywords'], 0, 5) as $keyword => $count)
                    <flux:badge size="sm" color="zinc">{{ $keyword }}</flux:badge>
                @empty
                    <flux:text class="text-sm text-zinc-400">-</flux:text>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Top Objections --}}
    @if (! empty($this->stats['top_objections']))
        <div class="mb-6 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Top Objections') }}</flux:text>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->stats['top_objections'] as $objection => $count)
                    <flux:badge size="sm" color="amber">{{ $objection }} ({{ $count }})</flux:badge>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-end gap-4">
        <div class="w-64">
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Search summary or contact...')"
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

        <flux:select wire:model.live="scoreFilter" class="w-36" placeholder="{{ __('Score') }}">
            <flux:select.option value="">{{ __('All Scores') }}</flux:select.option>
            <flux:select.option value="hot">{{ __('Hot (61-100)') }}</flux:select.option>
            <flux:select.option value="warm">{{ __('Warm (31-60)') }}</flux:select.option>
            <flux:select.option value="cold">{{ __('Cold (0-30)') }}</flux:select.option>
        </flux:select>

        <div class="flex items-end gap-2">
            <flux:input wire:model.live="dateFrom" type="date" :label="__('From')" class="w-36" />
            <flux:input wire:model.live="dateTo" type="date" :label="__('To')" class="w-36" />
        </div>

        @if ($search || $companyFilter || $agentFilter || $scoreFilter || $dateFrom || $dateTo)
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters" wire:loading.attr="disabled" wire:target="clearFilters">
                {{ __('Clear') }}
            </flux:button>
        @endif
    </div>

    {{-- Table --}}
    @if ($this->records->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="sparkles" class="mx-auto mb-3 size-12 text-zinc-400" />
            <flux:heading size="lg">{{ __('No analyzed calls yet') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Call intelligence results will appear here after recordings are processed.') }}</flux:text>
        </div>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column sortable :sorted="$sortBy === 'processed_at'" :direction="$sortBy === 'processed_at' ? $sortDir : null" wire:click="sortByColumn('processed_at')">{{ __('Date') }}</flux:table.column>
                <flux:table.column>{{ __('Contact') }}</flux:table.column>
                <flux:table.column>{{ __('Agent') }}</flux:table.column>
                <flux:table.column>{{ __('Duration') }}</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'lead_score'" :direction="$sortBy === 'lead_score' ? $sortDir : null" wire:click="sortByColumn('lead_score')">{{ __('Lead Score') }}</flux:table.column>
                <flux:table.column sortable :sorted="$sortBy === 'sentiment_overall'" :direction="$sortBy === 'sentiment_overall' ? $sortDir : null" wire:click="sortByColumn('sentiment_overall')">{{ __('Sentiment') }}</flux:table.column>
                <flux:table.column>{{ __('Summary') }}</flux:table.column>
                <flux:table.column>{{ __('Keywords') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->records as $record)
                    <flux:table.row wire:key="intel-{{ $record->id }}" wire:click="toggleExpand({{ $record->id }})" class="cursor-pointer">
                        <flux:table.cell>
                            @if ($record->processed_at)
                                <span class="text-sm" title="{{ $record->processed_at->format('M j, Y g:i:s A') }}">
                                    {{ $record->processed_at->format('M j, g:i A') }}
                                </span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($record->call?->contact)
                                <div>
                                    <span class="text-sm font-medium">{{ $record->call->contact->getFullName() }}</span>
                                    <span class="block font-mono text-xs text-zinc-500">{{ $record->call->contact->getDisplayPhone() }}</span>
                                </div>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($record->call?->agent?->user)
                                <span class="text-sm">{{ $record->call->agent->user->name }}</span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($record->call?->duration_seconds)
                                <span class="text-sm">{{ $this->formatDuration($record->call->duration_seconds) }}</span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @php $badge = $this->getScoreBadge($record->lead_score); @endphp
                            <flux:badge size="sm" :color="$badge['color']">{{ $badge['label'] }}</flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="text-sm">{{ $this->getSentimentLabel($record->sentiment_overall !== null ? (float) $record->sentiment_overall : null) }}</span>
                        </flux:table.cell>

                        <flux:table.cell class="max-w-xs">
                            @if ($record->ai_summary)
                                <span class="line-clamp-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $record->ai_summary }}</span>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($record->keywords)
                                <div class="flex flex-wrap gap-1">
                                    @foreach (array_slice($record->keywords, 0, 3) as $kw)
                                        <flux:badge size="sm" color="zinc">{{ $kw }}</flux:badge>
                                    @endforeach
                                    @if (count($record->keywords) > 3)
                                        <flux:badge size="sm" color="zinc">+{{ count($record->keywords) - 3 }}</flux:badge>
                                    @endif
                                </div>
                            @else
                                <span class="text-zinc-400">-</span>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>

                    {{-- Expanded detail --}}
                    @if ($expandedId === $record->id)
                        <flux:table.row wire:key="detail-{{ $record->id }}">
                            <flux:table.cell colspan="8">
                                <div class="grid gap-6 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900 lg:grid-cols-2">
                                    {{-- Transcription --}}
                                    <div>
                                        <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Transcription') }}</flux:text>
                                        @if ($record->hasTranscription())
                                            <div class="max-h-64 overflow-y-auto rounded bg-white p-3 text-sm dark:bg-zinc-800">
                                                @if ($record->transcription_segments)
                                                    @foreach ($record->transcription_segments as $seg)
                                                        <div class="mb-2">
                                                            @if (isset($seg['speaker']))
                                                                <span class="font-semibold text-blue-600 dark:text-blue-400">{{ $seg['speaker'] }}:</span>
                                                            @endif
                                                            <span>{{ $seg['text'] ?? '' }}</span>
                                                            @if (isset($seg['start']))
                                                                <span class="ml-1 text-xs text-zinc-400">[{{ number_format($seg['start'], 1) }}s]</span>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                @else
                                                    {{ $record->transcription_raw }}
                                                @endif
                                            </div>
                                        @else
                                            <flux:text class="text-sm text-zinc-400">{{ __('No transcription available.') }}</flux:text>
                                        @endif
                                    </div>

                                    {{-- AI Summary & Coaching --}}
                                    <div class="space-y-4">
                                        <div>
                                            <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('AI Summary') }}</flux:text>
                                            <flux:text class="text-sm">{{ $record->ai_summary ?? __('Not available') }}</flux:text>
                                        </div>

                                        <div>
                                            <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Coaching Notes') }}</flux:text>
                                            <flux:text class="text-sm">{{ $record->ai_coaching_notes ?? __('Not available') }}</flux:text>
                                        </div>

                                        {{-- Speaker Labels --}}
                                        @if ($record->speaker_labels)
                                            <div>
                                                <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Speakers') }}</flux:text>
                                                <div class="flex flex-wrap gap-2">
                                                    @foreach ($record->speaker_labels as $speaker => $role)
                                                        <flux:badge size="sm" color="zinc">{{ $speaker }}: {{ $role }}</flux:badge>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Objections --}}
                                    @if ($record->objections && count($record->objections) > 0)
                                        <div>
                                            <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Objections') }}</flux:text>
                                            <ul class="list-inside list-disc space-y-1 text-sm">
                                                @foreach ($record->objections as $objection)
                                                    <li>{{ $objection }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    {{-- Action Items --}}
                                    @if ($record->action_items && count($record->action_items) > 0)
                                        <div>
                                            <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Action Items') }}</flux:text>
                                            <ul class="list-inside list-disc space-y-1 text-sm">
                                                @foreach ($record->action_items as $item)
                                                    <li>{{ $item }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    {{-- Scoring Factors --}}
                                    @if ($record->scoring_factors && count($record->scoring_factors) > 0)
                                        <div class="lg:col-span-2">
                                            <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Scoring Factors') }}</flux:text>
                                            <div class="flex flex-wrap gap-2">
                                                @foreach ($record->scoring_factors as $factor)
                                                    <flux:badge size="sm" color="zinc">
                                                        {{ $factor['factor'] ?? '' }}
                                                        @if (isset($factor['weight']))
                                                            (+{{ $factor['weight'] }})
                                                        @endif
                                                    </flux:badge>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif

                                    {{-- All Keywords --}}
                                    @if ($record->keywords && count($record->keywords) > 0)
                                        <div class="lg:col-span-2">
                                            <flux:text class="mb-2 text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('All Keywords') }}</flux:text>
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($record->keywords as $kw)
                                                    <flux:badge size="sm" color="zinc">{{ $kw }}</flux:badge>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endif
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="mt-6">
            {{ $this->records->links() }}
        </div>
    @endif
</div>
