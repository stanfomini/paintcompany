<?php

use App\Models\ServiceCategory;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public function boot(): void
    {
        if (! auth()->user()?->can('people.view')) {
            abort(403);
        }
    }

    public string $search = '';

    public string $statusFilter = 'all';

    public string $complianceFilter = 'all';

    public string $serviceCategoryFilter = '';

    public int $perPage = 15;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedComplianceFilter(): void
    {
        $this->resetPage();
    }

    public function updatedServiceCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function activate(User $crew): void
    {
        $crew->update(['is_active' => true]);
        session()->flash('success', 'Crew activated successfully.');
    }

    public function deactivate(User $crew): void
    {
        $crew->update(['is_active' => false]);
        session()->flash('success', 'Crew deactivated successfully.');
    }

    #[Computed]
    public function crews()
    {
        return User::query()
            ->where('type', 'crew')
            ->with(['crewProfile.serviceCategory', 'crewJobs'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%")
                        ->orWhereHas('crewProfile', function ($profileQuery) {
                            $profileQuery->where('company_name', 'like', "%{$this->search}%")
                                ->orWhere('phone', 'like', "%{$this->search}%");
                        });
                });
            })
            ->when($this->statusFilter !== 'all', function ($query) {
                return $query->where('is_active', $this->statusFilter === 'active');
            })
            ->when($this->serviceCategoryFilter !== '', function ($query) {
                if ($this->serviceCategoryFilter === 'unassigned') {
                    $query->whereHas('crewProfile', fn ($q) => $q->whereNull('service_category_id'));
                } else {
                    $query->whereHas('crewProfile', fn ($q) => $q->where('service_category_id', $this->serviceCategoryFilter));
                }
            })
            ->when($this->complianceFilter !== 'all', function ($query) {
                return $query->whereHas('crewProfile', function ($q) {
                    if ($this->complianceFilter === 'expired') {
                        $q->where(function ($query) {
                            $query->whereNotNull('insurance_expiration_date')
                                ->where('insurance_expiration_date', '<', now());
                        })->orWhere(function ($query) {
                            $query->whereNotNull('license_expiration_date')
                                ->where('license_expiration_date', '<', now());
                        })->orWhere(function ($query) {
                            $query->whereNotNull('workers_comp_expiration_date')
                                ->where('workers_comp_expiration_date', '<', now());
                        });
                    } elseif ($this->complianceFilter === 'warning') {
                        $q->where(function ($query) {
                            $query->whereNotNull('insurance_expiration_date')
                                ->where('insurance_expiration_date', '>=', now())
                                ->where('insurance_expiration_date', '<=', now()->addDays(30));
                        })->orWhere(function ($query) {
                            $query->whereNotNull('license_expiration_date')
                                ->where('license_expiration_date', '>=', now())
                                ->where('license_expiration_date', '<=', now()->addDays(30));
                        })->orWhere(function ($query) {
                            $query->whereNotNull('workers_comp_expiration_date')
                                ->where('workers_comp_expiration_date', '>=', now())
                                ->where('workers_comp_expiration_date', '<=', now()->addDays(30));
                        });
                    } elseif ($this->complianceFilter === 'compliant') {
                        $q->where(function ($query) {
                            $query->whereNull('insurance_expiration_date')
                                ->orWhere('insurance_expiration_date', '>', now()->addDays(30));
                        })->where(function ($query) {
                            $query->whereNull('license_expiration_date')
                                ->orWhere('license_expiration_date', '>', now()->addDays(30));
                        })->where(function ($query) {
                            $query->whereNull('workers_comp_expiration_date')
                                ->orWhere('workers_comp_expiration_date', '>', now()->addDays(30));
                        });
                    }
                });
            })
            ->latest()
            ->paginate($this->perPage);
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">Crews</flux:heading>
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
                    placeholder="Search by name, company, or phone..."
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="statusFilter">
                    <option value="all">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </flux:select>
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="complianceFilter">
                    <option value="all">All Compliance</option>
                    <option value="compliant">Compliant</option>
                    <option value="warning">Expiring Soon</option>
                    <option value="expired">Expired</option>
                </flux:select>
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="serviceCategoryFilter">
                    <option value="">All Services</option>
                    <option value="unassigned">Unassigned</option>
                    @foreach (ServiceCategory::active()->ordered()->get() as $category)
                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($this->crews->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Name
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Company
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Crew Size
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Service
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Specialties
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Jobs
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Compliance
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Status
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->crews as $crew)
                            <tr wire:key="crew-{{ $crew->id }}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="font-medium">{{ $crew->name }}</flux:text>
                                    <flux:text class="text-sm text-zinc-500">{{ $crew->email }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $crew->crewProfile?->company_name ?? '-' }}</flux:text>
                                    @if ($crew->crewProfile?->phone)
                                        <flux:text class="text-sm text-zinc-500">{{ $crew->crewProfile->phone }}</flux:text>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $crew->crewProfile?->crew_size ?? 1 }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($crew->crewProfile?->serviceCategory)
                                        <flux:badge size="sm" color="{{ $crew->crewProfile->serviceCategory->color ?? 'zinc' }}">
                                            {{ $crew->crewProfile->serviceCategory->name }}
                                        </flux:badge>
                                    @else
                                        <flux:text class="text-zinc-400">-</flux:text>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($crew->crewProfile?->specialties)
                                        <div class="flex flex-wrap gap-1">
                                            @foreach (array_slice($crew->crewProfile->specialties, 0, 2) as $specialty)
                                                <flux:badge size="sm">{{ ucfirst($specialty) }}</flux:badge>
                                            @endforeach
                                            @if (count($crew->crewProfile->specialties) > 2)
                                                <flux:badge size="sm" color="zinc">+{{ count($crew->crewProfile->specialties) - 2 }}</flux:badge>
                                            @endif
                                        </div>
                                    @else
                                        <flux:text class="text-zinc-400">-</flux:text>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $crew->crewJobs->count() }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                        $status = $crew->crewProfile?->getComplianceStatus() ?? 'compliant';
                                    @endphp
                                    @if ($status === 'expired')
                                        <flux:badge size="sm" color="red">Expired</flux:badge>
                                    @elseif ($status === 'warning')
                                        <flux:badge size="sm" color="yellow">Expiring Soon</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="green">Compliant</flux:badge>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($crew->is_active)
                                        <flux:badge size="sm" color="green">Active</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" :href="route('crews.show', $crew)" wire:navigate>
                                                View
                                            </flux:menu.item>
                                            @if ($crew->is_active)
                                                <flux:menu.item icon="x-circle" wire:click="deactivate({{ $crew->id }})" variant="danger">
                                                    Deactivate
                                                </flux:menu.item>
                                            @else
                                                <flux:menu.item icon="check-circle" wire:click="activate({{ $crew->id }})">
                                                    Activate
                                                </flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->crews->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="user-group" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">No crews found</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $statusFilter !== 'all' || $complianceFilter !== 'all' || $serviceCategoryFilter !== '')
                        Try adjusting your search or filter criteria.
                    @else
                        There are no crews in the system yet.
                    @endif
                </flux:text>
            </div>
        @endif
    </div>
</div>
