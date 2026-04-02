<?php

use App\Models\Prospector\Campaign;
use App\Models\Prospector\RvmTemplate;
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
    public string $filterStatus = '';

    // Form state
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $description = '';
    public string $status = 'draft';
    public bool $useLocalPresence = true;
    public int $priority = 10;
    public bool $autoVmDrop = false;
    public ?int $defaultRvmTemplateId = null;

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
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->orderByDesc('created_at')
            ->paginate(15);
    }

    #[Computed]
    public function availableRvmTemplates(): \Illuminate\Support\Collection
    {
        return RvmTemplate::query()
            ->active()
            ->orderBy('name')
            ->get();
    }

    public function updatedSearch(): void
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
        $campaign = Campaign::findOrFail($id);
        $this->editingId = $campaign->id;
        $this->name = $campaign->name;
        $this->description = $campaign->description ?? '';
        $this->status = $campaign->status;
        $this->useLocalPresence = $campaign->use_local_presence;
        $this->priority = $campaign->priority ?? 10;
        $this->autoVmDrop = $campaign->auto_vm_drop ?? false;
        $this->defaultRvmTemplateId = $campaign->default_rvm_template_id;
        unset($this->availableRvmTemplates);
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
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('prospector_campaigns', 'name')
                    ->ignore($this->editingId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::in(['draft', 'active', 'paused', 'completed'])],
            'useLocalPresence' => ['boolean'],
            'priority' => ['required', 'integer', 'min:1', 'max:100'],
            'autoVmDrop' => ['boolean'],
            'defaultRvmTemplateId' => ['nullable', 'exists:prospector_rvm_templates,id'],
        ]);

        $data = [
            'name' => $this->name,
            'description' => $this->description ?: null,
            'status' => $this->status,
            'use_local_presence' => $this->useLocalPresence,
            'priority' => $this->priority,
            'auto_vm_drop' => $this->autoVmDrop,
            'default_rvm_template_id' => $this->autoVmDrop ? $this->defaultRvmTemplateId : null,
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

    public function cloneCampaign(int $id): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $original = Campaign::findOrFail($id);

        Campaign::create([
            'name' => $original->name.' (Copy)',
            'description' => $original->description,
            'status' => 'draft',
            'use_local_presence' => $original->use_local_presence,
            'priority' => $original->priority,
            'auto_vm_drop' => $original->auto_vm_drop,
            'default_rvm_template_id' => $original->default_rvm_template_id,
            'disposition_routes' => $original->disposition_routes,
            'max_calls_per_day' => $original->max_calls_per_day,
            'max_total_attempts' => $original->max_total_attempts,
            'max_days_in_campaign' => $original->max_days_in_campaign,
            'min_minutes_between' => $original->min_minutes_between,
            'created_by' => Auth::id(),
        ]);

        unset($this->campaigns);
        session()->flash('success', __('Campaign cloned successfully.'));
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->description = '';
        $this->status = 'draft';
        $this->useLocalPresence = true;
        $this->priority = 10;
        $this->autoVmDrop = false;
        $this->defaultRvmTemplateId = null;
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

    @if (session('success'))
        <flux:callout color="green" icon="check-circle" class="mb-4">
            <flux:callout.text>{{ session('success') }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <flux:input
            wire:model.live.debounce.300ms="search"
            icon="magnifying-glass"
            :placeholder="__('Search campaigns...')"
            class="max-w-sm"
        />

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
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column>{{ __('Local Presence') }}</flux:table.column>
                <flux:table.column>{{ __('Auto-VM') }}</flux:table.column>
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
                            @if ($campaign->use_local_presence)
                                <flux:badge size="sm" color="green">{{ __('On') }}</flux:badge>
                            @else
                                <span class="text-zinc-400">{{ __('Off') }}</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($campaign->auto_vm_drop)
                                <flux:badge size="sm" color="green">{{ __('On') }}</flux:badge>
                            @else
                                <span class="text-zinc-400">{{ __('Off') }}</span>
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
                                <flux:button size="sm" variant="ghost" icon="document-duplicate" wire:click="cloneCampaign({{ $campaign->id }})" title="{{ __('Clone') }}" />
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

            <flux:switch
                wire:model="useLocalPresence"
                :label="__('Use Local Presence')"
                :description="__('Match caller ID area code to contact\'s area code when possible.')"
            />

            <flux:input
                wire:model="priority"
                :label="__('Priority')"
                type="number"
                min="1"
                max="100"
                :description="__('Lower number = higher priority.')"
            />

            <flux:separator />

            <flux:switch
                wire:model.live="autoVmDrop"
                :label="__('Auto-drop VM on Answering Machine')"
                :description="__('Automatically leave a voicemail when AMD detects a machine.')"
            />

            @if ($autoVmDrop)
                <flux:select wire:model="defaultRvmTemplateId" :label="__('Default RVM Template')">
                    <flux:select.option value="">{{ __('Select a template...') }}</flux:select.option>
                    @foreach ($this->availableRvmTemplates as $template)
                        <flux:select.option value="{{ $template->id }}">
                            {{ $template->name }}
                            @if ($template->duration_seconds)
                                ({{ $template->duration_seconds }}s)
                            @endif
                        </flux:select.option>
                    @endforeach
                </flux:select>

                @if ($this->availableRvmTemplates->isEmpty() && $companyId)
                    <flux:text class="text-xs text-amber-600 dark:text-amber-400">
                        {{ __('No active RVM templates found for this company. Create one in RVM Templates first.') }}
                    </flux:text>
                @endif
            @endif

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
