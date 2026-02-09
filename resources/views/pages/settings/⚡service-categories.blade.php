<?php

use App\Models\ServiceCategory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('settings.company')) {
            abort(403);
        }
    }

    // Form state
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $description = '';
    public string $icon = 'paint-brush';
    public string $color = 'blue';
    public bool $is_active = true;

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
    public function categories(): \Illuminate\Database\Eloquent\Collection
    {
        return ServiceCategory::query()->ordered()->get();
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
        $category = ServiceCategory::findOrFail($id);

        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->description = $category->description ?? '';
        $this->icon = $category->icon ?? 'paint-brush';
        $this->color = $category->color ?? 'blue';
        $this->is_active = $category->is_active;
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
                Rule::unique('service_categories', 'name')->ignore($this->editingId),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['required', 'string', 'in:paint-brush,sparkles,wrench,bolt,truck,shield-check,home,star'],
            'color' => ['required', 'string', 'in:blue,green,red,yellow,purple,orange,pink,gray'],
            'is_active' => ['boolean'],
        ]);

        if ($this->editingId) {
            $category = ServiceCategory::findOrFail($this->editingId);
            $category->update([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']),
                'description' => $validated['description'] ?: null,
                'icon' => $validated['icon'],
                'color' => $validated['color'],
                'is_active' => $validated['is_active'],
            ]);
        } else {
            $maxOrder = ServiceCategory::max('sort_order') ?? 0;

            ServiceCategory::create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?: null,
                'icon' => $validated['icon'],
                'color' => $validated['color'],
                'is_active' => $validated['is_active'],
                'sort_order' => $maxOrder + 1,
            ]);
        }

        $this->closeModal();
        unset($this->categories);
        $this->dispatch('service-categories-updated');
    }

    public function toggleActive(int $id): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $category = ServiceCategory::findOrFail($id);
        $category->update(['is_active' => ! $category->is_active]);
        unset($this->categories);
    }

    public function confirmDelete(int $id): void
    {
        $category = ServiceCategory::findOrFail($id);

        $references = $category->crewProfiles()->count()
            + $category->serviceRequirements()->count()
            + $category->prepTasks()->count();

        if ($references > 0) {
            session()->flash('error', "Cannot delete \"{$category->name}\" because it is referenced by {$references} record(s).");

            return;
        }

        $this->deletingId = $id;
        $this->deletingName = $category->name;
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

        $category = ServiceCategory::findOrFail($this->deletingId);

        $references = $category->crewProfiles()->count()
            + $category->serviceRequirements()->count()
            + $category->prepTasks()->count();

        if ($references > 0) {
            session()->flash('error', "Cannot delete \"{$category->name}\" because it is referenced by {$references} record(s).");
            $this->showDeleteModal = false;
            $this->deletingId = null;
            $this->deletingName = '';

            return;
        }

        $category->delete();
        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->deletingName = '';
        unset($this->categories);
        $this->dispatch('service-categories-updated');
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->deletingName = '';
    }

    public function moveUp(int $id): void
    {
        $categories = ServiceCategory::query()->ordered()->get();
        $index = $categories->search(fn ($c) => $c->id === $id);

        if ($index === false || $index === 0) {
            return;
        }

        $current = $categories[$index];
        $previous = $categories[$index - 1];

        $tempOrder = $current->sort_order;
        $current->update(['sort_order' => $previous->sort_order]);
        $previous->update(['sort_order' => $tempOrder]);

        unset($this->categories);
    }

    public function moveDown(int $id): void
    {
        $categories = ServiceCategory::query()->ordered()->get();
        $index = $categories->search(fn ($c) => $c->id === $id);

        if ($index === false || $index === $categories->count() - 1) {
            return;
        }

        $current = $categories[$index];
        $next = $categories[$index + 1];

        $tempOrder = $current->sort_order;
        $current->update(['sort_order' => $next->sort_order]);
        $next->update(['sort_order' => $tempOrder]);

        unset($this->categories);
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->description = '';
        $this->icon = 'paint-brush';
        $this->color = 'blue';
        $this->is_active = true;
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
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Service Categories') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Service Categories')" :subheading="__('Manage the types of services your company offers')">
        <div class="my-6 w-full space-y-6">

            {{-- Info Callout --}}
            <flux:callout variant="info" icon="information-circle">
                <flux:callout.heading>Service Categories</flux:callout.heading>
                <flux:callout.text>
                    Define the services your company provides (e.g., Painting, Cleaning, Repairs).
                    Crews and jobs are organized by these categories.
                </flux:callout.text>
            </flux:callout>

            @if (session('error'))
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>{{ session('error') }}</flux:callout.text>
                </flux:callout>
            @endif

            {{-- Categories List --}}
            <div class="space-y-3">
                @foreach ($this->categories as $category)
                    <div wire:key="cat-{{ $category->id }}" class="flex items-center justify-between p-4 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg {{ ! $category->is_active ? 'opacity-50' : '' }}">
                        <div class="flex items-center gap-3 flex-1 min-w-0">
                            {{-- Icon --}}
                            <div class="shrink-0 w-9 h-9 flex items-center justify-center rounded-lg {{ $this->getColorClasses($category->color ?? 'gray') }}">
                                <flux:icon :name="$category->icon ?? 'paint-brush'" variant="mini" />
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium truncate">{{ $category->name }}</span>
                                    @if (! $category->is_active)
                                        <flux:badge size="sm" color="amber">Inactive</flux:badge>
                                    @endif
                                </div>
                                @if ($category->description)
                                    <div class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5 truncate">{{ $category->description }}</div>
                                @endif
                                <div class="text-xs text-zinc-400 mt-0.5">{{ $category->slug }}</div>
                            </div>
                        </div>

                        <div class="flex items-center gap-1 shrink-0">
                            {{-- Reorder --}}
                            <flux:button size="sm" variant="ghost" icon="chevron-up" wire:click="moveUp({{ $category->id }})" title="Move up" />
                            <flux:button size="sm" variant="ghost" icon="chevron-down" wire:click="moveDown({{ $category->id }})" title="Move down" />

                            {{-- Edit --}}
                            <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditModal({{ $category->id }})" />

                            {{-- Delete --}}
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $category->id }})" />

                            {{-- Toggle Active --}}
                            <flux:switch
                                wire:click="toggleActive({{ $category->id }})"
                                :checked="$category->is_active"
                                size="sm"
                            />
                        </div>
                    </div>
                @endforeach

                @if ($this->categories->isEmpty())
                    <div class="text-center py-8 text-zinc-500">
                        No service categories configured. Add one to get started.
                    </div>
                @endif
            </div>

            {{-- Add Button --}}
            <flux:button variant="primary" icon="plus" wire:click="openAddModal">
                Add Category
            </flux:button>

            <x-action-message on="service-categories-updated">
                {{ __('Saved.') }}
            </x-action-message>
        </div>
    </x-pages::settings.layout>

    {{-- Add/Edit Modal --}}
    <flux:modal wire:model="showModal" variant="flyout" class="w-[420px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Edit Category' : 'Add Category' }}</flux:heading>
                <flux:text class="mt-2">
                    {{ $editingId ? 'Update the service category details below.' : 'Define a new service category for your company.' }}
                </flux:text>
            </div>

            <flux:input
                wire:model="name"
                label="Name"
                placeholder="e.g., Painting, Cleaning, Repairs"
                required
            />

            <flux:textarea
                wire:model="description"
                label="Description (optional)"
                placeholder="What does this service include?"
                rows="2"
            />

            <flux:select wire:model="icon" label="Icon">
                @foreach ($this->iconOptions as $iconValue => $iconLabel)
                    <flux:select.option value="{{ $iconValue }}">{{ $iconLabel }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="color" label="Color">
                @foreach ($this->colorOptions as $colorValue => $colorLabel)
                    <flux:select.option value="{{ $colorValue }}">{{ $colorLabel }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:switch wire:model="is_active" label="Active" description="Inactive categories are hidden from crew and job forms" />

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeModal" class="flex-1">Cancel</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">
                    {{ $editingId ? 'Update' : 'Add' }} Category
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Delete Category</flux:heading>
                <flux:text class="mt-2">
                    Are you sure you want to delete <strong>{{ $deletingName }}</strong>? This action cannot be undone.
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="cancelDelete" class="flex-1">Cancel</flux:button>
                <flux:button variant="danger" wire:click="delete" class="flex-1">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
