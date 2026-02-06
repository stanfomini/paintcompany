<?php

use App\Models\Deal;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.agent', ['title' => 'My Deals'])] class extends Component {
    use WithPagination;

    public string $search = '';

    public string $stageFilter = '';

    public int $perPage = 15;

    /**
     * Default commission rate for agents (10%).
     */
    protected float $commissionRate = 0.10;

    #[On('deal-created')]
    #[On('deal-updated')]
    public function refreshList(): void
    {
        // Re-render is triggered automatically
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStageFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Get the badge color for a stage.
     */
    public function getStageBadgeColor(string $stage): string
    {
        return match ($stage) {
            'lead' => 'blue',
            'quoted' => 'purple',
            'scheduled' => 'yellow',
            'in_progress' => 'orange',
            'completed' => 'green',
            'paid' => 'emerald',
            'cancelled' => 'red',
            default => 'zinc',
        };
    }

    /**
     * Calculate commission for a deal value.
     */
    public function calculateCommission(?float $value): float
    {
        if ($value === null) {
            return 0;
        }

        return $value * $this->commissionRate;
    }

    #[Computed]
    public function deals()
    {
        return Deal::query()
            ->where('agent_id', auth('agent')->id())
            ->with(['customer'])
            ->search($this->search)
            ->when($this->stageFilter, function ($query) {
                return $query->byStage($this->stageFilter);
            })
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function stages(): array
    {
        return Deal::STAGES;
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">My Deals</flux:heading>
        </div>

        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="Search by deal title or customer name..."
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="stageFilter">
                    <option value="">All Stages</option>
                    @foreach ($this->stages as $stageKey => $stageLabel)
                        <option value="{{ $stageKey }}">{{ $stageLabel }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($this->deals->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Customer
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Deal Title
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Stage
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Value / Commission
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Expected Start
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Expected End
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->deals as $deal)
                            <tr wire:key="deal-{{ $deal->id }}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($deal->customer)
                                        <flux:button variant="ghost" size="sm" :href="route('agent.customers.show', $deal->customer)" wire:navigate class="!px-0 !justify-start">
                                            <flux:text class="font-medium">{{ $deal->customer->full_name }}</flux:text>
                                        </flux:button>
                                    @else
                                        <flux:text class="text-zinc-400">-</flux:text>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="font-medium">{{ $deal->title }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:badge size="sm" color="{{ $this->getStageBadgeColor($deal->stage) }}">
                                        {{ $deal->stage_label }}
                                    </flux:badge>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($deal->estimated_value)
                                        <div class="flex flex-col">
                                            <flux:text class="font-medium">${{ number_format($deal->estimated_value, 0) }}</flux:text>
                                            <flux:text class="text-xs text-green-600 dark:text-green-400">
                                                Commission: ${{ number_format($this->calculateCommission($deal->estimated_value), 0) }}
                                            </flux:text>
                                        </div>
                                    @else
                                        <flux:text class="text-zinc-400">-</flux:text>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $deal->expected_start_date?->format('M d, Y') ?? '-' }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $deal->expected_end_date?->format('M d, Y') ?? '-' }}</flux:text>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->deals->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="currency-dollar" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">No deals found</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $stageFilter)
                        Try adjusting your search or filter criteria.
                    @else
                        Deals from your referred customers will appear here.
                    @endif
                </flux:text>
            </div>
        @endif
    </div>
</div>
