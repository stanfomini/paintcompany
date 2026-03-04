<?php

use App\Models\Deal;
use App\Models\Estimate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public function boot(): void
    {
        if (! auth()->user()?->can('estimates.view')) {
            abort(403);
        }
    }

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = 'all';

    #[Url]
    public ?int $dealFilter = null;

    public int $perPage = 15;

    public function getListeners(): array
    {
        return [
            'estimate-created' => 'refreshList',
            'estimate-updated' => 'refreshList',
            'echo-private:internal-team,.estimate.status.changed' => 'refreshList',
        ];
    }

    public function refreshList(): void
    {
        unset($this->estimates);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDealFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function estimates()
    {
        return Estimate::query()
            ->with(['deal.customer', 'createdBy'])
            ->search($this->search)
            ->when($this->statusFilter !== 'all', function ($query) {
                return $query->byStatus($this->statusFilter);
            })
            ->when($this->dealFilter, function ($query) {
                return $query->where('deal_id', $this->dealFilter);
            })
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function deals()
    {
        return Deal::query()
            ->select('id', 'title', 'customer_id')
            ->with('customer:id,first_name,last_name')
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function statuses()
    {
        return Estimate::STATUSES;
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Estimates') }}</flux:heading>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    :placeholder="__('Search estimates...')"
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="statusFilter">
                    <option value="all">{{ __('All Statuses') }}</option>
                    @foreach ($this->statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="sm:w-64">
                <flux:select wire:model.live="dealFilter">
                    <option value="">{{ __('All Deals') }}</option>
                    @foreach ($this->deals as $deal)
                        <option value="{{ $deal->id }}">{{ $deal->title }} - {{ $deal->customer?->full_name ?? __('No customer') }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($this->estimates->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Estimate #') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Title') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Customer') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Mode') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Status') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Total') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Valid Until') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->estimates as $estimate)
                            <tr wire:key="estimate-{{ $estimate->id }}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="font-mono text-sm">{{ $estimate->estimate_number }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="{{ route('estimates.show', $estimate) }}" class="text-blue-600 hover:underline" wire:navigate>
                                        {{ $estimate->title }}
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $estimate->deal?->customer?->full_name ?? '-' }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                        $modeColor = match($estimate->estimator_mode) {
                                            'simple' => 'emerald',
                                            'template' => 'blue',
                                            'detailed' => 'purple',
                                            default => 'zinc',
                                        };
                                        $modeLabel = match($estimate->estimator_mode) {
                                            'simple' => __('Quick'),
                                            'template' => __('Template'),
                                            'detailed' => __('Detailed'),
                                            default => __('Standard'),
                                        };
                                    @endphp
                                    <flux:badge size="sm" color="{{ $modeColor }}">{{ $modeLabel }}</flux:badge>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                        $statusColor = match($estimate->status) {
                                            'draft' => 'zinc',
                                            'sent' => 'blue',
                                            'accepted' => 'green',
                                            'rejected' => 'red',
                                            'expired' => 'yellow',
                                            default => 'zinc',
                                        };
                                    @endphp
                                    <flux:badge size="sm" color="{{ $statusColor }}">{{ $estimate->status_label }}</flux:badge>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:text class="font-medium">${{ number_format($estimate->total, 2) }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $estimate->valid_until?->format('M j, Y') ?? '-' }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" :href="route('estimates.show', $estimate)" wire:navigate>{{ __('View') }}</flux:menu.item>
                                            @if ($estimate->isEditable())
                                                @php
                                                    $editRoute = match($estimate->estimator_mode) {
                                                        'simple' => route('estimates.edit.simple', $estimate),
                                                        'template' => route('estimates.edit.template', $estimate),
                                                        default => route('estimates.edit', $estimate),
                                                    };
                                                @endphp
                                                <flux:menu.item icon="pencil" :href="$editRoute" wire:navigate>{{ __('Edit') }}</flux:menu.item>
                                            @endif
                                            <flux:menu.item icon="document-arrow-down" :href="route('estimates.pdf', $estimate)" target="_blank">{{ __('Download PDF') }}</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->estimates->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="document-text" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">{{ __('No estimates found') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $statusFilter !== 'all' || $dealFilter)
                        {{ __('Try adjusting your search or filter criteria.') }}
                    @else
                        {{ __('Estimates will appear here once they are created from deals.') }}
                    @endif
                </flux:text>
            </div>
        @endif
    </div>
</div>
