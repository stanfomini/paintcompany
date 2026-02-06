<?php

use App\Models\Customer;
use App\Models\Estimate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.customer', ['title' => 'Estimates'])] class extends Component {
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public int $perPage = 15;

    #[On('estimate-updated')]
    public function refreshList(): void
    {
        // Re-render is triggered automatically
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function estimates()
    {
        $customer = Customer::query()
            ->where('user_id', auth('customer')->id())
            ->first();

        if (! $customer) {
            return collect();
        }

        return Estimate::query()
            ->whereHas('deal', fn ($q) => $q->where('customer_id', $customer->id))
            ->whereIn('status', ['sent', 'accepted', 'rejected', 'expired'])
            ->with('deal')
            ->when($this->search, fn ($q) => $q->search($this->search))
            ->when($this->status, fn ($q) => $q->byStatus($this->status))
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function statuses(): array
    {
        return [
            'sent' => 'Pending',
            'accepted' => 'Accepted',
            'rejected' => 'Declined',
            'expired' => 'Expired',
        ];
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Estimates') }}</flux:heading>
        </div>

        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="{{ __('Search by estimate number...') }}"
                    icon="magnifying-glass"
                />
            </div>
            <div class="w-full sm:w-48">
                <flux:select wire:model.live="status">
                    <option value="">{{ __('All Statuses') }}</option>
                    @foreach ($this->statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($this->estimates instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->estimates->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Estimate #') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Project') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Date') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Status') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Total') }}
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
                                    <flux:text class="font-medium">{{ $estimate->estimate_number }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $estimate->deal->title }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $estimate->created_at->format('M d, Y') }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                        $statusColor = match($estimate->status) {
                                            'sent' => 'yellow',
                                            'accepted' => 'green',
                                            'rejected' => 'red',
                                            'expired' => 'zinc',
                                            default => 'zinc',
                                        };
                                        $statusLabel = match($estimate->status) {
                                            'sent' => 'Pending',
                                            'accepted' => 'Accepted',
                                            'rejected' => 'Declined',
                                            'expired' => 'Expired',
                                            default => $estimate->status_label,
                                        };
                                    @endphp
                                    <flux:badge size="sm" color="{{ $statusColor }}">
                                        {{ $statusLabel }}
                                    </flux:badge>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:text class="font-semibold">${{ number_format($estimate->total, 2) }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:button variant="ghost" size="sm" icon="eye" :href="route('customer.estimates.show', $estimate)" wire:navigate>
                                        {{ __('View') }}
                                    </flux:button>
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
                    @if ($search || $status)
                        {{ __('Try adjusting your search criteria.') }}
                    @else
                        {{ __('Estimates will appear here once sent to you.') }}
                    @endif
                </flux:text>
            </div>
        @endif
    </div>
</div>
