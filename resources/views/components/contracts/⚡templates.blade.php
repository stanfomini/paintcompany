<?php

use App\Models\ContractTemplate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Contract Templates')]
class extends Component
{
    public function boot(): void
    {
        if (! auth()->user()?->can('settings.contracts')) {
            abort(403);
        }
    }

    public string $search = '';

    public string $statusFilter = 'all';

    public string $typeFilter = 'all';

    // Create/Edit form
    public bool $showCreateModal = false;

    public ?int $editingTemplateId = null;

    public string $formName = '';

    public string $formDescription = '';

    public string $formType = 'standard';

    public string $formContentType = 'rich_text';

    public string $formRoleName = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function resetPage(): void
    {
        // Reset pagination placeholder
    }

    #[Computed]
    public function templates()
    {
        return ContractTemplate::query()
            ->with('createdBy')
            ->withCount('employeeContracts')
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('description', 'like', "%{$this->search}%");
                });
            })
            ->when($this->statusFilter === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->statusFilter === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($this->typeFilter !== 'all', fn ($q) => $q->where('type', $this->typeFilter))
            ->latest()
            ->get();
    }

    public function openCreateModal(): void
    {
        $this->editingTemplateId = null;
        $this->formName = '';
        $this->formDescription = '';
        $this->formType = 'standard';
        $this->formContentType = 'rich_text';
        $this->formRoleName = '';
        $this->showCreateModal = true;
    }

    public function saveTemplate(): void
    {
        $this->validate([
            'formName' => 'required|string|max:255',
            'formDescription' => 'nullable|string|max:1000',
            'formType' => 'required|in:standard,role_specific',
            'formContentType' => 'required|in:rich_text,pdf',
            'formRoleName' => 'nullable|string|max:255',
        ]);

        $data = [
            'name' => $this->formName,
            'description' => $this->formDescription ?: null,
            'type' => $this->formType,
            'content_type' => $this->formContentType,
            'role_name' => $this->formType === 'role_specific' ? ($this->formRoleName ?: null) : null,
            'created_by' => auth()->id(),
        ];

        if ($this->editingTemplateId) {
            ContractTemplate::findOrFail($this->editingTemplateId)->update($data);
            session()->flash('success', __('Template updated successfully.'));
        } else {
            $data['version'] = 1;
            $data['is_active'] = true;
            ContractTemplate::create($data);
            session()->flash('success', __('Template created successfully.'));
        }

        $this->showCreateModal = false;
        unset($this->templates);
    }

    public function editTemplate(int $id): void
    {
        $template = ContractTemplate::findOrFail($id);
        $this->editingTemplateId = $template->id;
        $this->formName = $template->name;
        $this->formDescription = $template->description ?? '';
        $this->formType = $template->type;
        $this->formContentType = $template->content_type;
        $this->formRoleName = $template->role_name ?? '';
        $this->showCreateModal = true;
    }

    public function duplicateTemplate(int $id): void
    {
        $template = ContractTemplate::findOrFail($id);

        ContractTemplate::create([
            'name' => $template->name.' (Copy)',
            'description' => $template->description,
            'type' => $template->type,
            'content_type' => $template->content_type,
            'content' => $template->content,
            'pdf_path' => $template->pdf_path,
            'fields' => $template->fields,
            'merge_tags' => $template->merge_tags,
            'role_name' => $template->role_name,
            'is_active' => false,
            'version' => 1,
            'created_by' => auth()->id(),
        ]);

        session()->flash('success', __('Template duplicated.'));
        unset($this->templates);
    }

    public function toggleActive(int $id): void
    {
        $template = ContractTemplate::findOrFail($id);
        $template->update(['is_active' => ! $template->is_active]);
        unset($this->templates);
    }

    public function deleteTemplate(int $id): void
    {
        $template = ContractTemplate::findOrFail($id);

        if ($template->employeeContracts()->exists()) {
            session()->flash('error', __('Cannot delete a template that has been used for contracts.'));

            return;
        }

        $template->delete();
        session()->flash('success', __('Template deleted.'));
        unset($this->templates);
    }
}; ?>

