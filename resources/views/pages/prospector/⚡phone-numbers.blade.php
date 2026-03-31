<?php

use App\Models\Prospector\Call;
use App\Models\Prospector\Company;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\PhoneNumberService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Phone Numbers')]
class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $companyFilter = '';

    #[Url]
    public string $roleFilter = '';

    #[Url]
    public string $areaCodeFilter = '';

    #[Url]
    public string $unassignedOnly = '';

    #[Url]
    public string $view = 'pool'; // 'pool' or 'table'

    // Bulk assign
    public array $selectedNumbers = [];
    public bool $showBulkAssignModal = false;
    public ?int $bulkAssignCompanyId = null;

    // Number purchase
    public bool $showPurchaseModal = false;
    public string $purchaseAreaCode = '';
    public string $purchaseContains = '';
    public ?int $purchaseAccountId = null;
    public ?int $purchaseCompanyId = null;
    public string $purchaseRole = 'local_presence';
    public array $searchResults = [];
    public bool $isSearching = false;
    public bool $isPurchasing = false;
    public string $purchaseError = '';

    // Health stats
    public bool $showHealthModal = false;
    public ?int $healthCompanyId = null;

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function phoneNumbers(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return PhoneNumber::query()
            ->with(['twilioAccount', 'company'])
            ->when($this->search, fn ($q) => $q->where(function ($sub) {
                $sub->where('phone_number', 'like', '%'.$this->search.'%')
                    ->orWhere('friendly_name', 'like', '%'.$this->search.'%');
            }))
            ->when($this->companyFilter, fn ($q) => $q->where('company_id', $this->companyFilter))
            ->when($this->roleFilter, fn ($q) => $q->where('role', $this->roleFilter))
            ->when($this->areaCodeFilter, fn ($q) => $q->where('area_code', $this->areaCodeFilter))
            ->when($this->unassignedOnly === '1', fn ($q) => $q->whereNull('company_id'))
            ->orderBy('area_code')
            ->orderBy('phone_number')
            ->paginate(25);
    }

    #[Computed]
    public function companies(): \Illuminate\Support\Collection
    {
        return Company::query()->orderBy('name')->get();
    }

    #[Computed]
    public function twilioAccounts(): \Illuminate\Support\Collection
    {
        return TwilioAccount::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function availableAreaCodes(): array
    {
        return PhoneNumber::query()
            ->distinct()
            ->whereNotNull('area_code')
            ->orderBy('area_code')
            ->pluck('area_code')
            ->all();
    }

    #[Computed]
    public function roleOptions(): array
    {
        return [
            'main' => __('Main'),
            'local_presence' => __('Local Presence'),
            'caller_id_shadow' => __('Caller ID Shadow'),
            'shadow' => __('Shadow'),
            'unassigned' => __('Unassigned'),
        ];
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => PhoneNumber::count(),
            'assigned' => PhoneNumber::whereNotNull('company_id')->count(),
            'unassigned' => PhoneNumber::whereNull('company_id')->count(),
            'active' => PhoneNumber::where('is_active', true)->count(),
        ];
    }

    #[Computed]
    public function numberPools(): \Illuminate\Support\Collection
    {
        return app(PhoneNumberService::class)->getNumberPoolsByCompany();
    }

    #[Computed]
    public function todayUsage(): array
    {
        $usage = [];
        foreach ($this->companies as $company) {
            $companyUsage = app(PhoneNumberService::class)->getTodayUsageByNumber($company);
            foreach ($companyUsage as $number => $count) {
                $usage[$number] = $count;
            }
        }

        return $usage;
    }

    #[Computed]
    public function healthStats(): array
    {
        if (! $this->healthCompanyId) {
            return [];
        }

        $company = Company::find($this->healthCompanyId);
        if (! $company) {
            return [];
        }

        return app(PhoneNumberService::class)->getDailyUsageStats($company, 7);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCompanyFilter(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAreaCodeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedUnassignedOnly(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->companyFilter = '';
        $this->roleFilter = '';
        $this->areaCodeFilter = '';
        $this->unassignedOnly = '';
        $this->resetPage();
    }

    // ──────────────────────────────────────
    // View toggle
    // ──────────────────────────────────────

    public function setView(string $view): void
    {
        $this->view = $view;
    }

    // ──────────────────────────────────────
    // Bulk assign
    // ──────────────────────────────────────

    public function openBulkAssign(): void
    {
        if (empty($this->selectedNumbers)) {
            return;
        }

        $this->bulkAssignCompanyId = null;
        $this->showBulkAssignModal = true;
    }

    public function closeBulkAssign(): void
    {
        $this->showBulkAssignModal = false;
        $this->bulkAssignCompanyId = null;
    }

    public function bulkAssign(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $this->validate([
            'bulkAssignCompanyId' => ['required', 'exists:prospector_companies,id'],
            'selectedNumbers' => ['required', 'array', 'min:1'],
        ]);

        PhoneNumber::query()
            ->whereIn('id', $this->selectedNumbers)
            ->update(['company_id' => $this->bulkAssignCompanyId]);

        $count = count($this->selectedNumbers);
        $this->selectedNumbers = [];
        $this->closeBulkAssign();
        $this->clearCaches();

        session()->flash('success', __(':count number(s) assigned successfully.', ['count' => $count]));
    }

    public function toggleActive(int $id): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $number = PhoneNumber::findOrFail($id);
        $number->update(['is_active' => ! $number->is_active]);
        $this->clearCaches();
    }

    // ──────────────────────────────────────
    // Number Purchase
    // ──────────────────────────────────────

    public function openPurchaseModal(): void
    {
        $this->reset(['purchaseAreaCode', 'purchaseContains', 'purchaseAccountId', 'purchaseCompanyId', 'purchaseRole', 'searchResults', 'isSearching', 'isPurchasing', 'purchaseError']);
        $this->purchaseRole = 'local_presence';
        $this->showPurchaseModal = true;
    }

    public function closePurchaseModal(): void
    {
        $this->showPurchaseModal = false;
        $this->reset(['searchResults', 'purchaseError']);
    }

    public function searchNumbers(): void
    {
        $this->validate([
            'purchaseAreaCode' => ['required', 'digits:3'],
            'purchaseAccountId' => ['required', 'exists:prospector_twilio_accounts,id'],
        ]);

        $this->isSearching = true;
        $this->searchResults = [];
        $this->purchaseError = '';

        try {
            $account = TwilioAccount::findOrFail($this->purchaseAccountId);
            $service = app(PhoneNumberService::class);

            $this->searchResults = $service->searchAvailableNumbers(
                $account,
                $this->purchaseAreaCode,
                $this->purchaseContains ?: null,
            );
        } catch (\Exception $e) {
            $this->purchaseError = 'Search failed: '.$e->getMessage();
        } finally {
            $this->isSearching = false;
        }
    }

    public function purchaseNumber(string $phoneNumber): void
    {
        $this->validate([
            'purchaseAccountId' => ['required', 'exists:prospector_twilio_accounts,id'],
        ]);

        $this->isPurchasing = true;
        $this->purchaseError = '';

        try {
            $account = TwilioAccount::findOrFail($this->purchaseAccountId);
            $company = $this->purchaseCompanyId ? Company::find($this->purchaseCompanyId) : null;
            $service = app(PhoneNumberService::class);

            $service->purchaseNumber($account, $phoneNumber, $company, $this->purchaseRole);

            // Remove from search results
            $this->searchResults = collect($this->searchResults)
                ->reject(fn ($r) => $r['phone_number'] === $phoneNumber)
                ->values()
                ->all();

            $this->clearCaches();

            session()->flash('success', __('Number :number purchased successfully!', ['number' => $phoneNumber]));
        } catch (\Exception $e) {
            $this->purchaseError = 'Purchase failed: '.$e->getMessage();
        } finally {
            $this->isPurchasing = false;
        }
    }

    // ──────────────────────────────────────
    // Health Dashboard
    // ──────────────────────────────────────

    public function openHealthDashboard(int $companyId): void
    {
        $this->healthCompanyId = $companyId;
        unset($this->healthStats);
        $this->showHealthModal = true;
    }

    public function closeHealthDashboard(): void
    {
        $this->showHealthModal = false;
        $this->healthCompanyId = null;
    }

    protected function clearCaches(): void
    {
        unset($this->phoneNumbers, $this->stats, $this->numberPools, $this->todayUsage);
    }

    protected function getRoleBadgeColor(string $role): string
    {
        return match ($role) {
            'main' => 'green',
            'local_presence' => 'blue',
            'caller_id_shadow' => 'purple',
            'shadow' => 'cyan',
            default => 'zinc',
        };
    }

    protected function getUsageLevel(int $count): string
    {
        if ($count === 0) {
            return 'idle';
        }
        if ($count <= 10) {
            return 'low';
        }
        if ($count <= 30) {
            return 'moderate';
        }

        return 'high';
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Phone Numbers') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Manage phone number pools, local presence, and rotation health.') }}</flux:text>
        </div>

        <div class="flex items-center gap-2">
            @if (! empty($selectedNumbers))
                <flux:button variant="primary" icon="arrow-right-circle" wire:click="openBulkAssign">
                    {{ __('Assign Selected (:count)', ['count' => count($selectedNumbers)]) }}
                </flux:button>
            @endif

            <flux:button variant="primary" icon="plus" wire:click="openPurchaseModal">
                {{ __('Buy Number') }}
            </flux:button>
        </div>
    </div>

    @if (session('success'))
        <flux:callout color="green" icon="check-circle" class="mb-4">
            <flux:callout.text>{{ session('success') }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->stats['total'] }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Assigned') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->stats['assigned'] }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Unassigned') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->stats['unassigned'] }}</flux:heading>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Active') }}</flux:text>
            <flux:heading size="xl" class="mt-1">{{ $this->stats['active'] }}</flux:heading>
        </div>
    </div>

    {{-- View Toggle --}}
    <div class="mb-4 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <flux:button
                size="sm"
                :variant="$view === 'pool' ? 'primary' : 'ghost'"
                icon="squares-2x2"
                wire:click="setView('pool')"
            >
                {{ __('Pool View') }}
            </flux:button>
            <flux:button
                size="sm"
                :variant="$view === 'table' ? 'primary' : 'ghost'"
                icon="table-cells"
                wire:click="setView('table')"
            >
                {{ __('Table View') }}
            </flux:button>
        </div>

        @if ($view === 'table')
            {{-- Filters (only in table view) --}}
            <div class="flex flex-wrap items-end gap-4">
                <div class="w-48">
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        icon="magnifying-glass"
                        :placeholder="__('Search...')"
                        size="sm"
                    />
                </div>

                <flux:select wire:model.live="companyFilter" class="w-40" placeholder="{{ __('All Companies') }}" size="sm">
                    <flux:select.option value="">{{ __('All Companies') }}</flux:select.option>
                    @foreach ($this->companies as $company)
                        <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="roleFilter" class="w-36" placeholder="{{ __('All Roles') }}" size="sm">
                    <flux:select.option value="">{{ __('All Roles') }}</flux:select.option>
                    @foreach ($this->roleOptions as $roleValue => $roleLabel)
                        <flux:select.option value="{{ $roleValue }}">{{ $roleLabel }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($search || $companyFilter || $roleFilter || $areaCodeFilter || $unassignedOnly)
                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">
                        {{ __('Clear') }}
                    </flux:button>
                @endif
            </div>
        @endif
    </div>

    {{-- Pool View --}}
    @if ($view === 'pool')
        @if ($this->numberPools->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <flux:icon name="phone" class="mx-auto mb-3 size-12 text-zinc-400" />
                <flux:heading size="lg">{{ __('No number pools configured') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Assign phone numbers to companies to create number pools.') }}</flux:text>
            </div>
        @else
            <div class="space-y-6">
                @foreach ($this->numberPools as $pool)
                    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800" wire:key="pool-{{ $pool['company']->id }}">
                        {{-- Pool Header --}}
                        <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                            <div class="flex items-center gap-3">
                                <flux:heading size="lg">{{ $pool['company']->name }}</flux:heading>
                                <flux:badge size="sm" color="zinc">
                                    {{ $pool['numbers']->count() }} {{ __('numbers') }}
                                </flux:badge>
                            </div>
                            <div class="flex items-center gap-2">
                                <flux:button size="sm" variant="ghost" icon="chart-bar" wire:click="openHealthDashboard({{ $pool['company']->id }})">
                                    {{ __('Usage Stats') }}
                                </flux:button>
                                <flux:button size="sm" variant="ghost" icon="eye" :href="route('prospector.companies.show', $pool['company']->id)">
                                    {{ __('View Company') }}
                                </flux:button>
                            </div>
                        </div>

                        <div class="p-6">
                            {{-- Main Number --}}
                            @if ($pool['main'])
                                <div class="mb-4">
                                    <flux:text class="mb-2 text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                        {{ __('Main Number (Caller ID for unknowns)') }}
                                    </flux:text>
                                    <div class="flex items-center gap-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 dark:border-green-800 dark:bg-green-900/20">
                                        <flux:icon name="phone" class="size-5 text-green-600 dark:text-green-400" />
                                        <span class="font-mono text-sm font-medium">{{ $pool['main']->getDisplayNumber() }}</span>
                                        <flux:badge size="sm" color="green">{{ __('Main') }}</flux:badge>
                                        @if (isset($this->todayUsage[$pool['main']->phone_number]))
                                            <flux:badge size="sm" color="zinc">{{ $this->todayUsage[$pool['main']->phone_number] }} {{ __('calls today') }}</flux:badge>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            {{-- Local Presence Pool --}}
                            @if ($pool['local_presence']->isNotEmpty())
                                <div class="mb-4">
                                    <flux:text class="mb-2 text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                        {{ __('Local Presence Pool') }}
                                    </flux:text>
                                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        @foreach ($pool['local_presence'] as $number)
                                            @php
                                                $todayCount = $this->todayUsage[$number->phone_number] ?? 0;
                                                $usageLevel = $this->getUsageLevel($todayCount);
                                            @endphp
                                            <div class="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-2.5 dark:border-zinc-700" wire:key="lp-{{ $number->id }}">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-mono text-sm">{{ $number->getDisplayNumber() }}</span>
                                                    <flux:badge size="sm" color="blue">{{ $number->area_code }}</flux:badge>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    @if ($todayCount > 0)
                                                        <span class="text-xs text-zinc-500">{{ $todayCount }} {{ __('today') }}</span>
                                                    @endif
                                                    <span class="size-2 rounded-full {{ match($usageLevel) {
                                                        'idle' => 'bg-zinc-300 dark:bg-zinc-600',
                                                        'low' => 'bg-green-500',
                                                        'moderate' => 'bg-yellow-500',
                                                        'high' => 'bg-red-500',
                                                    } }}" title="{{ ucfirst($usageLevel) }}"></span>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Shadow Numbers --}}
                            @if ($pool['shadow']->isNotEmpty())
                                <div class="mb-4">
                                    <flux:text class="mb-2 text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                        {{ __('Shadow Numbers') }}
                                    </flux:text>
                                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        @foreach ($pool['shadow'] as $number)
                                            @php
                                                $todayCount = $this->todayUsage[$number->phone_number] ?? 0;
                                            @endphp
                                            <div class="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-2.5 dark:border-zinc-700" wire:key="sh-{{ $number->id }}">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-mono text-sm">{{ $number->getDisplayNumber() }}</span>
                                                    <flux:badge size="sm" color="cyan">{{ $number->area_code }}</flux:badge>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    @if ($todayCount > 0)
                                                        <span class="text-xs text-zinc-500">{{ $todayCount }} {{ __('today') }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Caller ID Shadow Numbers --}}
                            @if ($pool['caller_id_shadow']->isNotEmpty())
                                <div>
                                    <flux:text class="mb-2 text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                        {{ __('Caller ID Shadow Numbers') }}
                                    </flux:text>
                                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                        @foreach ($pool['caller_id_shadow'] as $number)
                                            <div class="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-2.5 dark:border-zinc-700" wire:key="cid-{{ $number->id }}">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-mono text-sm">{{ $number->getDisplayNumber() }}</span>
                                                    @if ($number->caller_id_override)
                                                        <flux:icon name="arrow-right" class="size-3.5 text-zinc-400" />
                                                        <span class="text-xs text-zinc-500">{{ __('shows as') }} {{ $number->caller_id_override }}</span>
                                                    @endif
                                                </div>
                                                <flux:badge size="sm" color="purple">{{ __('Shadow') }}</flux:badge>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- No numbers in pool --}}
                            @if (! $pool['main'] && $pool['local_presence']->isEmpty() && $pool['shadow']->isEmpty() && $pool['caller_id_shadow']->isEmpty())
                                <flux:text class="text-zinc-400">{{ __('No numbers assigned to this company.') }}</flux:text>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Unassigned Numbers Summary --}}
            @if ($this->stats['unassigned'] > 0)
                <div class="mt-6 rounded-lg border border-dashed border-zinc-300 bg-zinc-50 p-6 dark:border-zinc-600 dark:bg-zinc-900">
                    <div class="flex items-center justify-between">
                        <div>
                            <flux:heading size="base">{{ __('Unassigned Numbers') }}</flux:heading>
                            <flux:text class="mt-1">{{ __(':count numbers not assigned to any company.', ['count' => $this->stats['unassigned']]) }}</flux:text>
                        </div>
                        <flux:button size="sm" variant="ghost" wire:click="setView('table'); $set('unassignedOnly', '1')">
                            {{ __('View in Table') }}
                        </flux:button>
                    </div>
                </div>
            @endif
        @endif

    {{-- Table View --}}
    @else
        @if ($this->phoneNumbers->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
                <flux:icon name="phone" class="mx-auto mb-3 size-12 text-zinc-400" />
                <flux:heading size="lg">{{ __('No phone numbers found') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Sync your Twilio accounts to import phone numbers.') }}</flux:text>
            </div>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column class="w-8">
                        {{-- Checkbox header --}}
                    </flux:table.column>
                    <flux:table.column>{{ __('Number') }}</flux:table.column>
                    <flux:table.column>{{ __('Friendly Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Area Code') }}</flux:table.column>
                    <flux:table.column>{{ __('Company') }}</flux:table.column>
                    <flux:table.column>{{ __('Role') }}</flux:table.column>
                    <flux:table.column>{{ __('Today') }}</flux:table.column>
                    <flux:table.column>{{ __('Active') }}</flux:table.column>
                    <flux:table.column>{{ __('Last Used') }}</flux:table.column>
                    <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->phoneNumbers as $number)
                        <flux:table.row wire:key="pn-{{ $number->id }}">
                            <flux:table.cell>
                                <input
                                    type="checkbox"
                                    wire:model.live="selectedNumbers"
                                    value="{{ $number->id }}"
                                    class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600"
                                />
                            </flux:table.cell>

                            <flux:table.cell>
                                <span class="font-mono text-sm">{{ $number->getDisplayNumber() }}</span>
                            </flux:table.cell>

                            <flux:table.cell>
                                {{ $number->friendly_name ?: '-' }}
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:badge size="sm" color="zinc">{{ $number->area_code }}</flux:badge>
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($number->company)
                                    <a href="{{ route('prospector.companies.show', $number->company_id) }}" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                        {{ $number->company->name }}
                                    </a>
                                @else
                                    <span class="text-zinc-400">{{ __('Unassigned') }}</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:badge size="sm" :color="$this->getRoleBadgeColor($number->role ?? 'unassigned')">
                                    {{ $this->roleOptions[$number->role] ?? ucfirst($number->role ?? 'unassigned') }}
                                </flux:badge>
                            </flux:table.cell>

                            <flux:table.cell>
                                @php $todayCount = $this->todayUsage[$number->phone_number] ?? 0; @endphp
                                @if ($todayCount > 0)
                                    <span class="text-sm">{{ $todayCount }}</span>
                                @else
                                    <span class="text-zinc-400">0</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:switch
                                    wire:click="toggleActive({{ $number->id }})"
                                    :checked="$number->is_active"
                                    size="sm"
                                />
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($number->last_used_at)
                                    <span class="text-sm text-zinc-500" title="{{ $number->last_used_at->format('M j, Y g:i A') }}">
                                        {{ $number->last_used_at->diffForHumans() }}
                                    </span>
                                @else
                                    <span class="text-zinc-400">{{ __('Never') }}</span>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="text-right">
                                @if ($number->company)
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="eye"
                                        :href="route('prospector.companies.show', $number->company_id)"
                                        title="{{ __('View Company') }}"
                                    />
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            <div class="mt-6">
                {{ $this->phoneNumbers->links() }}
            </div>
        @endif
    @endif

    {{-- Bulk Assign Modal --}}
    <flux:modal wire:model="showBulkAssignModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Bulk Assign Numbers') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Assign :count selected number(s) to a company.', ['count' => count($selectedNumbers)]) }}
                </flux:text>
            </div>

            <flux:select wire:model="bulkAssignCompanyId" :label="__('Company')" required>
                <flux:select.option value="">{{ __('Select a company...') }}</flux:select.option>
                @foreach ($this->companies as $company)
                    <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="closeBulkAssign" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="bulkAssign" class="flex-1">{{ __('Assign') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Number Purchase Modal --}}
    <flux:modal wire:model="showPurchaseModal" class="max-w-2xl">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Buy Phone Number') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Search and purchase a number from Twilio.') }}</flux:text>
            </div>

            @if ($purchaseError)
                <flux:callout color="red" icon="exclamation-triangle">
                    <flux:callout.text>{{ $purchaseError }}</flux:callout.text>
                </flux:callout>
            @endif

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Twilio Account') }}</flux:label>
                    <flux:select wire:model="purchaseAccountId" required>
                        <flux:select.option value="">{{ __('Select account...') }}</flux:select.option>
                        @foreach ($this->twilioAccounts as $account)
                            <flux:select.option value="{{ $account->id }}">{{ $account->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="purchaseAccountId" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Area Code') }}</flux:label>
                    <flux:input wire:model="purchaseAreaCode" placeholder="303" maxlength="3" required />
                    <flux:error name="purchaseAreaCode" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Contains (optional)') }}</flux:label>
                    <flux:input wire:model="purchaseContains" placeholder="555" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Assign to Company') }}</flux:label>
                    <flux:select wire:model="purchaseCompanyId">
                        <flux:select.option value="">{{ __('None (unassigned)') }}</flux:select.option>
                        @foreach ($this->companies as $company)
                            <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                @if ($purchaseCompanyId)
                    <flux:field>
                        <flux:label>{{ __('Role') }}</flux:label>
                        <flux:select wire:model="purchaseRole">
                            <flux:select.option value="local_presence">{{ __('Local Presence') }}</flux:select.option>
                            <flux:select.option value="main">{{ __('Main') }}</flux:select.option>
                            <flux:select.option value="shadow">{{ __('Shadow') }}</flux:select.option>
                        </flux:select>
                    </flux:field>
                @endif
            </div>

            <flux:button variant="primary" wire:click="searchNumbers" wire:loading.attr="disabled" wire:target="searchNumbers">
                <span wire:loading.remove wire:target="searchNumbers">{{ __('Search Available Numbers') }}</span>
                <span wire:loading wire:target="searchNumbers">{{ __('Searching...') }}</span>
            </flux:button>

            {{-- Search Results --}}
            @if (! empty($searchResults))
                <div>
                    <flux:text class="mb-2 text-sm font-medium">{{ __(':count numbers found', ['count' => count($searchResults)]) }}</flux:text>
                    <div class="max-h-80 space-y-1 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        @foreach ($searchResults as $result)
                            <div class="flex items-center justify-between px-4 py-2.5 hover:bg-zinc-50 dark:hover:bg-zinc-800" wire:key="search-{{ $result['phone_number'] }}">
                                <div>
                                    <span class="font-mono text-sm font-medium">{{ $result['friendly_name'] }}</span>
                                    @if ($result['locality'])
                                        <span class="ml-2 text-xs text-zinc-500">{{ $result['locality'] }}, {{ $result['region'] }}</span>
                                    @endif
                                </div>
                                <flux:button
                                    size="sm"
                                    variant="primary"
                                    wire:click="purchaseNumber('{{ $result['phone_number'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="purchaseNumber"
                                >
                                    {{ __('Buy') }}
                                </flux:button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex justify-end">
                <flux:button variant="ghost" wire:click="closePurchaseModal">{{ __('Close') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Health Dashboard Modal --}}
    <flux:modal wire:model="showHealthModal" class="max-w-2xl">
        <div class="space-y-6">
            @php $healthCompany = $healthCompanyId ? \App\Models\Prospector\Company::find($healthCompanyId) : null; @endphp

            <div>
                <flux:heading size="lg">{{ __('Number Usage Stats') }}</flux:heading>
                @if ($healthCompany)
                    <flux:text class="mt-1">{{ $healthCompany->name }} &mdash; {{ __('Last 7 days') }}</flux:text>
                @endif
            </div>

            @if (! empty($this->healthStats))
                @php
                    // Group stats by number
                    $byNumber = collect($this->healthStats)->groupBy('from_number');
                @endphp

                <div class="space-y-4">
                    @foreach ($byNumber as $number => $dailyStats)
                        @php
                            $totalCalls = $dailyStats->sum('call_count');
                            $maxDaily = $dailyStats->max('call_count');
                            $avgDaily = round($dailyStats->avg('call_count'), 1);
                        @endphp

                        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <div class="mb-3 flex items-center justify-between">
                                <span class="font-mono text-sm font-medium">{{ $number }}</span>
                                <div class="flex items-center gap-3 text-xs text-zinc-500">
                                    <span>{{ __('Total') }}: {{ $totalCalls }}</span>
                                    <span>{{ __('Avg') }}: {{ $avgDaily }}/{{ __('day') }}</span>
                                    <span>{{ __('Peak') }}: {{ $maxDaily }}</span>
                                </div>
                            </div>

                            {{-- Simple bar chart --}}
                            <div class="flex items-end gap-1" style="height: 48px;">
                                @for ($i = 6; $i >= 0; $i--)
                                    @php
                                        $date = now()->subDays($i)->format('Y-m-d');
                                        $dayStat = $dailyStats->firstWhere('date', $date);
                                        $count = $dayStat ? $dayStat['call_count'] : 0;
                                        $maxForChart = max($maxDaily, 1);
                                        $heightPct = ($count / $maxForChart) * 100;
                                    @endphp
                                    <div class="group relative flex-1">
                                        <div
                                            class="w-full rounded-t {{ $count > 30 ? 'bg-red-400' : ($count > 10 ? 'bg-yellow-400' : 'bg-blue-400') }}"
                                            style="height: {{ max($heightPct, 4) }}%; min-height: 2px;"
                                        ></div>
                                        <div class="mt-1 text-center text-[10px] text-zinc-400">
                                            {{ now()->subDays($i)->format('D') }}
                                        </div>
                                    </div>
                                @endfor
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="py-8 text-center">
                    <flux:icon name="chart-bar" class="mx-auto mb-3 size-10 text-zinc-400" />
                    <flux:text>{{ __('No call activity in the last 7 days.') }}</flux:text>
                </div>
            @endif

            <div class="flex justify-end">
                <flux:button variant="ghost" wire:click="closeHealthDashboard">{{ __('Close') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
