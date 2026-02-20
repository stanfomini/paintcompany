<?php

use App\Models\Deal;
use App\Models\Job;
use App\Models\JobCostCard;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Title('AR Portal')]
class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $stageFilter = 'all';

    #[Url]
    public string $salespersonFilter = 'all';

    #[Url]
    public string $paymentFilter = 'all';

    public int $perPage = 20;

    public function boot(): void
    {
        if (! Auth::user()?->can('ar.view')) {
            abort(403);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStageFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSalespersonFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPaymentFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('ar.manage') ?? false;
    }

    #[Computed]
    public function salespersons()
    {
        return User::where('type', 'internal')
            ->whereHas('assignedDeals')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function deals()
    {
        return Deal::query()
            ->with([
                'customer',
                'property',
                'assignedTo',
                'leadGenerator',
                'job.costCards' => fn ($q) => $q->latest()->limit(1),
            ])
            ->whereHas('job')
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('title', 'like', "%{$this->search}%")
                        ->orWhereHas('customer', fn ($cq) => $cq->where('first_name', 'like', "%{$this->search}%")->orWhere('last_name', 'like', "%{$this->search}%"))
                        ->orWhereHas('property', fn ($pq) => $pq->where('address', 'like', "%{$this->search}%"));
                });
            })
            ->when($this->stageFilter !== 'all', fn ($q) => $q->where('stage', $this->stageFilter))
            ->when($this->salespersonFilter !== 'all', fn ($q) => $q->where('assigned_to', $this->salespersonFilter))
            ->when($this->paymentFilter !== 'all', function ($query) {
                $query->whereHas('job.costCards', function ($q) {
                    if ($this->paymentFilter === 'has_balance') {
                        $q->where('balance_due', '>', 0);
                    } elseif ($this->paymentFilter === 'fully_paid') {
                        $q->where('balance_due', '<=', 0)->where('total_collected', '>', 0);
                    }
                });
            })
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function summaryTotals(): array
    {
        $baseQuery = Deal::query()
            ->whereHas('job')
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('title', 'like', "%{$this->search}%")
                        ->orWhereHas('customer', fn ($cq) => $cq->where('first_name', 'like', "%{$this->search}%")->orWhere('last_name', 'like', "%{$this->search}%"));
                });
            })
            ->when($this->stageFilter !== 'all', fn ($q) => $q->where('stage', $this->stageFilter))
            ->when($this->salespersonFilter !== 'all', fn ($q) => $q->where('assigned_to', $this->salespersonFilter));

        $dealIds = $baseQuery->pluck('id');

        $costCards = JobCostCard::query()
            ->whereIn('job_id', Job::whereIn('deal_id', $dealIds)->pluck('id'))
            ->get();

        return [
            'total_contract' => round($costCards->sum('price'), 2),
            'total_invoiced' => round($costCards->sum('total_invoiced'), 2),
            'total_collected' => round($costCards->sum('total_collected'), 2),
            'total_balance' => round($costCards->sum('balance_due'), 2),
            'total_cogs' => round($costCards->sum('total_cogs'), 2),
            'deal_count' => $dealIds->count(),
        ];
    }

    public function getCostCardForDeal(Deal $deal): ?JobCostCard
    {
        return $deal->job?->costCards?->first();
    }

    public function getGrossMarginPercent(?JobCostCard $card): ?float
    {
        if (! $card || ! $card->price || $card->price == 0) {
            return null;
        }

        $grossProfit = $card->price - $card->total_cogs;

        return round(($grossProfit / $card->price) * 100, 1);
    }
};
?>

<div>
    {{-- Summary Cards --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Deals</div>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ number_format($this->summaryTotals['deal_count']) }}</div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Contract Total</div>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->summaryTotals['total_contract'], 2) }}</div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Collected</div>
            <div class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">${{ number_format($this->summaryTotals['total_collected'], 2) }}</div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium text-amber-600 dark:text-amber-400">Balance Due</div>
            <div class="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">${{ number_format($this->summaryTotals['total_balance'], 2) }}</div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Total COGS</div>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->summaryTotals['total_cogs'], 2) }}</div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Invoiced</div>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->summaryTotals['total_invoiced'], 2) }}</div>
        </div>
    </div>

    {{-- Header with inline filters --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading size="lg">AR Portal</flux:heading>

        <div class="flex flex-wrap items-center gap-2">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search..." icon="magnifying-glass" size="sm" class="w-48" />

            <flux:select wire:model.live="stageFilter" size="sm" class="w-36">
                <option value="all">All Stages</option>
                @foreach(\App\Models\Deal::STAGES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="salespersonFilter" size="sm" class="w-36">
                <option value="all">All Reps</option>
                @foreach($this->salespersons as $sp)
                    <option value="{{ $sp->id }}">{{ $sp->name }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="paymentFilter" size="sm" class="w-36">
                <option value="all">All</option>
                <option value="has_balance">Has Balance</option>
                <option value="fully_paid">Fully Paid</option>
            </flux:select>
        </div>
    </div>

    {{-- Table --}}
    @if ($this->deals->isEmpty())
        <flux:callout variant="outline" icon="document-text">
            <flux:callout.heading>No deals found</flux:callout.heading>
            <flux:callout.text>No deals with jobs found matching your filters.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Deal</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Stage</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Contract</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Collected</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Balance</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">COGS</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Margin</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                    @foreach($this->deals as $deal)
                        @php
                            $card = $this->getCostCardForDeal($deal);
                            $margin = $this->getGrossMarginPercent($card);
                        @endphp
                        <tr wire:key="deal-{{ $deal->id }}" class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50" onclick="window.location='{{ route('ar.show', $deal) }}'">
                            <td class="whitespace-nowrap px-4 py-3">
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ Str::limit($deal->title, 40) }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $deal->customer?->full_name ?? '-' }}
                                    @if($deal->assignedTo)
                                        &middot; {{ $deal->assignedTo->name }}
                                    @endif
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <flux:badge size="sm" :color="match($deal->stage) {
                                    'signed' => 'blue',
                                    'completed' => 'green',
                                    'lost', 'cancelled' => 'red',
                                    'quoted' => 'amber',
                                    default => 'zinc',
                                }">{{ $deal->stage_label }}</flux:badge>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                ${{ number_format($card?->price ?? 0, 2) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-emerald-600 dark:text-emerald-400">
                                ${{ number_format($card?->total_collected ?? 0, 2) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium {{ ($card?->balance_due ?? 0) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-500' }}">
                                ${{ number_format($card?->balance_due ?? 0, 2) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">
                                ${{ number_format($card?->total_cogs ?? 0, 2) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium {{ $margin !== null && $margin >= 40 ? 'text-emerald-600 dark:text-emerald-400' : ($margin !== null && $margin < 30 ? 'text-red-600 dark:text-red-400' : 'text-zinc-900 dark:text-zinc-100') }}">
                                {{ $margin !== null ? number_format($margin, 1) . '%' : '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $this->deals->links() }}
        </div>
    @endif
</div>
