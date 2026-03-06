<?php

use App\Models\EmployeeProfile;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Employees')]
class extends Component {
    use WithPagination;

    public function boot(): void
    {
        if (! auth()->user()?->can('employees.view')) {
            abort(403);
        }
    }

    public string $search = '';

    public string $statusFilter = 'all';

    public string $departmentFilter = 'all';

    public int $perPage = 15;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDepartmentFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function employees()
    {
        return EmployeeProfile::query()
            ->with('user')
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('employee_number', 'like', "%{$this->search}%")
                        ->orWhere('job_title', 'like', "%{$this->search}%")
                        ->orWhere('department', 'like', "%{$this->search}%")
                        ->orWhere('phone', 'like', "%{$this->search}%")
                        ->orWhereHas('user', function ($uq) {
                            $uq->where('name', 'like', "%{$this->search}%")
                                ->orWhere('email', 'like', "%{$this->search}%");
                        });
                });
            })
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('employment_status', $this->statusFilter))
            ->when($this->departmentFilter !== 'all', fn ($q) => $q->where('department', $this->departmentFilter))
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function departments()
    {
        return EmployeeProfile::query()
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->pluck('department')
            ->sort()
            ->values();
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Employees') }}</flux:heading>
            @can('employees.create')
                <flux:button variant="primary" icon="plus" wire:click="$dispatch('open-create-employee-modal')">
                    {{ __('Add Employee') }}
                </flux:button>
            @endcan
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="{{ __('Search by name, email, employee #, title, or phone...') }}"
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="statusFilter">
                    <option value="all">{{ __('All Statuses') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="on_leave">{{ __('On Leave') }}</option>
                    <option value="terminated">{{ __('Terminated') }}</option>
                </flux:select>
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="departmentFilter">
                    <option value="all">{{ __('All Departments') }}</option>
                    @foreach ($this->departments as $department)
                        <flux:select.option value="{{ $department }}">{{ $department }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($this->employees->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Employee #') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Name') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Job Title') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Department') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Pay Rate') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Status') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Hire Date') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->employees as $employee)
                            <tr wire:key="employee-{{ $employee->id }}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="font-mono text-sm">{{ $employee->employee_number }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="font-medium">{{ $employee->user?->name ?? '-' }}</flux:text>
                                    <flux:text class="text-sm text-zinc-500">{{ $employee->user?->email }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $employee->job_title ?? '-' }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $employee->department ?? '-' }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $employee->getPayRateLabel() }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($employee->employment_status === 'active')
                                        <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                    @elseif ($employee->employment_status === 'on_leave')
                                        <flux:badge size="sm" color="yellow">{{ __('On Leave') }}</flux:badge>
                                    @elseif ($employee->employment_status === 'terminated')
                                        <flux:badge size="sm" color="red">{{ __('Terminated') }}</flux:badge>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $employee->hire_date?->format('M d, Y') ?? '-' }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" :href="route('employees.show', $employee)" wire:navigate>
                                                {{ __('View') }}
                                            </flux:menu.item>
                                            @can('employees.edit')
                                                @if ($employee->employment_status === 'active')
                                                    <flux:menu.item icon="pause-circle" wire:click="$dispatch('open-edit-status-modal', { employeeId: {{ $employee->id }}, status: 'on_leave' })">
                                                        {{ __('Set On Leave') }}
                                                    </flux:menu.item>
                                                @elseif ($employee->employment_status === 'on_leave')
                                                    <flux:menu.item icon="play-circle" wire:click="$dispatch('open-edit-status-modal', { employeeId: {{ $employee->id }}, status: 'active' })">
                                                        {{ __('Set Active') }}
                                                    </flux:menu.item>
                                                @endif
                                            @endcan
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->employees->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="user-group" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">{{ __('No employees found') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $statusFilter !== 'all' || $departmentFilter !== 'all')
                        {{ __('Try adjusting your search or filter criteria.') }}
                    @else
                        {{ __('There are no employees in the system yet.') }}
                    @endif
                </flux:text>
            </div>
        @endif
    </div>

    @can('employees.create')
        @livewire('employees.create-modal')
    @endcan
</div>
