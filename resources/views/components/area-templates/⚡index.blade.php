<?php

use App\Models\EstimateTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public function boot(): void
    {
        if (! auth()->user()?->can('production_rates.view')) {
            abort(403);
        }
    }

    #[Url]
    public string $search = '';

    #[Url]
    public string $scopeTab = 'all';

    #[Url]
    public string $visibilityFilter = 'all';

    public int $perPage = 15;

    public bool $showDeleteModal = false;

    public ?int $deleteId = null;

    public ?string $deleteName = null;

    #[On('template-created')]
    #[On('template-updated')]
    public function refreshList(): void
    {
        unset($this->templates);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedScopeTab(): void
    {
        $this->resetPage();
    }

    public function updatedVisibilityFilter(): void
    {
        $this->resetPage();
    }

    public function switchTab(string $tab): void
    {
        $this->scopeTab = $tab;
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<EstimateTemplate>
     */
    #[Computed]
    public function templates(): LengthAwarePaginator
    {
        $user = auth()->user();

        $query = EstimateTemplate::query()
            ->configurable()
            ->with(['createdBy', 'productionRate', 'templateCategory'])
            ->withCount('surfaces');

        // Apply visibility filter - specific filters override the general visibleTo scope
        if ($this->visibilityFilter === 'mine') {
            $query->where('visibility', EstimateTemplate::VISIBILITY_PRIVATE)
                ->where('created_by', $user->id);
        } elseif ($this->visibilityFilter === 'company') {
            $query->where('visibility', EstimateTemplate::VISIBILITY_COMPANY);
        } elseif ($this->visibilityFilter === 'system') {
            $query->where('visibility', EstimateTemplate::VISIBILITY_SYSTEM);
        } else {
            // "all" - show all templates visible to user
            $query->visibleTo($user);
        }

        // Apply scope filter
        if ($this->scopeTab !== 'all') {
            $query->byScope($this->scopeTab);
        }

        // Apply search
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('description', 'like', "%{$this->search}%");
            });
        }

        return $query->orderBy('sort_order')->orderBy('name')->paginate($this->perPage);
    }

    public function confirmDelete(int $id): void
    {
        $template = EstimateTemplate::find($id);

        if (! $template) {
            session()->flash('error', __('Template not found.'));

            return;
        }

        if (! $template->canBeDeletedBy(auth()->user())) {
            session()->flash('error', __('You do not have permission to delete this template.'));

            return;
        }

        $this->deleteId = $id;
        $this->deleteName = $template->name;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (! $this->deleteId) {
            return;
        }

        $template = EstimateTemplate::find($this->deleteId);

        if (! $template) {
            session()->flash('error', __('Template not found.'));
            $this->closeDeleteModal();

            return;
        }

        if (! $template->canBeDeletedBy(auth()->user())) {
            session()->flash('error', __('You do not have permission to delete this template.'));
            $this->closeDeleteModal();

            return;
        }

        $template->delete();
        session()->flash('success', __('Template deleted successfully.'));

        $this->closeDeleteModal();
        unset($this->templates);
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deleteId = null;
        $this->deleteName = null;
    }

    public function clone(int $id): void
    {
        $template = EstimateTemplate::find($id);

        if (! $template) {
            session()->flash('error', __('Template not found.'));

            return;
        }

        $newTemplate = $template->replicate();
        $newTemplate->name = $template->name.' (Copy)';
        $newTemplate->slug = null; // Will be auto-generated
        $newTemplate->visibility = EstimateTemplate::VISIBILITY_PRIVATE;
        $newTemplate->created_by = auth()->id();
        $newTemplate->save();

        // Clone surfaces if they exist
        foreach ($template->surfaces as $surface) {
            $newSurface = $surface->replicate();
            $newSurface->estimate_template_id = $newTemplate->id;
            $newSurface->save();
        }

        session()->flash('success', __('Template cloned successfully.'));
        unset($this->templates);
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Area Templates') }}</flux:heading>
            <flux:button href="{{ route('area-templates.create') }}" wire:navigate icon="plus" variant="primary">
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

        <!-- Scope Tabs -->
        <div class="border-b border-zinc-200 dark:border-zinc-700 mb-6">
            <nav class="-mb-px flex space-x-8">
                @foreach ([
                    'all' => __('All'),
                    'interior' => __('Interior'),
                    'exterior' => __('Exterior'),
                ] as $tab => $label)
                    <button
                        wire:click="switchTab('{{ $tab }}')"
                        wire:key="tab-{{ $tab }}"
                        class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors {{ $scopeTab === $tab ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:text-zinc-400 dark:hover:text-zinc-300' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </nav>
        </div>

        <!-- Filters Row -->
        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    :placeholder="__('Search templates...')"
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="visibilityFilter">
                    <option value="all">{{ __('All Templates') }}</option>
                    <option value="mine">{{ __('My Templates') }}</option>
                    <option value="company">{{ __('Company') }}</option>
                    <option value="system">{{ __('System') }}</option>
                </flux:select>
            </div>
        </div>

        @if ($this->templates->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Name') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Scope') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Visibility') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Surfaces') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Created By') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->templates as $template)
                            <tr wire:key="template-{{ $template->id }}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center gap-3">
                                        <flux:icon name="square-3-stack-3d" class="w-5 h-5 text-zinc-400" />
                                        <div>
                                            <flux:text class="font-medium">{{ __($template->name) }}</flux:text>
                                            @if ($template->description)
                                                <flux:text class="text-xs text-zinc-500">{{ Str::limit($template->description, 50) }}</flux:text>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                        $scopeColor = match($template->scope_type) {
                                            'interior' => 'blue',
                                            'exterior' => 'emerald',
                                            'both' => 'purple',
                                            default => 'zinc',
                                        };
                                    @endphp
                                    <flux:badge size="sm" color="{{ $scopeColor }}">
                                        {{ $template->scope_type_label ?? __('N/A') }}
                                    </flux:badge>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                        $visibilityColor = match($template->visibility) {
                                            'system' => 'amber',
                                            'company' => 'blue',
                                            'private' => 'zinc',
                                            default => 'zinc',
                                        };
                                    @endphp
                                    <flux:badge size="sm" color="{{ $visibilityColor }}">
                                        {{ $template->visibility_label }}
                                    </flux:badge>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <flux:text>{{ $template->surfaces_count }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="text-sm">
                                        {{ $template->createdBy?->name ?? __('System') }}
                                    </flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <flux:button
                                            wire:click="clone({{ $template->id }})"
                                            variant="ghost"
                                            size="sm"
                                            icon="document-duplicate"
                                            :title="__('Clone')"
                                        />
                                        <flux:dropdown>
                                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                            <flux:menu>
                                                @if ($template->canBeModifiedBy(auth()->user()))
                                                    <flux:menu.item
                                                        icon="pencil"
                                                        :href="route('area-templates.edit', $template)"
                                                        wire:navigate
                                                    >
                                                        {{ __('Edit') }}
                                                    </flux:menu.item>
                                                @endif
                                                <flux:menu.item
                                                    wire:click="clone({{ $template->id }})"
                                                    icon="document-duplicate"
                                                >
                                                    {{ __('Clone') }}
                                                </flux:menu.item>
                                                @if ($template->canBeDeletedBy(auth()->user()))
                                                    <flux:menu.item
                                                        wire:click="confirmDelete({{ $template->id }})"
                                                        icon="trash"
                                                        variant="danger"
                                                    >
                                                        {{ __('Delete') }}
                                                    </flux:menu.item>
                                                @endif
                                            </flux:menu>
                                        </flux:dropdown>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->templates->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="square-3-stack-3d" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">{{ __('No templates found') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $scopeTab !== 'all' || $visibilityFilter !== 'all')
                        {{ __('Try adjusting your search or filter criteria.') }}
                    @else
                        {{ __('Get started by creating your first area template.') }}
                    @endif
                </flux:text>
                @if (! $search && $scopeTab === 'all' && $visibilityFilter === 'all')
                    <div class="mt-6">
                        <flux:button href="{{ route('area-templates.create') }}" wire:navigate variant="primary" icon="plus">
                            {{ __('Create Template') }}
                        </flux:button>
                    </div>
                @endif
            </div>
        @endif
    </div>

    <!-- Delete Confirmation Modal -->
    <flux:modal wire:model="showDeleteModal" class="md:w-96">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Delete Template') }}</flux:heading>

            <flux:text>
                {!! __('Are you sure you want to delete <strong>:name</strong>? This action cannot be undone.', ['name' => e($deleteName)]) !!}
            </flux:text>

            <div class="flex justify-end gap-3">
                <flux:button wire:click="closeDeleteModal" variant="ghost">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button wire:click="delete" variant="danger" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="delete">{{ __('Delete') }}</span>
                    <span wire:loading wire:target="delete">{{ __('Deleting...') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
