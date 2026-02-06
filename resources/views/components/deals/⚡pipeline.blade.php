<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('deals.view')) {
            abort(403);
        }
    }

    public string $search = '';

    public string $assignedToFilter = '';

    public string $customerFilter = '';

    #[On('deal-created')]
    #[On('deal-updated')]
    public function refreshPipeline(): void
    {
        // Re-render is triggered automatically
    }

    /**
     * Get the stage colors for display.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function stageColors(): array
    {
        return [
            'lead' => 'bg-blue-100 dark:bg-blue-900/50 border-blue-300 dark:border-blue-700',
            'quoted' => 'bg-purple-100 dark:bg-purple-900/50 border-purple-300 dark:border-purple-700',
            'scheduled' => 'bg-yellow-100 dark:bg-yellow-900/50 border-yellow-300 dark:border-yellow-700',
            'in_progress' => 'bg-orange-100 dark:bg-orange-900/50 border-orange-300 dark:border-orange-700',
            'completed' => 'bg-green-100 dark:bg-green-900/50 border-green-300 dark:border-green-700',
            'paid' => 'bg-emerald-100 dark:bg-emerald-900/50 border-emerald-300 dark:border-emerald-700',
            'signed_accepted' => 'bg-teal-100 dark:bg-teal-900/50 border-teal-300 dark:border-teal-700',
            'cancelled' => 'bg-red-100 dark:bg-red-900/50 border-red-300 dark:border-red-700',
        ];
    }

    /**
     * Get the stage header colors.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function stageHeaderColors(): array
    {
        return [
            'lead' => 'bg-blue-500',
            'quoted' => 'bg-purple-500',
            'scheduled' => 'bg-yellow-500',
            'in_progress' => 'bg-orange-500',
            'completed' => 'bg-green-500',
            'paid' => 'bg-emerald-500',
            'signed_accepted' => 'bg-teal-500',
            'cancelled' => 'bg-red-500',
        ];
    }

    /**
     * Move a deal to a new stage and position.
     * Called from the JavaScript kanban drag-and-drop handler.
     */
    public function moveToStage(int $dealId, string $newStage, int $newPosition): void
    {
        if (! array_key_exists($newStage, Deal::STAGES)) {
            return;
        }

        Deal::findOrFail($dealId);

        $dealsInStage = Deal::where('stage', $newStage)
            ->where('id', '!=', $dealId)
            ->orderBy('sort_order')
            ->pluck('id')
            ->toArray();

        array_splice($dealsInStage, $newPosition, 0, [$dealId]);

        foreach ($dealsInStage as $index => $id) {
            $updateData = ['sort_order' => $index];
            if ($id === $dealId) {
                $updateData['stage'] = $newStage;
            }
            Deal::where('id', $id)->update($updateData);
        }
    }

    /**
     * Get deals grouped by stage.
     *
     * @return array<string, Collection<int, Deal>>
     */
    #[Computed]
    public function dealsByStage(): array
    {
        $query = Deal::query()
            ->with(['customer', 'assignedTo'])
            ->search($this->search)
            ->when($this->assignedToFilter, function ($query) {
                return $query->where('assigned_to', $this->assignedToFilter);
            })
            ->when($this->customerFilter, function ($query) {
                return $query->where('customer_id', $this->customerFilter);
            })
            ->orderBy('sort_order')
            ->orderBy('created_at', 'desc');

        $deals = $query->get();

        $dealsByStage = [];
        foreach (array_keys(Deal::STAGES) as $stage) {
            $dealsByStage[$stage] = $deals->where('stage', $stage)->values();
        }

        return $dealsByStage;
    }

    /**
     * Get internal users for filter dropdown.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function internalUsers(): Collection
    {
        return User::query()
            ->where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Get customers for filter dropdown.
     *
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customers(): Collection
    {
        return Customer::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    #[Computed]
    public function stages(): array
    {
        return Deal::STAGES;
    }
}; ?>

<div>
    <div class="max-w-full mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">Deal Pipeline</flux:heading>
            @livewire('deals.create-modal')
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
                    placeholder="Search deals..."
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="assignedToFilter">
                    <option value="">All Team Members</option>
                    @foreach ($this->internalUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="customerFilter">
                    <option value="">All Customers</option>
                    @foreach ($this->customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->full_name }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        <div class="overflow-x-auto pb-4">
            <div class="flex gap-4 min-w-max" id="kanban-board">
                @foreach ($this->stages as $stageKey => $stageLabel)
                    <div class="w-72 flex-shrink-0">
                        <div class="rounded-lg border {{ $this->stageColors[$stageKey] }} overflow-hidden">
                            <div class="px-4 py-3 {{ $this->stageHeaderColors[$stageKey] }}">
                                <div class="flex items-center justify-between">
                                    <flux:heading size="sm" class="text-white font-semibold">
                                        {{ $stageLabel }}
                                    </flux:heading>
                                    <flux:badge size="sm" class="bg-white/20 text-white">
                                        {{ $this->dealsByStage[$stageKey]->count() }}
                                    </flux:badge>
                                </div>
                            </div>

                            <div
                                class="kanban-column p-2 min-h-[200px] space-y-2"
                                data-stage="{{ $stageKey }}"
                            >
                                @forelse ($this->dealsByStage[$stageKey] as $deal)
                                    <div
                                        data-deal-id="{{ $deal->id }}"
                                        wire:key="deal-{{ $deal->id }}"
                                        class="bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700 shadow-sm hover:shadow-md transition-shadow"
                                    >
                                        <div class="flex items-start gap-2 p-3">
                                            <div class="kanban-handle cursor-grab active:cursor-grabbing shrink-0 pt-0.5 text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-6 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-start justify-between mb-2">
                                                    <flux:text class="font-medium text-sm truncate flex-1">
                                                        {{ $deal->title }}
                                                    </flux:text>
                                                    <div class="kanban-no-drag ml-1">
                                                        <flux:dropdown>
                                                            <flux:button variant="ghost" size="xs" icon="ellipsis-vertical" />
                                                            <flux:menu>
                                                                <flux:menu.item icon="eye" href="{{ route('deals.show', $deal) }}" wire:navigate>
                                                                    View
                                                                </flux:menu.item>
                                                            </flux:menu>
                                                        </flux:dropdown>
                                                    </div>
                                                </div>

                                                @if ($deal->customer)
                                                    <div class="flex items-center text-xs text-zinc-500 dark:text-zinc-400 mb-2">
                                                        <flux:icon name="user" class="h-3 w-3 mr-1" />
                                                        {{ $deal->customer->full_name }}
                                                    </div>
                                                @endif

                                                <div class="flex items-center justify-between">
                                                    @if ($deal->estimated_value)
                                                        <flux:badge size="sm" color="green">
                                                            ${{ number_format($deal->estimated_value, 0) }}
                                                        </flux:badge>
                                                    @else
                                                        <span></span>
                                                    @endif

                                                    @if ($deal->assignedTo)
                                                        <flux:tooltip content="{{ $deal->assignedTo->name }}">
                                                            <flux:avatar size="xs" name="{{ $deal->assignedTo->name }}" />
                                                        </flux:tooltip>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="text-center py-8 text-zinc-400 dark:text-zinc-500 text-sm">
                                        No deals
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

@script
<script>
    let sortableInstances = [];

    function initSortable() {
        // Destroy existing instances
        sortableInstances.forEach(s => s.destroy());
        sortableInstances = [];

        const columns = $wire.$el.querySelectorAll('.kanban-column');
        columns.forEach(column => {
            const sortable = new Sortable(column, {
                group: 'kanban',
                handle: '.kanban-handle',
                filter: '.kanban-no-drag',
                preventOnFilter: false,
                animation: 200,
                ghostClass: 'opacity-30',
                dragClass: 'shadow-lg',
                scroll: true,
                scrollSensitivity: 80,
                onEnd: (evt) => {
                    const dealId = parseInt(evt.item.dataset.dealId);
                    const newStage = evt.to.dataset.stage;
                    const newPosition = evt.newIndex;

                    if (!dealId || !newStage) return;

                    $wire.moveToStage(dealId, newStage, newPosition);
                }
            });
            sortableInstances.push(sortable);
        });
    }

    // Initialize on first load
    initSortable();

    // Re-initialize after Livewire re-renders the component
    $wire.$hook('morph.updated', ({ el }) => {
        if (el === $wire.$el) {
            requestAnimationFrame(() => initSortable());
        }
    });
</script>
@endscript
