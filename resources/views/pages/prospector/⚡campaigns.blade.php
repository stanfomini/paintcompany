<?php

use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Prospector Campaigns')]
class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $filterCompany = '';

    #[Url]
    public string $filterStatus = '';

    // Form state
    public bool $showModal = false;
    public ?int $editingId = null;
    public ?int $companyId = null;
    public string $name = '';
    public string $description = '';
    public string $status = 'draft';
    public ?int $phoneNumberId = null;
    public bool $useLocalPresence = false;
    public string $dialerMode = 'single';
    public int $parallelLines = 2;
    public int $priority = 10;

    // Delete
    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public string $deletingName = '';

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function campaigns(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Campaign::query()
            ->with(['company', 'phoneNumber'])
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->when($this->filterCompany, fn ($q) => $q->where('company_id', $this->filterCompany))
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    #[Computed]
    public function companies(): \Illuminate\Support\Collection
    {
        return Company::query()->active()->orderBy('name')->get();
    }

    #[Computed]
    public function availablePhoneNumbers(): \Illuminate\Support\Collection
    {
        if (! $this->companyId) {
            return PhoneNumber::query()->where('id', 0)->get();
        }

        return PhoneNumber::query()
            ->where('company_id', $this->companyId)
            ->where('is_active', true)
            ->orderBy('friendly_name')
            ->get();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterCompany(): void
    {
        $this->resetPage();
    }

    public function updatedFilterStatus(): void
    {
        $this->resetPage();
    }

    public function updatedCompanyId(): void
    {
        $this->phoneNumberId = null;
        unset($this->availablePhoneNumbers);
    }

    public function openAddModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->editingId = $campaign->id;
        $this->companyId = $campaign->company_id;
        $this->name = $campaign->name;
        $this->description = $campaign->description ?? '';
        $this->status = $campaign->status;
        $this->phoneNumberId = $campaign->phone_number_id;
        $this->useLocalPresence = $campaign->use_local_presence;
        $this->dialerMode = $campaign->dialer_mode ?? 'single';
        $this->parallelLines = $campaign->parallel_lines ?? 2;
        $this->priority = $campaign->priority ?? 10;
        unset($this->availablePhoneNumbers);
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function save(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $validated = $this->validate([
            'companyId' => ['required', 'exists:prospector_companies,id'],
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('prospector_campaigns', 'name')
                    ->where('company_id', $this->companyId)
                    ->ignore($this->editingId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(['draft', 'active', 'paused', 'completed'])],
            'phoneNumberId' => ['nullable', 'exists:prospector_phone_numbers,id'],
            'useLocalPresence' => ['boolean'],
            'dialerMode' => ['required', Rule::in(['single', 'parallel'])],
            'parallelLines' => ['required_if:dialerMode,parallel', 'integer', 'min:1', 'max:3'],
            'priority' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $data = [
            'company_id' => $this->companyId,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'status' => $this->status,
            'phone_number_id' => $this->phoneNumberId,
            'use_local_presence' => $this->useLocalPresence,
            'dialer_mode' => $this->dialerMode,
            'parallel_lines' => $this->dialerMode === 'parallel' ? $this->parallelLines : 1,
            'priority' => $this->priority,
        ];

        if ($this->editingId) {
            $campaign = Campaign::findOrFail($this->editingId);
            $campaign->update($data);
        } else {
            Campaign::create(array_merge($data, [
                'created_by' => Auth::id(),
            ]));
        }

        $this->closeModal();
        unset($this->campaigns);
    }

    public function confirmDelete(int $id): void
    {
        $campaign = Campaign::findOrFail($id);
        $this->deletingId = $id;
        $this->deletingName = $campaign->name;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        if (! $this->deletingId) {
            return;
        }

        $campaign = Campaign::findOrFail($this->deletingId);
        $campaign->delete();
        $this->cancelDelete();
        unset($this->campaigns);
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->deletingName = '';
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->companyId = null;
        $this->name = '';
        $this->description = '';
        $this->status = 'draft';
        $this->phoneNumberId = null;
        $this->useLocalPresence = false;
        $this->dialerMode = 'single';
        $this->parallelLines = 2;
        $this->priority = 10;
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Campaigns') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Manage outbound calling campaigns and track their performance.') }}</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openAddModal">
            {{ __('Create Campaign') }}
        </flux:button>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search campaigns...')"
            class="max-w-sm"
        />

        <flux:select wire:model.live="filterCompany" class="max-w-[200px]">
            <flux:select.option value="">{{ __('All Companies') }}</flux:select.option>
            @foreach ($this->companies as $company)
                <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="filterStatus" class="max-w-[160px]">
            <flux:select.option value="">{{ __('All Statuses') }}</flux:select.option>
            <flux:select.option value="draft">{{ __('Draft') }}</flux:select.option>
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="paused">{{ __('Paused') }}</flux:select.option>
            <flux:select.option value="completed">{{ __('Completed') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- Campaigns table --}}
    @if ($this->campaigns->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="megaphone" class="mx-auto mb-3 size-12 text-zinc-400" />
            <flux:heading size="lg">{{ __('No campaigns found') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Create your first campaign to start reaching contacts.') }}</flux:text>
            <div class="mt-4">
                <flux:button variant="primary" icon="plus" wire:click="openAddModal">
                    {{ __('Create Campaign') }}
                </flux:button>
            </div>
        </div>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Campaign') }}</flux:table.column>
                <flux:table.column>{{ __('Company') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Dialer') }}</flux:table.column>
                <flux:table.column>{{ __('Phone') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Contacts') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Attempted') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Connected') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Rate') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Priority') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->campaigns as $campaign)
                    <flux:table.row wire:key="campaign-{{ $campaign->id }}">
                        <flux:table.cell>
                            <a href="{{ route('prospector.campaigns.show', $campaign->id) }}" class="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                {{ $campaign->name }}
                            </a>
                            @if ($campaign->description)
                                <div class="max-w-xs truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $campaign->description }}</div>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($campaign->company)
                                <span class="text-sm">{{ $campaign->company->name }}</span>
                            @else
                                <span class="text-zinc-400">{{ __('None') }}</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @switch($campaign->status)
                                @case('draft')
                                    <flux:badge size="sm" color="zinc">{{ __('Draft') }}</flux:badge>
                                    @break
                                @case('active')
                                    <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                    @break
                                @case('paused')
                                    <flux:badge size="sm" color="yellow">{{ __('Paused') }}</flux:badge>
                                    @break
                                @case('completed')
                                    <flux:badge size="sm" color="blue">{{ __('Completed') }}</flux:badge>
                                    @break
                            @endswitch
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="text-sm capitalize">{{ $campaign->dialer_mode }}</span>
                            @if ($campaign->dialer_mode === 'parallel')
                                <span class="text-xs text-zinc-500">({{ $campaign->parallel_lines }}x)</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($campaign->phoneNumber)
                                <span class="font-mono text-sm">{{ $campaign->phoneNumber->getDisplayNumber() }}</span>
                            @else
                                <span class="text-zinc-400">{{ __('Not set') }}</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            {{ number_format($campaign->contact_count) }}
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            {{ number_format($campaign->calls_attempted) }}
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            {{ number_format($campaign->calls_connected) }}
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            @if ($campaign->calls_attempted > 0)
                                {{ $campaign->getConnectionRate() }}%
                            @else
                                <span class="text-zinc-400">--</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            {{ $campaign->priority }}
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="eye"
                                    :href="route('prospector.campaigns.show', $campaign->id)"
                                    title="{{ __('View') }}"
                                />
                                <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditModal({{ $campaign->id }})" title="{{ __('Edit') }}" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $campaign->id }})" title="{{ __('Delete') }}" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="mt-6">
            {{ $this->campaigns->links() }}
        </div>
    @endif

    {{-- Add/Edit Flyout --}}
    <flux:modal wire:model="showModal" variant="flyout" class="w-[420px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit Campaign') : __('Create Campaign') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ $editingId
                        ? __('Update the campaign details below.')
                        : __('Create a new outbound calling campaign.')
                    }}
                </flux:text>
            </div>

            <flux:select wire:model.live="companyId" :label="__('Company')" required>
                <flux:select.option value="">{{ __('Select a company...') }}</flux:select.option>
                @foreach ($this->companies as $company)
                    <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model="name"
                :label="__('Campaign Name')"
                :placeholder="__('e.g., Spring Promo Q2')"
                required
            />

            <flux:textarea
                wire:model="description"
                :label="__('Description')"
                :placeholder="__('Optional campaign description...')"
                rows="2"
            />

            <flux:select wire:model="status" :label="__('Status')" required>
                <flux:select.option value="draft">{{ __('Draft') }}</flux:select.option>
                <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
                <flux:select.option value="paused">{{ __('Paused') }}</flux:select.option>
                <flux:select.option value="completed">{{ __('Completed') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model="phoneNumberId" :label="__('Phone Number')">
                <flux:select.option value="">{{ __('Select a number...') }}</flux:select.option>
                @foreach ($this->availablePhoneNumbers as $number)
                    <flux:select.option value="{{ $number->id }}">
                        {{ $number->getDisplayNumber() }} {{ $number->friendly_name ? '- '.$number->friendly_name : '' }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:switch
                wire:model="useLocalPresence"
                :label="__('Use Local Presence')"
                :description="__('Match caller ID area code to contact\'s area code when possible.')"
            />

            <flux:select wire:model.live="dialerMode" :label="__('Dialer Mode')" required>
                <flux:select.option value="single">{{ __('Single Line') }}</flux:select.option>
                <flux:select.option value="parallel">{{ __('Parallel Lines') }}</flux:select.option>
            </flux:select>

            @if ($dialerMode === 'parallel')
                <flux:select wire:model="parallelLines" :label="__('Parallel Lines')">
                    <flux:select.option value="1">1</flux:select.option>
                    <flux:select.option value="2">2</flux:select.option>
                    <flux:select.option value="3">3</flux:select.option>
                </flux:select>
            @endif

            <flux:input
                wire:model="priority"
                :label="__('Priority')"
                type="number"
                min="1"
                max="100"
                :description="__('Lower number = higher priority.')"
            />

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">
                    {{ $editingId ? __('Update Campaign') : __('Create Campaign') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Campaign') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete') }} <strong>{{ $deletingName }}</strong>{{ __('? This action cannot be undone.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="cancelDelete" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="delete" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
