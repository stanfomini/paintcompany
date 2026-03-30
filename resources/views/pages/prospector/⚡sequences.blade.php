<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\Sequence;
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
#[Title('Prospector Sequences')]
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
    public string $triggerType = 'manual';
    public bool $businessHoursOnly = false;

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
    public function sequences(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Sequence::query()
            ->with(['company', 'createdBy'])
            ->withCount(['steps', 'enrollments as active_enrollments_count' => fn ($q) => $q->where('status', 'active')])
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

    public function openAddModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $sequence = Sequence::findOrFail($id);
        $this->editingId = $sequence->id;
        $this->companyId = $sequence->company_id;
        $this->name = $sequence->name;
        $this->description = $sequence->description ?? '';
        $this->status = $sequence->status;
        $this->triggerType = $sequence->trigger_type;
        $this->businessHoursOnly = $sequence->business_hours_only;
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

        $this->validate([
            'companyId' => ['required', 'exists:prospector_companies,id'],
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('prospector_sequences', 'name')
                    ->where('company_id', $this->companyId)
                    ->ignore($this->editingId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(['draft', 'active', 'paused'])],
            'triggerType' => ['required', Rule::in(['manual', 'new_contact', 'tag_added', 'disposition', 'webhook'])],
            'businessHoursOnly' => ['boolean'],
        ]);

        $data = [
            'company_id' => $this->companyId,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'status' => $this->status,
            'trigger_type' => $this->triggerType,
            'business_hours_only' => $this->businessHoursOnly,
        ];

        if ($this->editingId) {
            $sequence = Sequence::findOrFail($this->editingId);
            $sequence->update($data);
        } else {
            Sequence::create(array_merge($data, [
                'created_by' => Auth::id(),
            ]));
        }

        $this->closeModal();
        unset($this->sequences);
    }

    public function confirmDelete(int $id): void
    {
        $sequence = Sequence::findOrFail($id);
        $this->deletingId = $id;
        $this->deletingName = $sequence->name;
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

        $sequence = Sequence::findOrFail($this->deletingId);
        $sequence->delete();
        $this->cancelDelete();
        unset($this->sequences);
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
        $this->triggerType = 'manual';
        $this->businessHoursOnly = false;
    }
}; ?>

<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Sequences') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Automated drip sequences for multi-touch outreach campaigns.') }}</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openAddModal">
            {{ __('Create Sequence') }}
        </flux:button>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search sequences...')"
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
        </flux:select>
    </div>

    {{-- Sequences table --}}
    @if ($this->sequences->isEmpty())
        <div class="rounded-lg border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="arrows-right-left" class="mx-auto mb-3 size-12 text-zinc-400" />
            <flux:heading size="lg">{{ __('No sequences found') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Create your first drip sequence to automate multi-touch outreach.') }}</flux:text>
            <div class="mt-4">
                <flux:button variant="primary" icon="plus" wire:click="openAddModal">
                    {{ __('Create Sequence') }}
                </flux:button>
            </div>
        </div>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Sequence') }}</flux:table.column>
                <flux:table.column>{{ __('Company') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Trigger') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Steps') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Active') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Completion') }}</flux:table.column>
                <flux:table.column>{{ __('Created By') }}</flux:table.column>
                <flux:table.column class="text-right">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->sequences as $sequence)
                    <flux:table.row wire:key="seq-{{ $sequence->id }}">
                        <flux:table.cell>
                            <a href="{{ route('prospector.sequences.show', $sequence->id) }}" class="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                {{ $sequence->name }}
                            </a>
                            @if ($sequence->description)
                                <div class="max-w-xs truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $sequence->description }}</div>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($sequence->company)
                                <span class="text-sm">{{ $sequence->company->name }}</span>
                            @else
                                <span class="text-zinc-400">{{ __('None') }}</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @switch($sequence->status)
                                @case('draft')
                                    <flux:badge size="sm" color="zinc">{{ __('Draft') }}</flux:badge>
                                    @break
                                @case('active')
                                    <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                    @break
                                @case('paused')
                                    <flux:badge size="sm" color="yellow">{{ __('Paused') }}</flux:badge>
                                    @break
                            @endswitch
                        </flux:table.cell>

                        <flux:table.cell>
                            <span class="text-sm capitalize">{{ str_replace('_', ' ', $sequence->trigger_type) }}</span>
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            {{ $sequence->steps_count }}
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            {{ $sequence->active_enrollments_count }}
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            {{ $sequence->getCompletionRate() }}%
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($sequence->createdBy)
                                <span class="text-sm">{{ $sequence->createdBy->name }}</span>
                            @else
                                <span class="text-zinc-400">{{ __('System') }}</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="eye"
                                    :href="route('prospector.sequences.show', $sequence->id)"
                                    title="{{ __('View') }}"
                                />
                                <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditModal({{ $sequence->id }})" title="{{ __('Edit') }}" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $sequence->id }})" title="{{ __('Delete') }}" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="mt-6">
            {{ $this->sequences->links() }}
        </div>
    @endif

    {{-- Add/Edit Flyout --}}
    <flux:modal wire:model="showModal" variant="flyout" class="w-[420px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit Sequence') : __('Create Sequence') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ $editingId
                        ? __('Update the sequence details below.')
                        : __('Create a new automated drip sequence.')
                    }}
                </flux:text>
            </div>

            <flux:select wire:model="companyId" :label="__('Company')" required>
                <flux:select.option value="">{{ __('Select a company...') }}</flux:select.option>
                @foreach ($this->companies as $company)
                    <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model="name"
                :label="__('Sequence Name')"
                :placeholder="__('e.g., New Lead Follow-Up')"
                required
            />

            <flux:textarea
                wire:model="description"
                :label="__('Description')"
                :placeholder="__('Optional description of this sequence...')"
                rows="2"
            />

            <flux:select wire:model="status" :label="__('Status')" required>
                <flux:select.option value="draft">{{ __('Draft') }}</flux:select.option>
                <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
                <flux:select.option value="paused">{{ __('Paused') }}</flux:select.option>
            </flux:select>

            <flux:select wire:model="triggerType" :label="__('Trigger Type')" required>
                <flux:select.option value="manual">{{ __('Manual') }}</flux:select.option>
                <flux:select.option value="new_contact">{{ __('New Contact Added') }}</flux:select.option>
                <flux:select.option value="tag_added">{{ __('Tag Added') }}</flux:select.option>
                <flux:select.option value="disposition">{{ __('Disposition Set') }}</flux:select.option>
                <flux:select.option value="webhook">{{ __('Webhook') }}</flux:select.option>
            </flux:select>

            <flux:switch
                wire:model="businessHoursOnly"
                :label="__('Business Hours Only')"
                :description="__('Only execute sequence steps during company business hours.')"
            />

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">
                    {{ $editingId ? __('Update Sequence') : __('Create Sequence') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Sequence') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete') }} <strong>{{ $deletingName }}</strong>{{ __('? All steps and enrollments will be permanently removed.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="cancelDelete" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="delete" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
