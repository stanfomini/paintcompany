<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url(as: 'tab')]
    public string $activeTab = 'roadmap';

    public function boot(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->can('business_plan.view')) {
            abort(403);
        }
    }

    #[Computed]
    public function tabs(): array
    {
        return [
            'roadmap' => ['label' => 'Roadmap', 'icon' => 'map'],
            'life-commitments' => ['label' => 'Life Commitments', 'icon' => 'heart'],
            'sales-plan' => ['label' => 'Sales Plan', 'icon' => 'currency-dollar'],
            'production-plan' => ['label' => 'Production Plan', 'icon' => 'wrench-screwdriver'],
            'job-cost-cards' => ['label' => 'Job Cost Cards', 'icon' => 'calculator'],
            'budget' => ['label' => 'Budget', 'icon' => 'banknotes'],
            'overhead-tracking' => ['label' => 'Overhead', 'icon' => 'building-office-2'],
            'marketing-tracking' => ['label' => 'Marketing', 'icon' => 'megaphone'],
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
    <div class="mb-6">
        <flux:heading size="xl">Master Business Plan</flux:heading>
        <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
            Complete business planning, tracking, and KPIs
        </flux:text>
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
        @endif
    </div>
</div>
