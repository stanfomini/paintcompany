<?php

use App\Models\LeadSource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('settings.lead_sources')) {
            abort(403);
        }
    }

    // Form state
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $slug = '';
    public string $type = 'custom';
    public string $icon = 'globe-alt';
    public string $color = 'blue';
    public bool $is_active = true;

    // Track if editing a system source
    public bool $editingSystem = false;

    // Webhook config modal
    public bool $showWebhookConfig = false;
    public ?int $configuringWebhookId = null;

    // Confirmation modal
    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public string $deletingName = '';

    public function mount(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function sources(): \Illuminate\Database\Eloquent\Collection
    {
        return LeadSource::query()->ordered()->get();
    }

    #[Computed]
    public function typeOptions(): array
    {
        return [
            'built_in' => 'System',
            'custom' => 'Custom',
            'webhook' => 'Webhook',
        ];
    }

    #[Computed]
    public function iconOptions(): array
    {
        return [
            'paint-brush' => 'Paint Brush',
            'sparkles' => 'Sparkles',
            'wrench' => 'Wrench',
            'bolt' => 'Bolt',
            'truck' => 'Truck',
            'shield-check' => 'Shield Check',
            'home' => 'Home',
            'star' => 'Star',
            'globe-alt' => 'Globe',
            'users' => 'Users',
            'document-text' => 'Document',
            'link' => 'Link',
            'megaphone' => 'Megaphone',
            'chat-bubble-left' => 'Chat Bubble',
            'flag' => 'Flag',
            'hand-raised' => 'Hand Raised',
            'arrow-path' => 'Arrow Path',
            'ellipsis-horizontal' => 'Ellipsis',
            'signal' => 'Signal',
            'user' => 'User',
        ];
    }

    #[Computed]
    public function colorOptions(): array
    {
        return [
            'blue' => 'Blue',
            'green' => 'Green',
            'red' => 'Red',
            'yellow' => 'Yellow',
            'purple' => 'Purple',
            'orange' => 'Orange',
            'pink' => 'Pink',
            'gray' => 'Gray',
        ];
    }

    public function openAddModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $source = LeadSource::findOrFail($id);

        $this->editingId = $source->id;
        $this->name = $source->name;
        $this->slug = $source->slug ?? '';
        $this->type = $source->type ?? 'custom';
        $this->icon = $source->icon ?? 'globe-alt';
        $this->color = $source->color ?? 'blue';
        $this->is_active = $source->is_active;
        $this->editingSystem = $source->is_system;
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

        $iconKeys = implode(',', array_keys($this->iconOptions));
        $colorKeys = implode(',', array_keys($this->colorOptions));

        $validated = $this->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('lead_sources', 'name')->ignore($this->editingId),
            ],
            'slug' => ['nullable', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:built_in,custom,webhook'],
            'icon' => ['required', 'string', "in:{$iconKeys}"],
            'color' => ['required', 'string', "in:{$colorKeys}"],
            'is_active' => ['boolean'],
        ]);

        $slug = ! empty($validated['slug'])
            ? Str::slug($validated['slug'], '_')
            : Str::slug($validated['name'], '_');

        if ($this->editingId) {
            $source = LeadSource::findOrFail($this->editingId);

            $data = [
                'name' => $validated['name'],
                'slug' => $slug,
                'icon' => $validated['icon'],
                'color' => $validated['color'],
                'is_active' => $validated['is_active'],
            ];

            // Don't allow type change on system sources
            if (! $source->is_system) {
                $data['type'] = $validated['type'];
            }

            $source->update($data);
        } else {
            $maxOrder = LeadSource::max('sort_order') ?? 0;

            LeadSource::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'type' => $validated['type'],
                'icon' => $validated['icon'],
                'color' => $validated['color'],
                'is_active' => $validated['is_active'],
                'is_system' => false,
                'sort_order' => $maxOrder + 1,
            ]);
        }

        $this->closeModal();
        unset($this->sources);
        $this->dispatch('lead-sources-updated');
    }

    public function toggleActive(int $id): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $source = LeadSource::findOrFail($id);
        $source->update(['is_active' => ! $source->is_active]);
        unset($this->sources);
    }

    public function confirmDelete(int $id): void
    {
        $source = LeadSource::findOrFail($id);

        if ($source->is_system) {
            session()->flash('error', __('Cannot delete ":name" because it is a system source.', ['name' => __($source->name)]));

            return;
        }

        $references = $source->getReferenceCount();

        if ($references > 0) {
            session()->flash('error', __('Cannot delete ":name" because it is referenced by :count record(s).', ['name' => __($source->name), 'count' => $references]));

            return;
        }

        $this->deletingId = $id;
        $this->deletingName = $source->name;
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

        $source = LeadSource::findOrFail($this->deletingId);

        if ($source->is_system) {
            session()->flash('error', __('Cannot delete ":name" because it is a system source.', ['name' => __($source->name)]));
            $this->cancelDelete();

            return;
        }

        $references = $source->getReferenceCount();

        if ($references > 0) {
            session()->flash('error', __('Cannot delete ":name" because it is referenced by :count record(s).', ['name' => __($source->name), 'count' => $references]));
            $this->cancelDelete();

            return;
        }

        $source->delete();
        $this->cancelDelete();
        unset($this->sources);
        $this->dispatch('lead-sources-updated');
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->deletingName = '';
    }

    public function openWebhookConfig(int $id): void
    {
        $this->configuringWebhookId = $id;
        $this->showWebhookConfig = true;
    }

    public function closeWebhookConfig(): void
    {
        $this->showWebhookConfig = false;
        $this->configuringWebhookId = null;
    }

    public function moveUp(int $id): void
    {
        $sources = LeadSource::query()->ordered()->get();
        $index = $sources->search(fn ($s) => $s->id === $id);

        if ($index === false || $index === 0) {
            return;
        }

        $current = $sources[$index];
        $previous = $sources[$index - 1];

        $tempOrder = $current->sort_order;
        $current->update(['sort_order' => $previous->sort_order]);
        $previous->update(['sort_order' => $tempOrder]);

        unset($this->sources);
    }

    public function moveDown(int $id): void
    {
        $sources = LeadSource::query()->ordered()->get();
        $index = $sources->search(fn ($s) => $s->id === $id);

        if ($index === false || $index === $sources->count() - 1) {
            return;
        }

        $current = $sources[$index];
        $next = $sources[$index + 1];

        $tempOrder = $current->sort_order;
        $current->update(['sort_order' => $next->sort_order]);
        $next->update(['sort_order' => $tempOrder]);

        unset($this->sources);
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->slug = '';
        $this->type = 'custom';
        $this->icon = 'globe-alt';
        $this->color = 'blue';
        $this->is_active = true;
        $this->editingSystem = false;
    }

    protected function getColorClasses(string $color): string
    {
        return match ($color) {
            'blue' => 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
            'green' => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
            'red' => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
            'yellow' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300',
            'purple' => 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300',
            'orange' => 'bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-300',
            'pink' => 'bg-pink-100 text-pink-700 dark:bg-pink-900 dark:text-pink-300',
            'gray' => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300',
            default => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300',
        };
    }

    protected function getTypeBadgeColor(string $type): string
    {
        return match ($type) {
            'built_in' => 'zinc',
            'webhook' => 'purple',
            'custom' => 'blue',
            default => 'zinc',
        };
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Lead Sources') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Lead Sources')" :subheading="__('Manage where your leads come from')" :fullWidth="true">
        <div class="my-6 w-full space-y-6">

            {{-- Info Callout --}}
            <flux:callout variant="info" icon="information-circle">
                <flux:callout.heading>{{ __('Lead Sources') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Track where your customers and deals come from. System sources are built-in and cannot be deleted. Add custom sources or webhook sources to match your marketing channels.') }}
                </flux:callout.text>
            </flux:callout>

            @if (session('error'))
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>{{ session('error') }}</flux:callout.text>
                </flux:callout>
            @endif

            {{-- Sources grouped by type --}}
            @php
                $grouped = $this->sources->groupBy('type');
                $typeLabels = ['built_in' => __('System Sources'), 'custom' => __('Custom Sources'), 'webhook' => __('Webhook Sources')];
            @endphp

            @foreach (['built_in', 'custom', 'webhook'] as $typeKey)
                @if ($grouped->has($typeKey) && $grouped[$typeKey]->isNotEmpty())
                    <div class="space-y-3">
                        <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400 uppercase tracking-wider text-xs">
                            {{ $typeLabels[$typeKey] }}
                        </flux:heading>

                        @foreach ($grouped[$typeKey] as $source)
                            <div wire:key="src-{{ $source->id }}" class="flex items-center justify-between p-4 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg {{ ! $source->is_active ? 'opacity-50' : '' }}">
                                <div class="flex items-center gap-3 flex-1 min-w-0">
                                    {{-- Icon --}}
                                    <div class="shrink-0 w-9 h-9 flex items-center justify-center rounded-lg {{ $this->getColorClasses($source->color ?? 'gray') }}">
                                        <flux:icon :name="$source->icon ?? 'globe-alt'" variant="mini" />
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium truncate">{{ __($source->name) }}</span>
                                            @if ($source->is_system)
                                                <flux:icon name="lock-closed" variant="micro" class="text-zinc-400" />
                                            @endif
                                            <flux:badge size="sm" :color="$this->getTypeBadgeColor($source->type)">
                                                {{ $this->typeOptions[$source->type] ?? $source->type }}
                                            </flux:badge>
                                            @if (! $source->is_active)
                                                <flux:badge size="sm" color="amber">{{ __('Inactive') }}</flux:badge>
                                            @endif
                                        </div>
                                        <div class="text-xs text-zinc-400 mt-0.5">{{ $source->slug }}</div>
                                    </div>
                                </div>

                                <div class="flex items-center gap-1 shrink-0">
                                    {{-- Reorder --}}
                                    <flux:button size="sm" variant="ghost" icon="chevron-up" wire:click="moveUp({{ $source->id }})" :title="__('Move up')" />
                                    <flux:button size="sm" variant="ghost" icon="chevron-down" wire:click="moveDown({{ $source->id }})" :title="__('Move down')" />

                                    {{-- Configure Webhook --}}
                                    @if ($source->type === 'webhook')
                                        <flux:button size="sm" variant="ghost" icon="cog-6-tooth" wire:click="openWebhookConfig({{ $source->id }})" :title="__('Configure webhook')" />
                                    @endif

                                    {{-- Edit --}}
                                    <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditModal({{ $source->id }})" />

                                    {{-- Delete (not for system sources) --}}
                                    @if (! $source->is_system)
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $source->id }})" />
                                    @endif

                                    {{-- Toggle Active --}}
                                    <flux:switch
                                        wire:click="toggleActive({{ $source->id }})"
                                        :checked="$source->is_active"
                                        size="sm"
                                    />
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endforeach

            @if ($this->sources->isEmpty())
                <div class="text-center py-8 text-zinc-500">
                    {{ __('No lead sources configured. Add one to get started.') }}
                </div>
            @endif

            {{-- Add Button --}}
            <flux:button variant="primary" icon="plus" wire:click="openAddModal">
                {{ __('Add Source') }}
            </flux:button>

            <x-action-message on="lead-sources-updated">
                {{ __('Saved.') }}
            </x-action-message>
        </div>
    </x-pages::settings.layout>

    {{-- Add/Edit Modal --}}
    <flux:modal wire:model="showModal" variant="flyout" class="w-[420px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit Source') : __('Add Source') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ $editingId ? __('Update the lead source details below.') : __('Define a new lead source for tracking where leads come from.') }}
                </flux:text>
            </div>

            <flux:input
                wire:model="name"
                :label="__('Name')"
                :placeholder="__('e.g., Facebook Ads, Door Hanger, Yard Sign')"
                required
            />

            <flux:input
                wire:model="slug"
                :label="__('Slug (optional)')"
                :placeholder="__('Auto-generated from name if empty')"
                :description="__('Used as a unique identifier. Leave blank to auto-generate.')"
            />

            @if (! $editingSystem)
                <flux:select wire:model="type" :label="__('Type')">
                    @foreach ($this->typeOptions as $typeValue => $typeLabel)
                        <flux:select.option value="{{ $typeValue }}">{{ $typeLabel }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <div>
                    <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Type') }}</flux:text>
                    <flux:badge size="sm" color="zinc" class="mt-1">{{ __('System (cannot be changed)') }}</flux:badge>
                </div>
            @endif

            <flux:select wire:model="icon" :label="__('Icon')">
                @foreach ($this->iconOptions as $iconValue => $iconLabel)
                    <flux:select.option value="{{ $iconValue }}">{{ $iconLabel }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="color" :label="__('Color')">
                @foreach ($this->colorOptions as $colorValue => $colorLabel)
                    <flux:select.option value="{{ $colorValue }}">{{ $colorLabel }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:switch wire:model="is_active" :label="__('Active')" :description="__('Inactive sources are hidden from lead forms')" />

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">
                    {{ $editingId ? __('Update Source') : __('Add Source') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Source') }}</flux:heading>
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

    {{-- Webhook Config Modal --}}
    <flux:modal wire:model="showWebhookConfig" variant="flyout" class="w-[600px]">
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Configure Webhook') }}</flux:heading>
                <flux:button variant="ghost" icon="x-mark" wire:click="closeWebhookConfig" />
            </div>

            @if ($configuringWebhookId)
                <livewire:settings.webhook-config :lead-source-id="$configuringWebhookId" :wire:key="'webhook-config-'.$configuringWebhookId" />
            @endif
        </div>
    </flux:modal>
</section>
