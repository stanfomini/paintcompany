<?php

use App\Models\BudgetLineItem;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Services\BusinessPlanService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url(as: 'tab')]
    public string $activeTab = 'roadmap';

    public string $accountingMode = 'cash';

    public ?string $modeChangeMessage = null;

    public function boot(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->can('business_plan.view')) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $this->accountingMode = CompanySetting::getInstance()->accounting_mode ?? 'cash';
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('business_plan.manage') ?? false;
    }

    public function setAccountingMode(string $mode): void
    {
        $this->authorize('business_plan.manage');

        if (! in_array($mode, ['cash', 'accrual', 'modified_cash'])) {
            return;
        }

        $settings = CompanySetting::getInstance();
        $settings->update(['accounting_mode' => $mode]);

        $this->accountingMode = $mode;
        $this->modeChangeMessage = __('Accounting mode updated to :mode.', ['mode' => $this->accountingModeLabel($mode)]);

        unset($this->dashboardData);
    }

    private function accountingModeLabel(string $mode): string
    {
        return match ($mode) {
            'cash' => __('Cash Basis'),
            'accrual' => __('Accrual Basis'),
            'modified_cash' => __('Modified Cash'),
            default => __('Cash Basis'),
        };
    }

    #[Computed]
    public function tabs(): array
    {
        return [
            'roadmap' => ['label' => __('Roadmap'), 'icon' => 'map'],
            'life-commitments' => ['label' => __('Life Commitments'), 'icon' => 'heart'],
            'sales-plan' => ['label' => __('Sales Plan'), 'icon' => 'currency-dollar'],
            'production-plan' => ['label' => __('Production Plan'), 'icon' => 'wrench-screwdriver'],
            'job-cost-cards' => ['label' => __('Job Cost Cards'), 'icon' => 'calculator'],
            'budget' => ['label' => __('Budget'), 'icon' => 'banknotes'],
            'overhead-tracking' => ['label' => __('Overhead'), 'icon' => 'building-office-2'],
            'marketing-tracking' => ['label' => __('Marketing'), 'icon' => 'megaphone'],
            'vendors' => ['label' => __('Vendors'), 'icon' => 'building-storefront'],
            'chart-of-accounts' => ['label' => __('Chart of Accounts'), 'icon' => 'list-bullet'],
            'journal-entries' => ['label' => __('Journal'), 'icon' => 'book-open'],
            'financial-reports' => ['label' => __('Reports'), 'icon' => 'chart-bar-square'],
        ];
    }

    #[Computed]
    public function dashboardData(): array
    {
        $settings = CompanySetting::getInstance();
        $year = now()->year;

        $budget = app(BusinessPlanService::class)->getBudgetForYear($year);

        $revenuePlan = $budget['revenue']['current_year_plan'];
        $revenueActual = $budget['revenue']['current_year_actual'];
        $revenuePercent = $revenuePlan > 0 ? round(($revenueActual / $revenuePlan) * 100, 1) : 0;

        $gp = $budget['computed']['gross_profit'];
        $gpPercent = $revenueActual > 0
            ? round(($gp['current_year_actual'] / $revenueActual) * 100, 1)
            : 0;

        $unpaidCount = Invoice::where('company_setting_id', $settings->id)->unpaid()->count();
        $unpaidTotal = (float) Invoice::where('company_setting_id', $settings->id)->unpaid()->sum('balance_due');

        $lastSync = BudgetLineItem::where('company_setting_id', $settings->id)
            ->whereNotNull('synced_at')
            ->max('synced_at');

        return [
            'revenue_plan' => $revenuePlan,
            'revenue_actual' => $revenueActual,
            'revenue_percent' => min($revenuePercent, 100),
            'gp_percent' => $gpPercent,
            'unpaid_count' => $unpaidCount,
            'unpaid_total' => $unpaidTotal,
            'last_sync' => $lastSync ? \Carbon\Carbon::parse($lastSync)->diffForHumans() : null,
        ];
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, $this->tabs)) {
            $this->activeTab = $tab;
        }
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl">{{ __('Master Business Plan') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Complete business planning, tracking, and KPIs') }}
            </flux:text>
        </div>
    </div>

    {{-- Accounting Mode Selector --}}
    <div class="mb-6 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <flux:icon name="cog-6-tooth" variant="mini" class="size-4 text-zinc-400" />
                    <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Accounting Method') }}</flux:text>
                </div>
            </div>

            @if ($this->canManage)
                <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
                    @foreach ([
                        'cash' => ['label' => __('Cash Basis'), 'desc' => __('Revenue when paid')],
                        'accrual' => ['label' => __('Accrual Basis'), 'desc' => __('Revenue when earned')],
                        'modified_cash' => ['label' => __('Modified Cash'), 'desc' => __('Hybrid approach')],
                    ] as $mode => $info)
                        <button
                            wire:key="mode-{{ $mode }}"
                            wire:click="setAccountingMode('{{ $mode }}')"
                            class="group relative rounded-md px-3 py-1.5 text-sm font-medium transition-all
                                {{ $accountingMode === $mode
                                    ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-zinc-100'
                                    : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
                        >
                            <span>{{ $info['label'] }}</span>
                            <span class="absolute -bottom-5 left-1/2 hidden -translate-x-1/2 whitespace-nowrap text-[10px] text-zinc-400 group-hover:block dark:text-zinc-500">
                                {{ $info['desc'] }}
                            </span>
                        </button>
                    @endforeach
                </div>
            @else
                <flux:badge size="lg" color="blue">
                    {{ match ($accountingMode) {
                        'cash' => __('Cash Basis'),
                        'accrual' => __('Accrual Basis'),
                        'modified_cash' => __('Modified Cash'),
                        default => __('Cash Basis'),
                    } }}
                </flux:badge>
            @endif
        </div>

        @if ($modeChangeMessage)
            <div class="mt-3">
                <flux:callout variant="outline" icon="check-circle" class="text-sm">
                    <flux:callout.text>{{ $modeChangeMessage }}</flux:callout.text>
                </flux:callout>
            </div>
        @endif

        <div class="mt-2">
            <flux:text class="text-xs text-zinc-400 dark:text-zinc-500">
                @if ($accountingMode === 'cash')
                    {{ __('Revenue is recognized when payment is received. Expenses counted when paid. Best for simple tax filing.') }}
                @elseif ($accountingMode === 'accrual')
                    {{ __('Revenue is recognized when invoiced. Expenses counted when incurred. Most accurate business picture.') }}
                @else
                    {{ __('Revenue recognized when invoiced, expenses counted when paid. Best of both worlds for small businesses.') }}
                @endif
            </flux:text>
        </div>
    </div>

    {{-- Dashboard Indicators --}}
    @php $dash = $this->dashboardData; @endphp
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {{-- Revenue vs Plan --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Revenue vs Plan') }}</flux:text>
                <flux:icon name="currency-dollar" variant="mini" class="size-4 text-green-500" />
            </div>
            <div class="mt-2">
                <span class="text-xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($dash['revenue_actual'], 0) }}</span>
                <span class="text-sm text-zinc-500 dark:text-zinc-400"> / ${{ number_format($dash['revenue_plan'], 0) }}</span>
            </div>
            <div class="mt-2">
                <div class="h-2 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                    <div
                        class="h-full rounded-full {{ $dash['revenue_percent'] >= 75 ? 'bg-green-500' : ($dash['revenue_percent'] >= 50 ? 'bg-amber-500' : 'bg-red-500') }}"
                        style="width: {{ $dash['revenue_percent'] }}%"
                    ></div>
                </div>
                <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $dash['revenue_percent'] }}% {{ __('of plan') }}</flux:text>
            </div>
        </div>

        {{-- Gross Profit % --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Gross Profit') }}</flux:text>
                <flux:icon name="arrow-trending-up" variant="mini" class="size-4 text-blue-500" />
            </div>
            <div class="mt-2">
                <span class="text-xl font-bold {{ $dash['gp_percent'] >= 40 ? 'text-green-600 dark:text-green-400' : ($dash['gp_percent'] >= 25 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') }}">
                    {{ $dash['gp_percent'] }}%
                </span>
            </div>
            <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                {{ $dash['gp_percent'] >= 40 ? __('Healthy margin') : ($dash['gp_percent'] >= 25 ? __('Below target') : __('Needs attention')) }}
            </flux:text>
        </div>

        {{-- Unpaid Invoices --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Unpaid Invoices') }}</flux:text>
                <flux:icon name="document-currency-dollar" variant="mini" class="size-4 text-amber-500" />
            </div>
            <div class="mt-2">
                <span class="text-xl font-bold text-zinc-900 dark:text-zinc-100">{{ $dash['unpaid_count'] }}</span>
            </div>
            <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                ${{ number_format($dash['unpaid_total'], 2) }} {{ __('outstanding') }}
            </flux:text>
        </div>

        {{-- Last Budget Sync --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Budget Sync') }}</flux:text>
                <flux:icon name="arrow-path" variant="mini" class="size-4 text-purple-500" />
            </div>
            <div class="mt-2">
                @if ($dash['last_sync'])
                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $dash['last_sync'] }}</span>
                @else
                    <span class="text-sm text-zinc-400 dark:text-zinc-500">{{ __('Never synced') }}</span>
                @endif
            </div>
            <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('Last actuals refresh') }}
            </flux:text>
        </div>
    </div>

    {{-- Tab Navigation --}}
    <div class="mb-6 flex gap-1 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700 pb-px">
        @foreach ($this->tabs as $key => $tab)
            <button
                wire:key="tab-{{ $key }}"
                wire:click="setTab('{{ $key }}')"
                class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors
                    {{ $activeTab === $key
                        ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                        : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-300' }}"
            >
                <flux:icon :name="$tab['icon']" variant="mini" class="size-4" />
                {{ $tab['label'] }}
            </button>
        @endforeach
    </div>

    {{-- Tab Content --}}
    <div>
        @if ($activeTab === 'roadmap')
            <livewire:dynamic-component :component="'roadmap.index'" :key="'roadmap-tab'" />
        @elseif ($activeTab === 'life-commitments')
            <livewire:dynamic-component :component="'business-plan.life-commitments'" :key="'life-tab'" />
        @elseif ($activeTab === 'sales-plan')
            <livewire:dynamic-component :component="'kpis.sales-plan'" :key="'sales-tab'" />
        @elseif ($activeTab === 'production-plan')
            <livewire:dynamic-component :component="'kpis.production-plan'" :key="'production-tab'" />
        @elseif ($activeTab === 'job-cost-cards')
            <livewire:dynamic-component :component="'business-plan.job-cost-cards'" :key="'jcc-tab'" />
        @elseif ($activeTab === 'budget')
            <livewire:dynamic-component :component="'business-plan.budget'" :key="'budget-tab'" />
        @elseif ($activeTab === 'overhead-tracking')
            <livewire:dynamic-component :component="'business-plan.overhead-tracking'" :key="'overhead-tab'" />
        @elseif ($activeTab === 'marketing-tracking')
            <livewire:dynamic-component :component="'business-plan.marketing-tracking'" :key="'marketing-tab'" />
        @elseif ($activeTab === 'vendors')
            <livewire:dynamic-component :component="'business-plan.vendors'" :key="'vendors-tab'" />
        @elseif ($activeTab === 'chart-of-accounts')
            <livewire:dynamic-component :component="'business-plan.chart-of-accounts'" :key="'coa-tab'" />
        @elseif ($activeTab === 'journal-entries')
            <livewire:dynamic-component :component="'business-plan.journal-entries'" :key="'journal-tab'" />
        @elseif ($activeTab === 'financial-reports')
            <livewire:dynamic-component :component="'business-plan.financial-reports'" :key="'reports-tab'" />
        @endif
    </div>
</div>
