<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\PhoneNumber;
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

    // Bulk assign
    public array $selectedNumbers = [];
    public bool $showBulkAssignModal = false;
    public ?int $bulkAssignCompanyId = null;

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
    public function companies(): \Illuminate\Database\Eloquent\Collection
    {
        return Company::query()->orderBy('name')->get();
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

    protected function clearCaches(): void
    {
        unset($this->phoneNumbers, $this->stats);
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
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Phone Numbers') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Full inventory of all phone numbers across all Twilio accounts.') }}</flux:text>
        </div>

        @if (! empty($selectedNumbers))
            <flux:button variant="primary" icon="arrow-right-circle" wire:click="openBulkAssign">
                {{ __('Assign Selected (:count)', ['count' => count($selectedNumbers)]) }}
            </flux:button>
        @endif
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

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-end gap-4">
        <div class="w-64">
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Search numbers...')"
            />
        </div>

        <flux:select wire:model.live="companyFilter" class="w-48" placeholder="{{ __('All Companies') }}">
            <flux:select.option value="">{{ __('All Companies') }}</flux:select.option>
            @foreach ($this->companies as $company)
                <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="roleFilter" class="w-40" placeholder="{{ __('All Roles') }}">
            <flux:select.option value="">{{ __('All Roles') }}</flux:select.option>
            @foreach ($this->roleOptions as $roleValue => $roleLabel)
                <flux:select.option value="{{ $roleValue }}">{{ $roleLabel }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="areaCodeFilter" class="w-36" placeholder="{{ __('All Areas') }}">
            <flux:select.option value="">{{ __('All Area Codes') }}</flux:select.option>
            @foreach ($this->availableAreaCodes as $code)
                <flux:select.option value="{{ $code }}">{{ $code }}</flux:select.option>
            @endforeach
        </flux:select>

        <label class="flex cursor-pointer items-center gap-2 text-sm">
            <input
                type="checkbox"
                wire:model.live="unassignedOnly"
                value="1"
                class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500 dark:border-zinc-600"
                @checked($unassignedOnly === '1')
            />
            {{ __('Unassigned only') }}
        </label>

        @if ($search || $companyFilter || $roleFilter || $areaCodeFilter || $unassignedOnly)
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">
                {{ __('Clear') }}
            </flux:button>
        @endif
    </div>

    {{-- Phone numbers table --}}
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
</div>
