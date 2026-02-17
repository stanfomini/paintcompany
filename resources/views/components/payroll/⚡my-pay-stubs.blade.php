<?php

use App\Models\PayStub;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('My Pay Stubs')]
class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->employeeProfile) {
            abort(403);
        }
    }

    #[Computed]
    public function payStubs()
    {
        return PayStub::query()
            ->where('employee_profile_id', auth()->user()->employeeProfile->id)
            ->with('payPeriod')
            ->latest()
            ->get();
    }

    #[Computed]
    public function ytdTotals(): array
    {
        $yearStubs = $this->payStubs->filter(fn ($s) => $s->payPeriod?->start_date?->year === now()->year);

        return [
            'gross' => $yearStubs->sum('gross_pay'),
            'deductions' => $yearStubs->sum('total_deductions'),
            'net' => $yearStubs->sum('net_pay'),
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <flux:heading size="xl">{{ __('My Pay Stubs') }}</flux:heading>
        <flux:text class="mt-1">{{ __('View your earnings and deductions history.') }}</flux:text>
    </div>

    {{-- YTD Summary Cards --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('YTD Gross Pay') }}</div>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->ytdTotals['gross'], 2) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('YTD Deductions') }}</div>
            <div class="mt-1 text-2xl font-bold text-red-600 dark:text-red-400">${{ number_format($this->ytdTotals['deductions'], 2) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('YTD Net Pay') }}</div>
            <div class="mt-1 text-2xl font-bold text-green-700 dark:text-green-400">${{ number_format($this->ytdTotals['net'], 2) }}</div>
        </div>
    </div>

    {{-- Pay Stubs Table --}}
    @if($this->payStubs->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="banknotes" class="mx-auto mb-3 size-10 text-zinc-400 dark:text-zinc-500" />
            <flux:heading size="lg">{{ __('No Pay Stubs Yet') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Your pay stubs will appear here once payroll has been processed.') }}</flux:text>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Pay Period') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Gross Pay') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Deductions') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Net Pay') }}</th>
                        <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Check #') }}</th>
                        <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach($this->payStubs as $stub)
                        <tr wire:key="stub-{{ $stub->id }}">
                            <td class="px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">
                                {{ $stub->payPeriod?->start_date?->format('M j') }} - {{ $stub->payPeriod?->end_date?->format('M j, Y') }}
                            </td>
                            <td class="px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">${{ number_format($stub->gross_pay, 2) }}</td>
                            <td class="px-4 py-3 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($stub->total_deductions, 2) }}</td>
                            <td class="px-4 py-3 text-right text-sm font-medium text-green-700 dark:text-green-400">${{ number_format($stub->net_pay, 2) }}</td>
                            <td class="px-4 py-3 text-center text-sm text-zinc-500 dark:text-zinc-400">{{ $stub->check_number ?? '-' }}</td>
                            <td class="px-4 py-3 text-center">
                                <flux:badge :color="$stub->isFinalized() ? 'green' : 'amber'" size="sm">
                                    {{ ucfirst($stub->status) }}
                                </flux:badge>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <flux:button variant="subtle" size="sm" :href="route('payroll.stub', $stub)" wire:navigate>
                                    {{ __('View') }}
                                </flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
