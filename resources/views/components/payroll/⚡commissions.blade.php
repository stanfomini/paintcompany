<?php

use App\Models\AgentProfile;
use App\Models\CommissionEntry;
use App\Models\CrewProfile;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CommissionService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Commissions')]
class extends Component
{
    use WithPagination;

    public function boot(): void
    {
        if (! auth()->user()?->can('payroll.manage')) {
            abort(403);
        }
    }

    public string $statusFilter = 'all';

    public string $entityFilter = 'all';

    public string $sourceFilter = 'all';

    public string $dateFrom = '';

    public string $dateTo = '';

    /** @var array<int> */
    public array $selected = [];

    public bool $showManualModal = false;

    public string $manualEmployeeId = '';

    public string $manualAmount = '';

    public string $manualType = 'flat';

    public string $manualNotes = '';

    public bool $showReverseModal = false;

    public int $reversingEntryId = 0;

    public string $reverseReason = '';

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function updatedEntityFilter(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function updatedSourceFilter(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function commissions()
    {
        return CommissionEntry::query()
            ->with(['employeeProfile.user', 'commissionable', 'approvedBy'])
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->entityFilter !== 'all', fn ($q) => $q->where('commissionable_type', match ($this->entityFilter) {
                'employee' => EmployeeProfile::class,
                'vendor' => Vendor::class,
                'user' => User::class,
                default => null,
            }))
            ->when($this->sourceFilter !== 'all', fn ($q) => $q->where('source_label', 'like', match ($this->sourceFilter) {
                'door_knock' => 'Door Knock:%',
                'estimate' => 'Estimate:%',
                'job' => 'Job:%',
                'territory_override' => 'Territory Override:%',
                'lead' => 'Lead:%',
                'manual' => 'Manual Entry%',
                'crew' => 'Crew Bonus:%',
                default => '%',
            }))
            ->when($this->dateFrom, fn ($q) => $q->where('earned_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('earned_date', '<=', $this->dateTo))
            ->orderByDesc('earned_date')
            ->orderByDesc('id')
            ->paginate(20);
    }

    public function resolveCommissionableName(CommissionEntry $entry): string
    {
        // Prefer commissionable relationship
        $commissionable = $entry->commissionable;

        if ($commissionable instanceof EmployeeProfile) {
            return $commissionable->user?->name ?? '-';
        }

        if ($commissionable instanceof Vendor) {
            return $commissionable->name ?? '-';
        }

        if ($commissionable instanceof User) {
            return $commissionable->name ?? '-';
        }

        if ($commissionable instanceof CrewProfile) {
            return $commissionable->user?->name ?? '-';
        }

        if ($commissionable instanceof AgentProfile) {
            return $commissionable->user?->name ?? '-';
        }

        // Fallback to legacy employee_profile_id
        return $entry->employeeProfile?->user?->name ?? '-';
    }

    public function resolveCommissionableType(CommissionEntry $entry): string
    {
        return match ($entry->commissionable_type) {
            EmployeeProfile::class => 'Employee',
            Vendor::class => 'Vendor',
            User::class => 'Referrer',
            CrewProfile::class => 'Crew',
            AgentProfile::class => 'Agent',
            default => 'Employee',
        };
    }

    #[Computed]
    public function employees()
    {
        return EmployeeProfile::query()
            ->with('user')
            ->where('employment_status', 'active')
            ->get();
    }

    #[Computed]
    public function pendingCount(): int
    {
        return CommissionEntry::pending()->count();
    }

    public function approveEntry(int $entryId): void
    {
        $entry = CommissionEntry::findOrFail($entryId);

        if (! $entry->isPending()) {
            return;
        }

        app(CommissionService::class)->approveCommission($entry, auth()->user());

        unset($this->commissions, $this->pendingCount);
    }

    public function openReverseModal(int $entryId): void
    {
        $this->reversingEntryId = $entryId;
        $this->reverseReason = '';
        $this->showReverseModal = true;
    }

    public function reverseEntry(): void
    {
        $entry = CommissionEntry::findOrFail($this->reversingEntryId);

        if (! $entry->isApproved()) {
            return;
        }

        app(CommissionService::class)->reverseCommission($entry, $this->reverseReason ?: null);

        $this->showReverseModal = false;
        $this->reversingEntryId = 0;
        $this->reverseReason = '';

        unset($this->commissions, $this->pendingCount);
    }

    public function openManualModal(): void
    {
        $this->manualEmployeeId = '';
        $this->manualAmount = '';
        $this->manualType = 'flat';
        $this->manualNotes = '';
        $this->showManualModal = true;
    }

    public function createManualEntry(): void
    {
        $this->validate([
            'manualEmployeeId' => 'required|exists:employee_profiles,id',
            'manualAmount' => 'required|numeric|min:0.01',
            'manualType' => 'required|in:flat,revenue_percentage,profit_percentage',
            'manualNotes' => 'nullable|string|max:1000',
        ]);

        $employee = EmployeeProfile::findOrFail($this->manualEmployeeId);

        app(CommissionService::class)->createManual(
            $employee,
            (float) $this->manualAmount,
            $this->manualType,
            $this->manualNotes ?: null
        );

        $this->showManualModal = false;

        unset($this->commissions, $this->pendingCount);
    }

    public function bulkApprove(): void
    {
        if (empty($this->selected)) {
            return;
        }

        $service = app(CommissionService::class);
        $user = auth()->user();

        $entries = CommissionEntry::whereIn('id', $this->selected)
            ->where('status', 'pending')
            ->get();

        foreach ($entries as $entry) {
            $service->approveCommission($entry, $user);
        }

        $this->selected = [];

        unset($this->commissions, $this->pendingCount);
    }

    public function toggleSelectAll(): void
    {
        $pageIds = $this->commissions
            ->filter(fn ($e) => $e->isPending())
            ->pluck('id')
            ->toArray();

        if (count(array_intersect($this->selected, $pageIds)) === count($pageIds) && count($pageIds) > 0) {
            $this->selected = array_values(array_diff($this->selected, $pageIds));
        } else {
            $this->selected = array_values(array_unique(array_merge($this->selected, $pageIds)));
        }
    }
};
?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Commissions') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Manage commission entries for employees, vendors, and referrers.') }}</flux:text>
        </div>

        <flux:button variant="primary" wire:click="openManualModal" icon="plus">
            {{ __('Manual Entry') }}
        </flux:button>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-4">
        <flux:select wire:model.live="statusFilter" class="w-40">
            <option value="all">{{ __('All Statuses') }}</option>
            <option value="pending">{{ __('Pending') }}</option>
            <option value="approved">{{ __('Approved') }}</option>
            <option value="paid">{{ __('Paid') }}</option>
            <option value="reversed">{{ __('Reversed') }}</option>
        </flux:select>

        <flux:select wire:model.live="entityFilter" class="w-40">
            <option value="all">{{ __('All Entities') }}</option>
            <option value="employee">{{ __('Employees') }}</option>
            <option value="vendor">{{ __('Vendors') }}</option>
            <option value="user">{{ __('Referrers') }}</option>
        </flux:select>

        <flux:select wire:model.live="sourceFilter" class="w-44">
            <option value="all">{{ __('All Sources') }}</option>
            <option value="door_knock">{{ __('Door Knock') }}</option>
            <option value="lead">{{ __('Lead') }}</option>
            <option value="estimate">{{ __('Estimate') }}</option>
            <option value="job">{{ __('Job') }}</option>
            <option value="territory_override">{{ __('Territory Override') }}</option>
            <option value="crew">{{ __('Crew Bonus') }}</option>
            <option value="manual">{{ __('Manual Entry') }}</option>
        </flux:select>

        <flux:input type="date" wire:model.live="dateFrom" class="w-44" />
        <flux:input type="date" wire:model.live="dateTo" class="w-44" />

        @if (count($selected) > 0)
            <flux:button wire:click="bulkApprove" variant="primary" size="sm" icon="check" wire:confirm="{{ __('Approve :count selected commission(s)?', ['count' => count($selected)]) }}">
                {{ __('Approve Selected (:count)', ['count' => count($selected)]) }}
            </flux:button>
        @endif

        @if ($this->pendingCount > 0)
            <flux:badge color="yellow" size="sm">
                {{ $this->pendingCount }} {{ __('pending') }}
            </flux:badge>
        @endif
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th class="w-10 px-4 py-3">
                        @if ($statusFilter === 'pending' || $statusFilter === 'all')
                            <flux:checkbox wire:click="toggleSelectAll" :checked="
                                count($selected) > 0
                                && count(array_intersect($selected, $this->commissions->filter(fn ($e) => $e->isPending())->pluck('id')->toArray()))
                                    === count($this->commissions->filter(fn ($e) => $e->isPending())->pluck('id')->toArray())
                                && count($this->commissions->filter(fn ($e) => $e->isPending())) > 0
                            " />
                        @endif
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __('Entity') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __('Source') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __('Type') }}</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __('Basis') }}</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __('Rate') }}</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __('Amount') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __('Earned') }}</th>
                    <th class="px-4 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->commissions as $entry)
                    <tr wire:key="commission-{{ $entry->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                        <td class="px-4 py-3">
                            @if ($entry->isPending())
                                <flux:checkbox wire:model.live="selected" value="{{ $entry->id }}" />
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">
                            <div>{{ $this->resolveCommissionableName($entry) }}</div>
                            @if ($entry->commissionable_type && $entry->commissionable_type !== \App\Models\EmployeeProfile::class)
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $this->resolveCommissionableType($entry) }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                            {{ $entry->source_label ?? '-' }}
                        </td>
                        <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                            {{ ucfirst(str_replace('_', ' ', $entry->commission_type)) }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-400">
                            @if ((float) $entry->basis_amount > 0)
                                ${{ number_format($entry->basis_amount, 2) }}
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-400">
                            @if ((float) $entry->rate > 0)
                                {{ number_format($entry->rate * 100, 2) }}%
                            @else
                                -
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">
                            ${{ number_format($entry->amount, 2) }}
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge
                                size="sm"
                                :color="match($entry->status) {
                                    'pending' => 'yellow',
                                    'approved' => 'green',
                                    'paid' => 'blue',
                                    'reversed' => 'red',
                                    default => 'zinc',
                                }"
                            >
                                {{ ucfirst($entry->status) }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                            {{ $entry->earned_date?->format('M j, Y') ?? '-' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                @if ($entry->isPending())
                                    <flux:button wire:click="approveEntry({{ $entry->id }})" size="xs" variant="primary" icon="check">
                                        {{ __('Approve') }}
                                    </flux:button>
                                @endif
                                @if ($entry->isApproved())
                                    <flux:button wire:click="openReverseModal({{ $entry->id }})" size="xs" variant="ghost" icon="arrow-uturn-left">
                                        {{ __('Reverse') }}
                                    </flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-4 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('No commission entries found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $this->commissions->links() }}
    </div>

    {{-- Manual Entry Modal --}}
    <flux:modal wire:model="showManualModal">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Manual Commission Entry') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Employee') }}</flux:label>
                <flux:select wire:model="manualEmployeeId">
                    <option value="">{{ __('Select an employee...') }}</option>
                    @foreach ($this->employees as $emp)
                        <option wire:key="emp-{{ $emp->id }}" value="{{ $emp->id }}">{{ $emp->user->name }} ({{ $emp->employee_number }})</option>
                    @endforeach
                </flux:select>
                <flux:error name="manualEmployeeId" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Amount') }}</flux:label>
                    <flux:input wire:model="manualAmount" type="number" step="0.01" min="0" prefix="$" />
                    <flux:error name="manualAmount" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Type') }}</flux:label>
                    <flux:select wire:model="manualType">
                        <option value="flat">{{ __('Flat') }}</option>
                        <option value="revenue_percentage">{{ __('Revenue %') }}</option>
                        <option value="profit_percentage">{{ __('Profit %') }}</option>
                    </flux:select>
                    <flux:error name="manualType" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Notes') }}</flux:label>
                <flux:textarea wire:model="manualNotes" rows="3" placeholder="{{ __('Optional notes...') }}" />
                <flux:error name="manualNotes" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showManualModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="createManualEntry">{{ __('Create Entry') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Reverse Modal --}}
    <flux:modal wire:model="showReverseModal">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Reverse Commission') }}</flux:heading>

            <flux:text>{{ __('This will reverse the commission entry. Please provide a reason.') }}</flux:text>

            <flux:field>
                <flux:label>{{ __('Reason') }}</flux:label>
                <flux:textarea wire:model="reverseReason" rows="3" placeholder="{{ __('Why is this being reversed?') }}" />
                <flux:error name="reverseReason" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showReverseModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="reverseEntry">{{ __('Reverse') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
