<?php

use App\Models\Customer;
use App\Models\CustomerDuplicateFlag;
use App\Models\Deal;
use App\Models\User;
use App\Services\DealStageService;
use Illuminate\Database\Eloquent\Collection;
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

    // Create estimate prompt
    public bool $showCreateEstimateModal = false;

    public ?int $createEstimateDealId = null;

    // Triage modal state
    public bool $showTriageModal = false;

    public ?int $triageDealId = null;

    public string $triageAction = '';

    public ?string $triageFollowUpDate = null;

    public function getListeners(): array
    {
        return [
            'deal-created' => 'refreshPipeline',
            'deal-updated' => 'refreshPipeline',
        ];
    }

    public function refreshPipeline(): void
    {
        // Re-render is triggered automatically
    }

    /**
     * Get the stage colors for display (kanban stages only).
     *
     * @return array<string, string>
     */
    #[Computed]
    public function stageColors(): array
    {
        return [
            'lead' => 'bg-blue-100 dark:bg-blue-900/50 border-blue-300 dark:border-blue-700',
            'quote_scheduled' => 'bg-purple-100 dark:bg-purple-900/50 border-purple-300 dark:border-purple-700',
            'quoted' => 'bg-yellow-100 dark:bg-yellow-900/50 border-yellow-300 dark:border-yellow-700',
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
            'quote_scheduled' => 'bg-purple-500',
            'quoted' => 'bg-yellow-500',
        ];
    }

    /**
     * Move a deal to a new stage and position.
     * Called from the JavaScript kanban drag-and-drop handler.
     */
    public function moveToStage(int $dealId, string $newStage, int $newPosition): void
    {
        if (! in_array($newStage, Deal::KANBAN_STAGES)) {
            return;
        }

        $deal = Deal::findOrFail($dealId);
        $oldStage = $deal->stage;

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

        if ($oldStage !== $newStage && class_exists(\App\Events\DealUpdated::class)) {
            $deal->refresh();
            broadcast(new \App\Events\DealUpdated($deal, auth()->user()->name, 'stage_changed'))->toOthers();
        }
    }

    /**
     * Open triage modal for a lead.
     */
    public function openTriage(int $dealId, string $action): void
    {
        $this->triageDealId = $dealId;
        $this->triageAction = $action;
        $this->triageFollowUpDate = now()->addDay()->toDateString();

        if ($action === 'follow_up' || $action === 'quote_scheduled') {
            $this->triageFollowUpDate = $action === 'quote_scheduled'
                ? now()->addDay()->setTime(9, 0)->format('Y-m-d\TH:i')
                : now()->addDay()->toDateString();
            $this->showTriageModal = true;
        } else {
            $this->executeTriage();
        }
    }

    /**
     * Execute the triage action.
     */
    public function executeTriage(): void
    {
        if (! $this->triageDealId) {
            return;
        }

        $deal = Deal::findOrFail($this->triageDealId);
        $service = app(DealStageService::class);

        match ($this->triageAction) {
            'not_contacted' => $service->transitionTo($deal, 'not_contacted'),
            'follow_up' => $service->transitionTo($deal, 'follow_up', $this->triageFollowUpDate),
            'quote_scheduled' => $service->transitionTo($deal, 'quote_scheduled', $this->triageFollowUpDate),
            'lost' => $service->transitionTo($deal, 'lost'),
            default => null,
        };

        if (class_exists(\App\Events\DealUpdated::class)) {
            $deal->refresh();
            broadcast(new \App\Events\DealUpdated($deal, auth()->user()->name, 'stage_changed'))->toOthers();
        }

        $this->closeTriageModal();
    }

    public function closeTriageModal(): void
    {
        $this->showTriageModal = false;
        $this->triageDealId = null;
        $this->triageAction = '';
        $this->triageFollowUpDate = null;
    }

    public function promptCreateEstimate(int $dealId): void
    {
        $this->createEstimateDealId = $dealId;
        $this->showCreateEstimateModal = true;
    }

    /**
     * Get deals grouped by kanban stage only.
     *
     * @return array<string, Collection<int, Deal>>
     */
    #[Computed]
    public function dealsByStage(): array
    {
        $query = Deal::query()
            ->kanban()
            ->with(['customer', 'agent', 'assignedTo', 'appointmentBooking', 'originVendor', 'estimates'])
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
        foreach (Deal::KANBAN_STAGES as $stage) {
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
            ->select('id', 'name')
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
            ->select('id', 'first_name', 'last_name', 'user_id')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    /**
     * Get kanban stage labels.
     */
    #[Computed]
    public function stages(): array
    {
        return array_intersect_key(Deal::STAGES, array_flip(Deal::KANBAN_STAGES));
    }

    /**
     * Check if a deal's customer has pending duplicate flags.
     */
    public function hasDuplicateFlag(int $dealId): bool
    {
        static $flaggedCustomerIds = null;

        if ($flaggedCustomerIds === null) {
            $flaggedCustomerIds = CustomerDuplicateFlag::query()
                ->where('status', 'pending')
                ->get(['customer_id', 'duplicate_customer_id'])
                ->flatMap(fn ($flag) => [$flag->customer_id, $flag->duplicate_customer_id])
                ->filter()
                ->unique()
                ->all();
        }

        $deal = collect($this->dealsByStage)->flatten(1)->firstWhere('id', $dealId);

        return $deal && in_array($deal->customer_id, $flaggedCustomerIds);
    }

}; ?>

<div>
    <div class="max-w-full mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Active Leads') }}</flux:heading>
            <div class="flex items-center gap-2">
                @can('estimates.view')
                    <flux:button icon="document-text" :href="route('estimates.index')" wire:navigate variant="ghost">
                        {{ __('Estimates') }}
                    </flux:button>
                @endcan
                @livewire('deals.create-modal')
            </div>
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
                    :placeholder="__('Search deals...')"
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="assignedToFilter">
                    <option value="">{{ __('All Team Members') }}</option>
                    @foreach ($this->internalUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="customerFilter">
                    <option value="">{{ __('All Customers') }}</option>
                    @foreach ($this->customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->full_name }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        <div class="overflow-x-auto pb-4">
            <div class="flex gap-4 min-w-max" id="kanban-board">
                @foreach ($this->stages as $stageKey => $stageLabel)
                    <div class="w-80 flex-shrink-0">
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
                                                                @if ($deal->customer_id)
                                                                    <flux:menu.item icon="user" href="{{ route('customers.show', ['customer' => $deal->customer_id, 'deal' => $deal->id]) }}" wire:navigate>
                                                                        {{ __('Customer Hub') }}
                                                                    </flux:menu.item>
                                                                @elseif ($deal->agent_id)
                                                                    <flux:menu.item icon="user" href="{{ route('agents.show', $deal->agent_id) }}" wire:navigate>
                                                                        {{ __('Agent Profile') }}
                                                                    </flux:menu.item>
                                                                @endif
                                                                @php $latestEstimate = $deal->estimates->sortByDesc('created_at')->first(); @endphp
                                                                @if ($latestEstimate)
                                                                    <flux:menu.item icon="document-text" href="{{ route('estimates.show', $latestEstimate) }}" wire:navigate>
                                                                        {{ __('View Estimate') }}
                                                                    </flux:menu.item>
                                                                @else
                                                                    <flux:menu.item icon="plus" wire:click="promptCreateEstimate({{ $deal->id }})">
                                                                        {{ __('Create Estimate') }}
                                                                    </flux:menu.item>
                                                                @endif
                                                            </flux:menu>
                                                        </flux:dropdown>
                                                    </div>
                                                </div>

                                                @if ($deal->customer)
                                                    <a href="{{ route('customers.show', ['customer' => $deal->customer_id, 'deal' => $deal->id]) }}" wire:navigate class="flex items-center text-xs text-zinc-500 dark:text-zinc-400 mb-2 hover:text-blue-600 dark:hover:text-blue-400 transition-colors">
                                                        <flux:icon name="user" class="h-3 w-3 mr-1" />
                                                        {{ $deal->customer->full_name }}
                                                    </a>
                                                @elseif ($deal->agent)
                                                    <a href="{{ route('agents.show', $deal->agent_id) }}" wire:navigate class="flex items-center text-xs text-zinc-500 dark:text-zinc-400 mb-2 hover:text-blue-600 dark:hover:text-blue-400 transition-colors">
                                                        <flux:icon name="building-office" class="h-3 w-3 mr-1" />
                                                        {{ $deal->agent->name }}
                                                    </a>
                                                @endif

                                                {{-- Lead source and appointment badges --}}
                                                <div class="flex flex-wrap items-center gap-1 mb-2">
                                                    @if ($deal->lead_source === 'vendor_booking')
                                                        <flux:badge size="sm" color="purple">
                                                            <flux:icon name="calendar" class="w-3 h-3 mr-0.5" />{{ __('Vendor Booking') }}
                                                        </flux:badge>
                                                    @elseif ($deal->lead_source === 'door_knock')
                                                        <flux:badge size="sm" color="amber">{{ __('Door Knock') }}</flux:badge>
                                                    @elseif ($deal->lead_source === 'website')
                                                        <flux:badge size="sm" color="blue">{{ __('Website') }}</flux:badge>
                                                    @elseif ($deal->lead_source)
                                                        <flux:badge size="sm" color="zinc">{{ __(Deal::LEAD_SOURCES[$deal->lead_source] ?? ucfirst($deal->lead_source)) }}</flux:badge>
                                                    @endif
                                                    @if ($deal->originVendor)
                                                        <flux:badge size="sm" color="indigo">{{ $deal->originVendor->name }}</flux:badge>
                                                    @endif
                                                    @if ($this->hasDuplicateFlag($deal->id))
                                                        <flux:badge size="sm" color="amber" title="{{ __('Possible duplicate customer') }}">{{ __('Duplicate?') }}</flux:badge>
                                                    @endif
                                                </div>

                                                @if ($deal->appointmentBooking)
                                                    <div class="flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400 mb-2 bg-emerald-50 dark:bg-emerald-900/20 rounded px-2 py-1">
                                                        <flux:icon name="calendar-days" class="w-3.5 h-3.5 shrink-0" />
                                                        <span class="font-medium">{{ $deal->appointmentBooking->booked_date->format('M j') }}</span>
                                                        <span>{{ $deal->appointmentBooking->booked_start_time }}</span>
                                                    </div>
                                                @endif

                                                <div class="flex items-center justify-between mb-2">
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

                                                {{-- Triage buttons for leads --}}
                                                @if ($stageKey === 'lead')
                                                    <div class="kanban-no-drag flex flex-wrap gap-1 pt-1 border-t border-zinc-100 dark:border-zinc-700">
                                                        <button wire:click="openTriage({{ $deal->id }}, 'not_contacted')" class="text-xs px-2 py-1 rounded bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-600 transition-colors">
                                                            {{ __('No Answer') }}
                                                        </button>
                                                        <button wire:click="openTriage({{ $deal->id }}, 'follow_up')" class="text-xs px-2 py-1 rounded bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 hover:bg-amber-200 dark:hover:bg-amber-900/50 transition-colors">
                                                            {{ __('Follow Up') }}
                                                        </button>
                                                        <button wire:click="openTriage({{ $deal->id }}, 'quote_scheduled')" class="text-xs px-2 py-1 rounded bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 hover:bg-purple-200 dark:hover:bg-purple-900/50 transition-colors">
                                                            {{ __('Schedule') }}
                                                        </button>
                                                        <button wire:click="openTriage({{ $deal->id }}, 'lost')" wire:confirm="{{ __('Mark this deal as lost?') }}" class="text-xs px-2 py-1 rounded bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors">
                                                            {{ __('Lost') }}
                                                        </button>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="text-center py-8 text-zinc-400 dark:text-zinc-500 text-sm">
                                        {{ __('No deals') }}
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Create Estimate Prompt Modal --}}
    <flux:modal wire:model="showCreateEstimateModal" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Create Estimate') }}</flux:heading>
            <flux:text>{{ __('No estimate exists for this lead yet. Would you like to create one?') }}</flux:text>
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showCreateEstimateModal', false)">{{ __('Cancel') }}</flux:button>
                @if ($createEstimateDealId)
                    <flux:button variant="primary" icon="plus" :href="route('estimates.create', $createEstimateDealId)" wire:navigate>
                        {{ __('Create Estimate') }}
                    </flux:button>
                @endif
            </div>
        </div>
    </flux:modal>

    {{-- Follow-Up / Quote Schedule Triage Modal --}}
    <flux:modal wire:model="showTriageModal" class="max-w-sm">
        <form wire:submit="executeTriage">
            <flux:heading size="lg" class="mb-4">
                {{ $triageAction === 'quote_scheduled' ? __('Schedule Quote Appointment') : __('Schedule Follow-Up') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ $triageAction === 'quote_scheduled' ? __('Appointment Date & Time') : __('Follow-up Date') }}</flux:label>
                <flux:input wire:model="triageFollowUpDate" :type="$triageAction === 'quote_scheduled' ? 'datetime-local' : 'date'" />
            </flux:field>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeTriageModal" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">
                    {{ $triageAction === 'quote_scheduled' ? __('Schedule Quote') : __('Set Follow-Up') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>

@script
<script>
    let sortableInstances = [];

    async function initSortable() {
        const Sortable = await window.loadSortable();

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
