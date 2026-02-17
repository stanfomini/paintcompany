<?php

use App\Models\EmployeeProfile;
use App\Models\PtoRequest;
use App\Services\SchedulingService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('PTO Requests')]
class extends Component {
    use WithPagination;

    public function boot(): void
    {
        if (! auth()->user()?->can('scheduling.view')) {
            abort(403);
        }
    }

    public string $statusFilter = 'all';

    public string $typeFilter = 'all';

    public string $employeeFilter = 'all';

    // Create form
    public bool $showCreateModal = false;

    public string $formType = 'vacation';

    public string $formStartDate = '';

    public string $formEndDate = '';

    public string $formHours = '8.0';

    public string $formNotes = '';

    // Deny form
    public bool $showDenyModal = false;

    public ?int $denyingRequestId = null;

    public string $denyReason = '';

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedEmployeeFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()?->can('scheduling.manage') ?? false;
    }

    #[Computed]
    public function requests()
    {
        $query = PtoRequest::query()
            ->with(['employeeProfile.user', 'approvedBy']);

        // Non-managers can only see their own
        if (! $this->canManage) {
            $employeeProfile = auth()->user()?->employeeProfile;
            if ($employeeProfile) {
                $query->where('employee_profile_id', $employeeProfile->id);
            } else {
                return PtoRequest::query()->whereRaw('1 = 0')->paginate(15);
            }
        }

        return $query
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->typeFilter !== 'all', fn ($q) => $q->where('type', $this->typeFilter))
            ->when($this->employeeFilter !== 'all', fn ($q) => $q->where('employee_profile_id', (int) $this->employeeFilter))
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    #[Computed]
    public function employees()
    {
        return EmployeeProfile::query()
            ->with('user')
            ->where('employment_status', 'active')
            ->orderBy('employee_number')
            ->get();
    }

    public function openCreateModal(): void
    {
        $this->resetCreateForm();
        $this->showCreateModal = true;
    }

    public function createRequest(): void
    {
        $this->validate([
            'formType' => 'required|in:vacation,sick,personal,unpaid,bereavement,jury_duty',
            'formStartDate' => 'required|date',
            'formEndDate' => 'required|date|after_or_equal:formStartDate',
            'formHours' => 'required|numeric|min:0.5',
        ]);

        $employeeProfile = auth()->user()?->employeeProfile;
        if (! $employeeProfile) {
            $this->addError('formType', __('No employee profile found.'));

            return;
        }

        PtoRequest::create([
            'employee_profile_id' => $employeeProfile->id,
            'type' => $this->formType,
            'start_date' => $this->formStartDate,
            'end_date' => $this->formEndDate,
            'hours' => (float) $this->formHours,
            'notes' => $this->formNotes ?: null,
        ]);

        $this->showCreateModal = false;
        $this->resetCreateForm();
        unset($this->requests);
        session()->flash('success', __('PTO request submitted.'));
    }

    public function approveRequest(int $requestId): void
    {
        if (! $this->canManage) {
            return;
        }

        $request = PtoRequest::findOrFail($requestId);
        app(SchedulingService::class)->approvePto($request, auth()->user());
        unset($this->requests);
        session()->flash('success', __('PTO request approved.'));
    }

    public function openDenyModal(int $requestId): void
    {
        if (! $this->canManage) {
            return;
        }

        $this->denyingRequestId = $requestId;
        $this->denyReason = '';
        $this->showDenyModal = true;
    }

    public function denyRequest(): void
    {
        if (! $this->canManage || ! $this->denyingRequestId) {
            return;
        }

        $this->validate([
            'denyReason' => 'required|min:3',
        ]);

        $request = PtoRequest::findOrFail($this->denyingRequestId);
        app(SchedulingService::class)->denyPto($request, auth()->user(), $this->denyReason);

        $this->showDenyModal = false;
        $this->denyingRequestId = null;
        $this->denyReason = '';
        unset($this->requests);
        session()->flash('success', __('PTO request denied.'));
    }

    private function resetCreateForm(): void
    {
        $this->formType = 'vacation';
        $this->formStartDate = '';
        $this->formEndDate = '';
        $this->formHours = '8.0';
        $this->formNotes = '';
        $this->resetValidation();
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('PTO Requests') }}</flux:heading>
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                {{ __('Request PTO') }}
            </flux:button>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        {{-- Filters --}}
        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="sm:w-40">
                <flux:select wire:model.live="statusFilter">
                    <option value="all">{{ __('All Statuses') }}</option>
                    <option value="pending">{{ __('Pending') }}</option>
                    <option value="approved">{{ __('Approved') }}</option>
                    <option value="denied">{{ __('Denied') }}</option>
                </flux:select>
            </div>
            <div class="sm:w-40">
                <flux:select wire:model.live="typeFilter">
                    <option value="all">{{ __('All Types') }}</option>
                    <option value="vacation">{{ __('Vacation') }}</option>
                    <option value="sick">{{ __('Sick') }}</option>
                    <option value="personal">{{ __('Personal') }}</option>
                    <option value="unpaid">{{ __('Unpaid') }}</option>
                    <option value="bereavement">{{ __('Bereavement') }}</option>
                    <option value="jury_duty">{{ __('Jury Duty') }}</option>
                </flux:select>
            </div>
            @if ($this->canManage)
                <div class="sm:w-56">
                    <flux:select wire:model.live="employeeFilter">
                        <option value="all">{{ __('All Employees') }}</option>
                        @foreach ($this->employees as $emp)
                            <flux:select.option value="{{ $emp->id }}">{{ $emp->user?->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif
        </div>

        {{-- Requests Table --}}
        @if ($this->requests->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            @if ($this->canManage)
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Employee') }}</th>
                            @endif
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Type') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Dates') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Hours') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Status') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Notes') }}</th>
                            @if ($this->canManage)
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">{{ __('Actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->requests as $request)
                            <tr wire:key="pto-{{ $request->id }}">
                                @if ($this->canManage)
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text class="font-medium">{{ $request->employeeProfile?->user?->name }}</flux:text>
                                    </td>
                                @endif
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="capitalize">{{ str_replace('_', ' ', $request->type) }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $request->start_date->format('M j, Y') }}</flux:text>
                                    @if ($request->start_date->ne($request->end_date))
                                        <flux:text class="text-zinc-500"> - {{ $request->end_date->format('M j, Y') }}</flux:text>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $request->hours }}h</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($request->status === 'pending')
                                        <flux:badge size="sm" color="yellow">{{ __('Pending') }}</flux:badge>
                                    @elseif ($request->status === 'approved')
                                        <flux:badge size="sm" color="green">{{ __('Approved') }}</flux:badge>
                                    @elseif ($request->status === 'denied')
                                        <flux:badge size="sm" color="red">{{ __('Denied') }}</flux:badge>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    <flux:text class="text-sm text-zinc-500 max-w-xs truncate">
                                        {{ $request->notes ?? ($request->denial_reason ? 'Denied: ' . $request->denial_reason : '-') }}
                                    </flux:text>
                                </td>
                                @if ($this->canManage)
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        @if ($request->isPending())
                                            <div class="flex items-center justify-end gap-2">
                                                <flux:button size="sm" variant="primary" wire:click="approveRequest({{ $request->id }})">
                                                    {{ __('Approve') }}
                                                </flux:button>
                                                <flux:button size="sm" variant="danger" wire:click="openDenyModal({{ $request->id }})">
                                                    {{ __('Deny') }}
                                                </flux:button>
                                            </div>
                                        @else
                                            <flux:text class="text-sm text-zinc-400">
                                                {{ $request->approvedBy?->name }}
                                            </flux:text>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->requests->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="calendar-days" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">{{ __('No PTO requests') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">{{ __('No PTO requests match the current filters.') }}</flux:text>
            </div>
        @endif
    </div>

    {{-- Create PTO Modal --}}
    <flux:modal wire:model="showCreateModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Request PTO') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Type') }}</flux:label>
                <flux:select wire:model="formType">
                    <flux:select.option value="vacation">{{ __('Vacation') }}</flux:select.option>
                    <flux:select.option value="sick">{{ __('Sick') }}</flux:select.option>
                    <flux:select.option value="personal">{{ __('Personal') }}</flux:select.option>
                    <flux:select.option value="unpaid">{{ __('Unpaid') }}</flux:select.option>
                    <flux:select.option value="bereavement">{{ __('Bereavement') }}</flux:select.option>
                    <flux:select.option value="jury_duty">{{ __('Jury Duty') }}</flux:select.option>
                </flux:select>
                @error('formType') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Start Date') }}</flux:label>
                    <flux:input type="date" wire:model="formStartDate" />
                    @error('formStartDate') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('End Date') }}</flux:label>
                    <flux:input type="date" wire:model="formEndDate" />
                    @error('formEndDate') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Total Hours') }}</flux:label>
                <flux:input type="number" wire:model="formHours" step="0.5" min="0.5" />
                @error('formHours') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Notes (optional)') }}</flux:label>
                <flux:textarea wire:model="formNotes" rows="2" />
            </flux:field>

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showCreateModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="createRequest">{{ __('Submit Request') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Deny PTO Modal --}}
    <flux:modal wire:model="showDenyModal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Deny PTO Request') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Reason for Denial') }}</flux:label>
                <flux:textarea wire:model="denyReason" rows="3" />
                @error('denyReason') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
            </flux:field>

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showDenyModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="denyRequest">{{ __('Deny Request') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
