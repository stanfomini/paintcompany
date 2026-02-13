<?php

use App\Models\CompanySetting;
use App\Services\AccountingService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $reportType = 'trial-balance';
    public string $asOfDate = '';
    public string $fromDate = '';
    public string $toDate = '';

    public function boot(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->can('accounting.view')) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $this->asOfDate = now()->format('Y-m-d');
        $this->fromDate = now()->startOfYear()->format('Y-m-d');
        $this->toDate = now()->format('Y-m-d');
    }

    #[Computed]
    public function accountingMode(): string
    {
        return CompanySetting::getInstance()->accounting_mode ?? 'cash';
    }

    #[Computed]
    public function accountingModeLabel(): string
    {
        return match ($this->accountingMode) {
            'cash' => __('Cash Basis'),
            'accrual' => __('Accrual Basis'),
            'modified_cash' => __('Modified Cash Basis'),
            default => __('Cash Basis'),
        };
    }

    #[Computed]
    public function trialBalance(): ?array
    {
        if ($this->reportType !== 'trial-balance') {
            return null;
        }

        $service = new AccountingService;

        return $service->getTrialBalance(
            \Carbon\Carbon::parse($this->asOfDate),
            $this->accountingMode,
        );
    }

    #[Computed]
    public function profitAndLoss(): ?array
    {
        if ($this->reportType !== 'profit-loss') {
            return null;
        }

        $service = new AccountingService;

        return $service->getProfitAndLoss(
            \Carbon\Carbon::parse($this->fromDate),
            \Carbon\Carbon::parse($this->toDate),
            $this->accountingMode,
        );
    }

    #[Computed]
    public function balanceSheet(): ?array
    {
        if ($this->reportType !== 'balance-sheet') {
            return null;
        }

        $service = new AccountingService;

        return $service->getBalanceSheet(
            \Carbon\Carbon::parse($this->asOfDate),
            $this->accountingMode,
        );
    }

    public function setReportType(string $type): void
    {
        $this->reportType = $type;
        unset($this->trialBalance, $this->profitAndLoss, $this->balanceSheet);
    }

    public function updatedAsOfDate(): void
    {
        unset($this->trialBalance, $this->balanceSheet);
    }

    public function updatedFromDate(): void
    {
        unset($this->profitAndLoss);
    }

    public function updatedToDate(): void
    {
        unset($this->profitAndLoss);
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="lg">{{ __('Financial Reports') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('View financial statements and reports.') }}
            </flux:text>
        </div>
        <flux:badge size="lg" color="blue">{{ $this->accountingModeLabel }}</flux:badge>
    </div>

    {{-- Report Type Tabs --}}
    <div class="mb-6 flex gap-1 border-b border-zinc-200 pb-px dark:border-zinc-700">
        @foreach ([
            'trial-balance' => __('Trial Balance'),
            'profit-loss' => __('Profit & Loss'),
            'balance-sheet' => __('Balance Sheet'),
        ] as $key => $label)
            <button
                wire:key="report-tab-{{ $key }}"
                wire:click="setReportType('{{ $key }}')"
                class="whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors
                    {{ $reportType === $key
                        ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                        : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Date Controls --}}
    <div class="mb-4 flex items-center gap-3">
        @if ($reportType === 'profit-loss')
            <flux:input wire:model.live="fromDate" type="date" class="w-44" />
            <flux:text class="text-zinc-400">{{ __('to') }}</flux:text>
            <flux:input wire:model.live="toDate" type="date" class="w-44" />
        @else
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('As of:') }}</flux:text>
            <flux:input wire:model.live="asOfDate" type="date" class="w-44" />
        @endif
    </div>

    {{-- Trial Balance --}}
    @if ($reportType === 'trial-balance' && $this->trialBalance)
        @php $tb = $this->trialBalance; @endphp
        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Code') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Account') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Debit') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Credit') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                    @foreach ($tb['accounts'] as $row)
                        @if ($row['debit'] > 0 || $row['credit'] > 0)
                            <tr wire:key="tb-{{ $row['account']->id }}">
                                <td class="px-4 py-2 text-sm font-mono text-zinc-600 dark:text-zinc-400">{{ $row['account']->code }}</td>
                                <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">{{ $row['account']->name }}</td>
                                <td class="px-4 py-2 text-right text-sm text-zinc-900 dark:text-zinc-100">
                                    {{ $row['debit'] > 0 ? '$' . number_format($row['debit'], 2) : '' }}
                                </td>
                                <td class="px-4 py-2 text-right text-sm text-zinc-900 dark:text-zinc-100">
                                    {{ $row['credit'] > 0 ? '$' . number_format($row['credit'], 2) : '' }}
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
                <tfoot class="bg-zinc-100 dark:bg-zinc-800">
                    <tr>
                        <td colspan="2" class="px-4 py-2 text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('Total') }}</td>
                        <td class="px-4 py-2 text-right text-sm font-bold text-zinc-900 dark:text-zinc-100">
                            ${{ number_format($tb['total_debits'], 2) }}
                        </td>
                        <td class="px-4 py-2 text-right text-sm font-bold text-zinc-900 dark:text-zinc-100">
                            ${{ number_format($tb['total_credits'], 2) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        @if ($tb['is_balanced'])
            <flux:callout variant="outline" icon="check-circle" class="mt-4">
                <flux:callout.heading>{{ __('Balanced') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Total debits equal total credits.') }}</flux:callout.text>
            </flux:callout>
        @else
            <flux:callout variant="outline" icon="exclamation-triangle" class="mt-4">
                <flux:callout.heading>{{ __('Unbalanced') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Warning: Total debits do not equal total credits. Difference:') }} ${{ number_format(abs($tb['total_debits'] - $tb['total_credits']), 2) }}</flux:callout.text>
            </flux:callout>
        @endif
    @endif

    {{-- Profit & Loss --}}
    @if ($reportType === 'profit-loss' && $this->profitAndLoss)
        @php $pl = $this->profitAndLoss; @endphp
        <div class="space-y-6">
            {{-- Revenue Section --}}
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                <div class="border-b border-zinc-200 bg-emerald-50 px-4 py-2 dark:border-zinc-700 dark:bg-emerald-950/30">
                    <flux:heading size="md" class="text-emerald-700 dark:text-emerald-400">{{ __('Revenue') }}</flux:heading>
                </div>
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($pl['revenue'] as $item)
                        @if ($item['amount'] != 0)
                            <div class="flex items-center justify-between px-4 py-2">
                                <span class="text-sm text-zinc-900 dark:text-zinc-100">{{ $item['account']->code }} - {{ $item['account']->name }}</span>
                                <span class="text-sm font-medium text-emerald-600 dark:text-emerald-400">${{ number_format($item['amount'], 2) }}</span>
                            </div>
                        @endif
                    @empty
                        <div class="px-4 py-2">
                            <flux:text class="text-zinc-400">{{ __('No revenue recorded for this period.') }}</flux:text>
                        </div>
                    @endforelse
                    <div class="flex items-center justify-between bg-emerald-50 px-4 py-2 dark:bg-emerald-950/20">
                        <span class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('Total Revenue') }}</span>
                        <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400">${{ number_format($pl['total_revenue'], 2) }}</span>
                    </div>
                </div>
            </div>

            {{-- Expenses Section --}}
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                <div class="border-b border-zinc-200 bg-red-50 px-4 py-2 dark:border-zinc-700 dark:bg-red-950/30">
                    <flux:heading size="md" class="text-red-700 dark:text-red-400">{{ __('Expenses') }}</flux:heading>
                </div>
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($pl['expenses'] as $item)
                        @if ($item['amount'] != 0)
                            <div class="flex items-center justify-between px-4 py-2">
                                <span class="text-sm text-zinc-900 dark:text-zinc-100">{{ $item['account']->code }} - {{ $item['account']->name }}</span>
                                <span class="text-sm font-medium text-red-600 dark:text-red-400">${{ number_format($item['amount'], 2) }}</span>
                            </div>
                        @endif
                    @empty
                        <div class="px-4 py-2">
                            <flux:text class="text-zinc-400">{{ __('No expenses recorded for this period.') }}</flux:text>
                        </div>
                    @endforelse
                    <div class="flex items-center justify-between bg-red-50 px-4 py-2 dark:bg-red-950/20">
                        <span class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('Total Expenses') }}</span>
                        <span class="text-sm font-bold text-red-600 dark:text-red-400">${{ number_format($pl['total_expenses'], 2) }}</span>
                    </div>
                </div>
            </div>

            {{-- Net Income --}}
            <div class="rounded-lg border-2 {{ $pl['net_income'] >= 0 ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-950/30' : 'border-red-300 bg-red-50 dark:border-red-700 dark:bg-red-950/30' }} p-4 text-center">
                <flux:text class="text-sm uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Net Income') }}</flux:text>
                <p class="text-3xl font-bold {{ $pl['net_income'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                    ${{ number_format($pl['net_income'], 2) }}
                </p>
            </div>
        </div>
    @endif

    {{-- Balance Sheet --}}
    @if ($reportType === 'balance-sheet' && $this->balanceSheet)
        @php $bs = $this->balanceSheet; @endphp
        <div class="grid gap-6 lg:grid-cols-2">
            {{-- Assets --}}
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                <div class="border-b border-zinc-200 bg-blue-50 px-4 py-2 dark:border-zinc-700 dark:bg-blue-950/30">
                    <flux:heading size="md" class="text-blue-700 dark:text-blue-400">{{ __('Assets') }}</flux:heading>
                </div>
                <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @forelse ($bs['assets'] as $item)
                        @if ($item['balance'] != 0)
                            <div class="flex items-center justify-between px-4 py-2">
                                <span class="text-sm text-zinc-900 dark:text-zinc-100">{{ $item['account']->name }}</span>
                                <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($item['balance'], 2) }}</span>
                            </div>
                        @endif
                    @empty
                        <div class="px-4 py-2"><flux:text class="text-zinc-400">{{ __('No assets.') }}</flux:text></div>
                    @endforelse
                    <div class="flex items-center justify-between bg-blue-50 px-4 py-2 dark:bg-blue-950/20">
                        <span class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('Total Assets') }}</span>
                        <span class="text-sm font-bold text-blue-600 dark:text-blue-400">${{ number_format($bs['total_assets'], 2) }}</span>
                    </div>
                </div>
            </div>

            {{-- Liabilities & Equity --}}
            <div class="space-y-6">
                {{-- Liabilities --}}
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div class="border-b border-zinc-200 bg-amber-50 px-4 py-2 dark:border-zinc-700 dark:bg-amber-950/30">
                        <flux:heading size="md" class="text-amber-700 dark:text-amber-400">{{ __('Liabilities') }}</flux:heading>
                    </div>
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($bs['liabilities'] as $item)
                            @if ($item['balance'] != 0)
                                <div class="flex items-center justify-between px-4 py-2">
                                    <span class="text-sm text-zinc-900 dark:text-zinc-100">{{ $item['account']->name }}</span>
                                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($item['balance'], 2) }}</span>
                                </div>
                            @endif
                        @empty
                            <div class="px-4 py-2"><flux:text class="text-zinc-400">{{ __('No liabilities.') }}</flux:text></div>
                        @endforelse
                        <div class="flex items-center justify-between bg-amber-50 px-4 py-2 dark:bg-amber-950/20">
                            <span class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('Total Liabilities') }}</span>
                            <span class="text-sm font-bold text-amber-600 dark:text-amber-400">${{ number_format($bs['total_liabilities'], 2) }}</span>
                        </div>
                    </div>
                </div>

                {{-- Equity --}}
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <div class="border-b border-zinc-200 bg-purple-50 px-4 py-2 dark:border-zinc-700 dark:bg-purple-950/30">
                        <flux:heading size="md" class="text-purple-700 dark:text-purple-400">{{ __('Equity') }}</flux:heading>
                    </div>
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @forelse ($bs['equity'] as $item)
                            @if ($item['balance'] != 0)
                                <div class="flex items-center justify-between px-4 py-2">
                                    <span class="text-sm text-zinc-900 dark:text-zinc-100">{{ $item['account']->name }}</span>
                                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($item['balance'], 2) }}</span>
                                </div>
                            @endif
                        @empty
                            <div class="px-4 py-2"><flux:text class="text-zinc-400">{{ __('No equity.') }}</flux:text></div>
                        @endforelse
                        <div class="flex items-center justify-between bg-purple-50 px-4 py-2 dark:bg-purple-950/20">
                            <span class="text-sm font-bold text-zinc-900 dark:text-zinc-100">{{ __('Total Equity') }}</span>
                            <span class="text-sm font-bold text-purple-600 dark:text-purple-400">${{ number_format($bs['total_equity'], 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Balance Check --}}
        <div class="mt-4 rounded-lg border-2 {{ $bs['is_balanced'] ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-950/30' : 'border-red-300 bg-red-50 dark:border-red-700 dark:bg-red-950/30' }} p-4 text-center">
            <div class="flex items-center justify-center gap-8">
                <div>
                    <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Assets') }}</flux:text>
                    <p class="text-lg font-bold text-blue-600 dark:text-blue-400">${{ number_format($bs['total_assets'], 2) }}</p>
                </div>
                <flux:text class="text-2xl text-zinc-400">=</flux:text>
                <div>
                    <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ __('Liabilities + Equity') }}</flux:text>
                    <p class="text-lg font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($bs['total_liabilities'] + $bs['total_equity'], 2) }}</p>
                </div>
            </div>
            @if ($bs['is_balanced'])
                <flux:text class="mt-2 text-emerald-600 dark:text-emerald-400">{{ __('Balanced') }}</flux:text>
            @else
                <flux:text class="mt-2 text-red-600 dark:text-red-400">{{ __('Unbalanced') }} - {{ __('difference:') }} ${{ number_format(abs($bs['total_assets'] - $bs['total_liabilities'] - $bs['total_equity']), 2) }}</flux:text>
            @endif
        </div>
    @endif

    {{-- Empty state when no transactions exist --}}
    @if (($reportType === 'trial-balance' && $this->trialBalance && $this->trialBalance['accounts']->every(fn ($r) => $r['debit'] == 0 && $r['credit'] == 0))
        || ($reportType === 'profit-loss' && $this->profitAndLoss && $this->profitAndLoss['total_revenue'] == 0 && $this->profitAndLoss['total_expenses'] == 0))
        <flux:callout variant="outline" icon="chart-bar-square" class="mt-4">
            <flux:callout.heading>{{ __('No transactions yet') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Financial reports will populate as journal entries are posted.') }}</flux:callout.text>
        </flux:callout>
    @endif
</div>
