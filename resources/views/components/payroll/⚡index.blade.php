<?php

use App\Models\PayPeriod;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Payroll')]
class extends Component {
    use WithPagination;

    public function boot(): void
    {
        if (! auth()->user()?->can('payroll.view')) {
            abort(403);
        }
    }

    public string $statusFilter = 'all';

    public string $typeFilter = 'all';

    public bool $showCreateModal = false;

    public string $newStartDate = '';

    public string $newEndDate = '';

    public string $newType = 'biweekly';

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function payPeriods()
    {
        return PayPeriod::query()
            ->withCount('payStubs')
            ->with('processedBy')
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->typeFilter !== 'all', fn ($q) => $q->where('type', $this->typeFilter))
            ->orderByDesc('start_date')
            ->paginate(15);
    }

    public function openCreateModal(): void
    {
        if (! auth()->user()?->can('payroll.manage')) {
            abort(403);
        }

        $this->newStartDate = now()->startOfWeek()->toDateString();
        $this->newEndDate = now()->endOfWeek()->addWeek()->toDateString();
        $this->newType = 'biweekly';
        $this->showCreateModal = true;
    }

    public function createPayPeriod(): void
    {
        if (! auth()->user()?->can('payroll.manage')) {
            abort(403);
        }

        $this->validate([
            'newStartDate' => ['required', 'date'],
            'newEndDate' => ['required', 'date', 'after:newStartDate'],
            'newType' => ['required', 'in:weekly,biweekly,semimonthly,monthly'],
        ]);

        app(\App\Services\PayrollService::class)->createPayPeriod(
            $this->newStartDate,
            $this->newEndDate,
            $this->newType
        );

        $this->showCreateModal = false;
        $this->dispatch('$refresh');
    }
};
?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">Payroll</flux:heading>
            <flux:text class="mt-1">Manage pay periods and run payroll.</flux:text>
        </div>

        @can('payroll.manage')
            <flux:button variant="primary" wire:click="openCreateModal">
                New Pay Period
            </flux:button>
        @endcan
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex items-center gap-4">
        <flux:select wire:model.live="statusFilter" class="w-40">
            <option value="all">All Statuses</option>
            <option value="open">Open</option>
            <option value="processing">Processing</option>
            <option value="completed">Completed</option>
        </flux:select>

        <flux:select wire:model.live="typeFilter" class="w-40">
            <option value="all">All Types</option>
            <option value="weekly">Weekly</option>
            <option value="biweekly">Biweekly</option>
            <option value="semimonthly">Semi-Monthly</option>
            <option value="monthly">Monthly</option>
        </flux:select>
    </div>

    {{-- Pay Periods Table --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Period</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Type</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Pay Stubs</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Processed</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse($this->payPeriods as $period)
                    <tr wire:key="period-{{ $period->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">
                            {{ $period->start_date->format('M j') }} - {{ $period->end_date->format('M j, Y') }}
                        </td>
                        <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                            {{ ucfirst($period->type) }}
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge
                                :variant="match($period->status) { 'open' => 'warning', 'processing' => 'primary', 'completed' => 'success', default => 'outline' }"
                                size="sm"
                            >
                                {{ ucfirst($period->status) }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                            {{ $period->pay_stubs_count }}
                        </td>
                        <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                            @if($period->processedBy)
                                {{ $period->processedBy->name }}
                                <br>
                                <span class="text-xs">{{ $period->processed_at?->format('M j, Y g:ia') }}</span>
                            @else
                                --
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if($period->isOpen())
                                    @can('payroll.manage')
                                        <flux:button size="sm" variant="primary" href="{{ route('payroll.run', $period) }}">
                                            Run Payroll
                                        </flux:button>
                                    @endcan
                                @else
                                    <flux:button size="sm" variant="ghost" href="{{ route('payroll.run', $period) }}">
                                        View
                                    </flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                            No pay periods found. Create one to get started.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->payPeriods->links() }}
    </div>

    {{-- Create Pay Period Modal --}}
    <flux:modal wire:model="showCreateModal">
        <div class="space-y-6">
            <flux:heading size="lg">New Pay Period</flux:heading>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Start Date</flux:label>
                    <flux:input type="date" wire:model="newStartDate" />
                    <flux:error name="newStartDate" />
                </flux:field>

                <flux:field>
                    <flux:label>End Date</flux:label>
                    <flux:input type="date" wire:model="newEndDate" />
                    <flux:error name="newEndDate" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Pay Period Type</flux:label>
                <flux:select wire:model="newType">
                    <option value="weekly">Weekly</option>
                    <option value="biweekly">Biweekly</option>
                    <option value="semimonthly">Semi-Monthly</option>
                    <option value="monthly">Monthly</option>
                </flux:select>
                <flux:error name="newType" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showCreateModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="createPayPeriod">Create Pay Period</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