<div>
    <div class="mx-auto max-w-6xl">
        {{-- Header --}}
        <div class="mb-6 flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('Contract Templates') }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                    {{ __('Create and manage contract templates for employees.') }}
                </flux:text>
            </div>
            <flux:button wire:click="openCreateModal" variant="primary" icon="plus">
                {{ __('Create Template') }}
            </flux:button>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        @if (session('error'))
            <flux:callout variant="danger" class="mb-4">
                {{ session('error') }}
            </flux:callout>
        @endif

        {{-- Filters --}}
        <div class="mb-4 flex flex-wrap gap-3">
            <div class="flex-1">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="{{ __('Search templates...') }}" />
            </div>
            <flux:select wire:model.live="statusFilter" class="w-40">
                <flux:select.option value="all">{{ __('All Status') }}</flux:select.option>
                <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
                <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="typeFilter" class="w-40">
                <flux:select.option value="all">{{ __('All Types') }}</flux:select.option>
                <flux:select.option value="standard">{{ __('Standard') }}</flux:select.option>
                <flux:select.option value="role_specific">{{ __('Role Specific') }}</flux:select.option>
            </flux:select>
        </div>

        {{-- Templates List --}}
        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            {{ __('Name') }}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            {{ __('Type') }}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            {{ __('Format') }}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            {{ __('Version') }}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            {{ __('Used') }}
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            {{ __('Status') }}
                        </th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            {{ __('Actions') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                    @forelse ($this->templates as $template)
                        <tr wire:key="template-{{ $template->id }}" class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50" onclick="window.Livewire.navigate('{{ route('contracts.templates.edit', $template) }}')">
                            <td class="whitespace-nowrap px-6 py-4">
                                <div>
                                    <flux:text class="font-medium">{{ $template->name }}</flux:text>
                                    @if ($template->description)
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ Str::limit($template->description, 60) }}</flux:text>
                                    @endif
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4">
                                <flux:badge size="sm" color="{{ $template->type === 'standard' ? 'zinc' : 'blue' }}">
                                    {{ $template->type === 'standard' ? __('Standard') : __('Role Specific') }}
                                </flux:badge>
                                @if ($template->role_name)
                                    <flux:text class="mt-1 text-xs text-zinc-500">{{ $template->role_name }}</flux:text>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-6 py-4">
                                <flux:badge size="sm" color="{{ $template->content_type === 'rich_text' ? 'zinc' : 'amber' }}">
                                    {{ $template->content_type === 'rich_text' ? __('Rich Text') : __('PDF') }}
                                </flux:badge>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4">
                                <flux:text>v{{ $template->version }}</flux:text>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4">
                                <flux:text>{{ $template->employee_contracts_count }} {{ Str::plural(__('contract'), $template->employee_contracts_count) }}</flux:text>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4">
                                <flux:badge size="sm" color="{{ $template->is_active ? 'green' : 'zinc' }}">
                                    {{ $template->is_active ? __('Active') : __('Inactive') }}
                                </flux:badge>
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 text-right" @click.stop>
                                <div class="flex items-center justify-end gap-1">
                                    <flux:button size="xs" variant="ghost" icon="pencil-square" :href="route('contracts.templates.edit', $template)" wire:navigate>
                                        {{ $template->content_type === 'pdf' ? __('Edit PDF') : __('Edit Content') }}
                                    </flux:button>
                                    <flux:dropdown>
                                        <flux:button size="xs" variant="ghost" icon="ellipsis-vertical" />
                                        <flux:menu>
                                            <flux:menu.item wire:click="editTemplate({{ $template->id }})" icon="pencil">
                                                {{ __('Edit Details') }}
                                            </flux:menu.item>
                                            <flux:menu.item wire:click="duplicateTemplate({{ $template->id }})" icon="document-duplicate">
                                                {{ __('Duplicate') }}
                                            </flux:menu.item>
                                            <flux:menu.item wire:click="toggleActive({{ $template->id }})" icon="{{ $template->is_active ? 'eye-slash' : 'eye' }}">
                                                {{ $template->is_active ? __('Deactivate') : __('Activate') }}
                                            </flux:menu.item>
                                            @if ($template->employee_contracts_count === 0)
                                                <flux:menu.item wire:click="deleteTemplate({{ $template->id }})" wire:confirm="{{ __('Are you sure you want to delete this template?') }}" icon="trash" variant="danger">
                                                    {{ __('Delete') }}
                                                </flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center">
                                <flux:icon name="document-text" class="mx-auto h-12 w-12 text-zinc-400" />
                                <flux:heading size="sm" class="mt-4">{{ __('No Templates') }}</flux:heading>
                                <flux:text class="mt-2 text-zinc-500">{{ __('Create your first contract template to get started.') }}</flux:text>
                                <div class="mt-4">
                                    <flux:button wire:click="openCreateModal" variant="primary" size="sm" icon="plus">
                                        {{ __('Create Template') }}
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Create/Edit Modal --}}
    <flux:modal wire:model="showCreateModal" variant="flyout" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">
                {{ $editingTemplateId ? __('Edit Template') : __('Create Template') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ __('Template Name') }}</flux:label>
                <flux:input wire:model="formName" placeholder="{{ __('e.g. Employment Agreement') }}" />
                <flux:error name="formName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:textarea wire:model="formDescription" rows="3" placeholder="{{ __('Brief description of this template...') }}" />
                <flux:error name="formDescription" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Type') }}</flux:label>
                <flux:select wire:model.live="formType">
                    <flux:select.option value="standard">{{ __('Standard') }}</flux:select.option>
                    <flux:select.option value="role_specific">{{ __('Role Specific') }}</flux:select.option>
                </flux:select>
                <flux:description>{{ __('Role-specific templates are shown only for certain job roles.') }}</flux:description>
                <flux:error name="formType" />
            </flux:field>

            @if ($formType === 'role_specific')
                <flux:field>
                    <flux:label>{{ __('Role Name') }}</flux:label>
                    <flux:input wire:model="formRoleName" placeholder="{{ __('e.g. Painter, Foreman') }}" />
                    <flux:error name="formRoleName" />
                </flux:field>
            @endif

            <flux:field>
                <flux:label>{{ __('Content Format') }}</flux:label>
                <flux:select wire:model="formContentType">
                    <flux:select.option value="rich_text">{{ __('Rich Text') }}</flux:select.option>
                    <flux:select.option value="pdf">{{ __('PDF Upload') }}</flux:select.option>
                </flux:select>
                <flux:error name="formContentType" />
            </flux:field>

            <div class="flex justify-end gap-3 pt-4">
                <flux:button wire:click="$set('showCreateModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="saveTemplate" variant="primary">
                    {{ $editingTemplateId ? __('Save Changes') : __('Create Template') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
