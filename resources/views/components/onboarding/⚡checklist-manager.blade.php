<?php

use App\Models\ContractTemplate;
use App\Models\OnboardingChecklist;
use App\Models\OnboardingChecklistItem;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Onboarding Checklists')]
class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('onboarding.manage')) {
            abort(403);
        }
    }

    // Checklist form
    public bool $showChecklistModal = false;
    public ?int $editingChecklistId = null;
    public string $checklistName = '';
    public string $checklistDescription = '';
    public ?string $checklistRoleName = null;
    public bool $checklistIsActive = true;

    // Item form
    public bool $showItemModal = false;
    public ?int $editingItemId = null;
    public ?int $itemChecklistId = null;
    public string $itemTitle = '';
    public string $itemDescription = '';
    public string $itemType = 'task';
    public bool $itemIsRequired = true;
    public ?int $itemContractTemplateId = null;

    // Delete confirmation
    public bool $showDeleteModal = false;
    public ?string $deleteType = null;
    public ?int $deleteId = null;

    // Expanded checklists
    public array $expandedChecklists = [];

    #[Computed]
    public function checklists()
    {
        return OnboardingChecklist::query()
            ->with(['items' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function contractTemplates()
    {
        return ContractTemplate::active()->orderBy('name')->get();
    }

    public function toggleChecklist(int $id): void
    {
        if (in_array($id, $this->expandedChecklists)) {
            $this->expandedChecklists = array_values(array_diff($this->expandedChecklists, [$id]));
        } else {
            $this->expandedChecklists[] = $id;
        }
    }

    // Checklist CRUD
    public function openCreateChecklist(): void
    {
        $this->resetChecklistForm();
        $this->showChecklistModal = true;
    }

    public function openEditChecklist(int $id): void
    {
        $checklist = OnboardingChecklist::findOrFail($id);
        $this->editingChecklistId = $checklist->id;
        $this->checklistName = $checklist->name;
        $this->checklistDescription = $checklist->description ?? '';
        $this->checklistRoleName = $checklist->role_name;
        $this->checklistIsActive = $checklist->is_active;
        $this->showChecklistModal = true;
    }

    public function saveChecklist(): void
    {
        $this->validate([
            'checklistName' => 'required|string|max:255',
            'checklistDescription' => 'nullable|string|max:1000',
            'checklistRoleName' => 'nullable|string|max:255',
        ]);

        $data = [
            'name' => $this->checklistName,
            'description' => $this->checklistDescription ?: null,
            'role_name' => $this->checklistRoleName ?: null,
            'is_active' => $this->checklistIsActive,
        ];

        if ($this->editingChecklistId) {
            OnboardingChecklist::findOrFail($this->editingChecklistId)->update($data);
        } else {
            $maxSort = OnboardingChecklist::max('sort_order') ?? -1;
            $data['sort_order'] = $maxSort + 1;
            OnboardingChecklist::create($data);
        }

        $this->showChecklistModal = false;
        $this->resetChecklistForm();
        unset($this->checklists);
    }

    public function confirmDeleteChecklist(int $id): void
    {
        $this->deleteType = 'checklist';
        $this->deleteId = $id;
        $this->showDeleteModal = true;
    }

    // Item CRUD
    public function openCreateItem(int $checklistId): void
    {
        $this->resetItemForm();
        $this->itemChecklistId = $checklistId;
        $this->showItemModal = true;
    }

    public function openEditItem(int $id): void
    {
        $item = OnboardingChecklistItem::findOrFail($id);
        $this->editingItemId = $item->id;
        $this->itemChecklistId = $item->onboarding_checklist_id;
        $this->itemTitle = $item->title;
        $this->itemDescription = $item->description ?? '';
        $this->itemType = $item->type;
        $this->itemIsRequired = $item->is_required;
        $this->itemContractTemplateId = $item->metadata['contract_template_id'] ?? null;
        $this->showItemModal = true;
    }

    public function saveItem(): void
    {
        $rules = [
            'itemChecklistId' => 'required|exists:onboarding_checklists,id',
            'itemTitle' => 'required|string|max:255',
            'itemDescription' => 'nullable|string|max:1000',
            'itemType' => 'required|in:task,document,contract,training',
        ];

        if ($this->itemType === 'contract') {
            $rules['itemContractTemplateId'] = 'required|exists:contract_templates,id';
        }

        $this->validate($rules);

        $metadata = [];
        if ($this->itemType === 'contract' && $this->itemContractTemplateId) {
            $metadata['contract_template_id'] = $this->itemContractTemplateId;
        }

        $data = [
            'onboarding_checklist_id' => $this->itemChecklistId,
            'title' => $this->itemTitle,
            'description' => $this->itemDescription ?: null,
            'type' => $this->itemType,
            'is_required' => $this->itemIsRequired,
            'metadata' => $metadata ?: null,
        ];

        if ($this->editingItemId) {
            OnboardingChecklistItem::findOrFail($this->editingItemId)->update($data);
        } else {
            $maxSort = OnboardingChecklistItem::where('onboarding_checklist_id', $this->itemChecklistId)->max('sort_order') ?? -1;
            $data['sort_order'] = $maxSort + 1;
            OnboardingChecklistItem::create($data);
        }

        $this->showItemModal = false;
        $this->resetItemForm();
        unset($this->checklists);
    }

    public function confirmDeleteItem(int $id): void
    {
        $this->deleteType = 'item';
        $this->deleteId = $id;
        $this->showDeleteModal = true;
    }

    public function executeDelete(): void
    {
        if ($this->deleteType === 'checklist') {
            OnboardingChecklist::findOrFail($this->deleteId)->delete();
        } elseif ($this->deleteType === 'item') {
            OnboardingChecklistItem::findOrFail($this->deleteId)->delete();
        }

        $this->showDeleteModal = false;
        $this->deleteType = null;
        $this->deleteId = null;
        unset($this->checklists);
    }

    // Sort ordering
    public function moveChecklistUp(int $id): void
    {
        $checklist = OnboardingChecklist::findOrFail($id);
        $previous = OnboardingChecklist::where('sort_order', '<', $checklist->sort_order)
            ->orderByDesc('sort_order')
            ->first();

        if ($previous) {
            $tempSort = $checklist->sort_order;
            $checklist->update(['sort_order' => $previous->sort_order]);
            $previous->update(['sort_order' => $tempSort]);
            unset($this->checklists);
        }
    }

    public function moveChecklistDown(int $id): void
    {
        $checklist = OnboardingChecklist::findOrFail($id);
        $next = OnboardingChecklist::where('sort_order', '>', $checklist->sort_order)
            ->orderBy('sort_order')
            ->first();

        if ($next) {
            $tempSort = $checklist->sort_order;
            $checklist->update(['sort_order' => $next->sort_order]);
            $next->update(['sort_order' => $tempSort]);
            unset($this->checklists);
        }
    }

    public function moveItemUp(int $id): void
    {
        $item = OnboardingChecklistItem::findOrFail($id);
        $previous = OnboardingChecklistItem::where('onboarding_checklist_id', $item->onboarding_checklist_id)
            ->where('sort_order', '<', $item->sort_order)
            ->orderByDesc('sort_order')
            ->first();

        if ($previous) {
            $tempSort = $item->sort_order;
            $item->update(['sort_order' => $previous->sort_order]);
            $previous->update(['sort_order' => $tempSort]);
            unset($this->checklists);
        }
    }

    public function moveItemDown(int $id): void
    {
        $item = OnboardingChecklistItem::findOrFail($id);
        $next = OnboardingChecklistItem::where('onboarding_checklist_id', $item->onboarding_checklist_id)
            ->where('sort_order', '>', $item->sort_order)
            ->orderBy('sort_order')
            ->first();

        if ($next) {
            $tempSort = $item->sort_order;
            $item->update(['sort_order' => $next->sort_order]);
            $next->update(['sort_order' => $tempSort]);
            unset($this->checklists);
        }
    }

    private function resetChecklistForm(): void
    {
        $this->editingChecklistId = null;
        $this->checklistName = '';
        $this->checklistDescription = '';
        $this->checklistRoleName = null;
        $this->checklistIsActive = true;
    }

    private function resetItemForm(): void
    {
        $this->editingItemId = null;
        $this->itemChecklistId = null;
        $this->itemTitle = '';
        $this->itemDescription = '';
        $this->itemType = 'task';
        $this->itemIsRequired = true;
        $this->itemContractTemplateId = null;
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Onboarding Checklists') }}</flux:heading>
            <flux:button variant="primary" icon="plus" wire:click="openCreateChecklist">
                {{ __('New Checklist') }}
            </flux:button>
        </div>

        @if ($this->checklists->isEmpty())
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="clipboard-document-list" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">{{ __('No checklists yet') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    {{ __('Create your first onboarding checklist to get started.') }}
                </flux:text>
            </div>
        @else
            <div class="space-y-4">
                @foreach ($this->checklists as $checklist)
                    <div wire:key="checklist-{{ $checklist->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                        {{-- Checklist Header --}}
                        <div class="flex items-center justify-between px-4 py-3 bg-zinc-50 dark:bg-zinc-800 cursor-pointer"
                             wire:click="toggleChecklist({{ $checklist->id }})">
                            <div class="flex items-center gap-3">
                                <flux:icon name="{{ in_array($checklist->id, $expandedChecklists) ? 'chevron-down' : 'chevron-right' }}" class="w-5 h-5 text-zinc-400" />
                                <div>
                                    <div class="flex items-center gap-2">
                                        <flux:heading size="sm">{{ $checklist->name }}</flux:heading>
                                        @if (! $checklist->is_active)
                                            <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                                        @endif
                                        @if ($checklist->role_name)
                                            <flux:badge size="sm" color="blue">{{ $checklist->role_name }}</flux:badge>
                                        @endif
                                    </div>
                                    @if ($checklist->description)
                                        <flux:text class="text-sm text-zinc-500">{{ $checklist->description }}</flux:text>
                                    @endif
                                </div>
                            </div>
                            <div class="flex items-center gap-1" wire:click.stop>
                                <flux:badge size="sm">{{ $checklist->items->count() }} {{ __('items') }}</flux:badge>
                                <flux:button variant="ghost" size="sm" icon="chevron-up" wire:click="moveChecklistUp({{ $checklist->id }})" />
                                <flux:button variant="ghost" size="sm" icon="chevron-down" wire:click="moveChecklistDown({{ $checklist->id }})" />
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEditChecklist({{ $checklist->id }})" />
                                <flux:button variant="ghost" size="sm" icon="trash" wire:click="confirmDeleteChecklist({{ $checklist->id }})" />
                            </div>
                        </div>

                        {{-- Checklist Items --}}
                        @if (in_array($checklist->id, $expandedChecklists))
                            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @forelse ($checklist->items as $item)
                                    <div wire:key="item-{{ $item->id }}" class="flex items-center justify-between px-4 py-3 pl-12">
                                        <div class="flex items-center gap-3">
                                            @php
                                                $typeColors = ['document' => 'blue', 'contract' => 'purple', 'training' => 'amber', 'task' => 'green'];
                                            @endphp
                                            <flux:badge size="sm" color="{{ $typeColors[$item->type] ?? 'zinc' }}">{{ ucfirst($item->type) }}</flux:badge>
                                            <div>
                                                <flux:text class="font-medium">{{ $item->title }}</flux:text>
                                                @if ($item->description)
                                                    <flux:text class="text-sm text-zinc-500">{{ $item->description }}</flux:text>
                                                @endif
                                            </div>
                                            @if ($item->type === 'contract' && ($templateId = $item->metadata['contract_template_id'] ?? null))
                                                @php $templateName = $this->contractTemplates->firstWhere('id', $templateId)?->name; @endphp
                                                @if ($templateName)
                                                    <flux:badge size="sm" color="violet">{{ $templateName }}</flux:badge>
                                                @endif
                                            @endif
                                            @if (! $item->is_required)
                                                <flux:badge size="sm" color="zinc">{{ __('Optional') }}</flux:badge>
                                            @endif
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <flux:button variant="ghost" size="sm" icon="chevron-up" wire:click="moveItemUp({{ $item->id }})" />
                                            <flux:button variant="ghost" size="sm" icon="chevron-down" wire:click="moveItemDown({{ $item->id }})" />
                                            <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEditItem({{ $item->id }})" />
                                            <flux:button variant="ghost" size="sm" icon="trash" wire:click="confirmDeleteItem({{ $item->id }})" />
                                        </div>
                                    </div>
                                @empty
                                    <div class="px-4 py-6 text-center">
                                        <flux:text class="text-zinc-500">{{ __('No items in this checklist.') }}</flux:text>
                                    </div>
                                @endforelse

                                <div class="px-4 py-3 pl-12 bg-zinc-50 dark:bg-zinc-800/50">
                                    <flux:button variant="ghost" size="sm" icon="plus" wire:click="openCreateItem({{ $checklist->id }})">
                                        {{ __('Add Item') }}
                                    </flux:button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Checklist Modal --}}
    <flux:modal wire:model="showChecklistModal" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">
                {{ $editingChecklistId ? __('Edit Checklist') : __('New Checklist') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="checklistName" placeholder="{{ __('e.g., General Onboarding') }}" />
                @error('checklistName') <flux:text class="text-sm text-red-500">{{ $message }}</flux:text> @enderror
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:textarea wire:model="checklistDescription" rows="2" placeholder="{{ __('Optional description...') }}" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Role (optional)') }}</flux:label>
                <flux:input wire:model="checklistRoleName" placeholder="{{ __('e.g., Painter, Lead Painter (leave blank for all)') }}" />
            </flux:field>

            <flux:field>
                <flux:checkbox wire:model="checklistIsActive" label="{{ __('Active') }}" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showChecklistModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="saveChecklist">{{ __('Save') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Item Modal --}}
    <flux:modal wire:model="showItemModal" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">
                {{ $editingItemId ? __('Edit Item') : __('New Item') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ __('Title') }}</flux:label>
                <flux:input wire:model="itemTitle" placeholder="{{ __('e.g., W-4 Form') }}" />
                @error('itemTitle') <flux:text class="text-sm text-red-500">{{ $message }}</flux:text> @enderror
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:textarea wire:model="itemDescription" rows="2" placeholder="{{ __('Optional description...') }}" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Type') }}</flux:label>
                <flux:select wire:model.live="itemType">
                    <option value="task">{{ __('Task') }}</option>
                    <option value="document">{{ __('Document') }}</option>
                    <option value="contract">{{ __('Contract') }}</option>
                    <option value="training">{{ __('Training') }}</option>
                </flux:select>
            </flux:field>

            @if ($itemType === 'contract')
                <flux:field>
                    <flux:label>{{ __('Contract Template') }}</flux:label>
                    <flux:select wire:model="itemContractTemplateId">
                        <option value="">{{ __('Select a template...') }}</option>
                        @foreach ($this->contractTemplates as $template)
                            <option value="{{ $template->id }}">{{ $template->name }}</option>
                        @endforeach
                    </flux:select>
                    @error('itemContractTemplateId') <flux:text class="text-sm text-red-500">{{ $message }}</flux:text> @enderror
                </flux:field>
            @endif

            <flux:field>
                <flux:checkbox wire:model="itemIsRequired" label="{{ __('Required') }}" />
            </flux:field>

            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showItemModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="saveItem">{{ __('Save') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" class="max-w-sm">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Confirm Delete') }}</flux:heading>
            <flux:text>
                @if ($deleteType === 'checklist')
                    {{ __('Are you sure you want to delete this checklist and all its items? This cannot be undone.') }}
                @else
                    {{ __('Are you sure you want to delete this item? This cannot be undone.') }}
                @endif
            </flux:text>
            <div class="flex justify-end gap-3">
                <flux:button wire:click="$set('showDeleteModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="executeDelete">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
