<?php

use App\Events\NewLeadCreated;
use App\Models\Activity;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\EstimateActivity;
use App\Models\Job;
use App\Models\Property;
use App\Models\User;
use App\Services\DealStageService;
use App\Services\InvitationService;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    public function boot(): void
    {
        if (! auth()->user()?->can('people.view')) {
            abort(403);
        }
    }

    public Customer $customer;

    #[Url(as: 'deal')]
    public ?int $focusDealId = null;

    // Quick add property form
    public bool $showAddProperty = false;

    public string $propertyAddress = '';

    public string $propertyCity = '';

    public string $propertyState = '';

    public string $propertyZip = '';

    public string $propertyType = 'residential';

    // Quick add deal form
    public bool $showAddDeal = false;

    public string $dealTitle = '';

    public string $dealDescription = '';

    public string $dealLeadSource = '';

    public ?int $dealPropertyId = null;

    // Quick add activity form
    public bool $showAddActivity = false;

    public string $activityTitle = '';

    public string $activityType = 'task';

    public ?string $activityDueAt = null;

    public string $activityDescription = '';

    // Past deals visibility
    public bool $showPastDeals = false;

    // Follow-up modal
    public bool $showFollowUpModal = false;

    public ?string $followUpDate = null;

    // Quote schedule modal
    public bool $showQuoteScheduleModal = false;

    public ?string $quoteScheduleDate = null;

    public function mount(Customer $customer): void
    {
        $this->customer = $customer->load([
            'createdBy',
            'referredBy',
            'assignedTo',
            'properties',
            'deals.property',
            'deals.estimates',
            'deals.assignedTo',
            'deals.job',
            'activities.user',
        ]);

        // If no focus deal specified, default to most recent active deal
        if (! $this->focusDealId && $this->activeDeals->isNotEmpty()) {
            $this->focusDealId = $this->activeDeals->first()->id;
        }
    }

    #[Computed]
    public function activeDeal(): ?Deal
    {
        if ($this->focusDealId) {
            $deal = $this->customer->deals->firstWhere('id', $this->focusDealId);
            if ($deal) {
                return $deal;
            }
        }

        return $this->activeDeals->first();
    }

    #[Computed]
    public function activeDeals()
    {
        return $this->customer->deals
            ->filter(fn (Deal $deal) => ! $deal->isPastDeal())
            ->sortByDesc('created_at')
            ->values();
    }

    #[Computed]
    public function pastDeals()
    {
        return $this->customer->deals
            ->filter(fn (Deal $deal) => $deal->isPastDeal())
            ->sortByDesc('created_at')
            ->values();
    }

    #[Computed]
    public function activeDealEstimates()
    {
        if (! $this->activeDeal) {
            return collect();
        }

        return $this->activeDeal->estimates->sortByDesc('created_at');
    }

    /**
     * Get active jobs for this customer (through deals).
     */
    #[Computed]
    public function activeJobs()
    {
        return Job::whereHas('deal', fn ($q) => $q->where('customer_id', $this->customer->id))
            ->with(['deal', 'estimate'])
            ->whereNotIn('stage', ['completed'])
            ->latest()
            ->get();
    }

    /**
     * Get chat threads related to this customer (deal threads + direct threads with customer user).
     */
    #[Computed]
    public function customerThreads()
    {
        $user = auth()->user();
        $dealIds = $this->customer->deals->pluck('id')->toArray();

        $query = ChatThread::query()
            ->forUser($user)
            ->active()
            ->with(['latestMessage.user', 'deal', 'activeParticipants.user']);

        $query->where(function ($q) use ($dealIds) {
            if (! empty($dealIds)) {
                $q->whereIn('deal_id', $dealIds);
            }
            if ($this->customer->user_id) {
                $q->orWhere(function ($q2) {
                    $q2->where('type', ChatThread::TYPE_DIRECT)
                        ->whereHas('participants', function ($pq) {
                            $pq->where('user_id', $this->customer->user_id)
                                ->whereNull('left_at');
                        });
                });
            }
        });

        return $query->latestActivity()->get()
            ->map(function (ChatThread $thread) use ($user) {
                $thread->unread_count = $thread->getUnreadCountFor($user);

                return $thread;
            });
    }

    /**
     * Unified activity feed aggregating customer, deal, and estimate activities.
     */
    #[Computed]
    public function unifiedActivityFeed()
    {
        $items = collect();

        // 1. Activities linked to customer
        $customerActivities = Activity::where('subject_type', Customer::class)
            ->where('subject_id', $this->customer->id)
            ->with('user')
            ->get()
            ->map(fn ($a) => [
                'type' => $a->type,
                'icon' => match ($a->type) {
                    'task' => 'check-circle',
                    'call' => 'phone',
                    'email' => 'envelope',
                    'meeting' => 'calendar',
                    'note' => 'document-text',
                    default => 'chat-bubble-left',
                },
                'title' => $a->title,
                'subtitle' => $a->description,
                'user' => $a->user?->name,
                'date' => $a->created_at,
                'source' => 'Customer',
                'is_completed' => $a->is_completed,
                'activity_id' => $a->id,
                'activity_type_label' => $a->type_label,
            ]);
        $items = $items->merge($customerActivities);

        // 2. Activities linked to customer's deals
        $dealIds = $this->customer->deals->pluck('id');
        if ($dealIds->isNotEmpty()) {
            $dealActivities = Activity::where('subject_type', Deal::class)
                ->whereIn('subject_id', $dealIds)
                ->with(['user', 'subject'])
                ->get()
                ->map(fn ($a) => [
                    'type' => $a->type,
                    'icon' => match ($a->type) {
                        'task' => 'check-circle',
                        'call' => 'phone',
                        'email' => 'envelope',
                        'meeting' => 'calendar',
                        'note' => 'document-text',
                        default => 'chat-bubble-left',
                    },
                    'title' => $a->title,
                    'subtitle' => $a->subject?->title,
                    'user' => $a->user?->name,
                    'date' => $a->created_at,
                    'source' => 'Deal',
                    'is_completed' => $a->is_completed,
                    'activity_id' => $a->id,
                    'activity_type_label' => $a->type_label,
                ]);
            $items = $items->merge($dealActivities);
        }

        // 3. Estimate activities (viewed, sent, accepted, rejected)
        $estimateIds = $this->customer->estimates()->pluck('estimates.id');
        if ($estimateIds->isNotEmpty() && class_exists(EstimateActivity::class)) {
            $estimateActivities = EstimateActivity::whereIn('estimate_id', $estimateIds)
                ->with('user')
                ->get()
                ->map(fn ($ea) => [
                    'type' => 'estimate_event',
                    'icon' => match ($ea->action ?? $ea->type ?? '') {
                        'sent' => 'paper-airplane',
                        'accepted' => 'check',
                        'rejected' => 'x-mark',
                        'viewed' => 'eye',
                        default => 'document-text',
                    },
                    'title' => 'Estimate '.($ea->action ?? $ea->type ?? 'activity'),
                    'subtitle' => $ea->description ?? null,
                    'user' => $ea->user?->name,
                    'date' => $ea->created_at,
                    'source' => 'Estimate',
                    'is_completed' => null,
                    'activity_id' => null,
                    'activity_type_label' => ucfirst($ea->action ?? $ea->type ?? 'event'),
                ]);
            $items = $items->merge($estimateActivities);
        }

        // 4. Recent chat messages related to this customer
        $customerThreadIds = $this->customerThreads->pluck('id');
        if ($customerThreadIds->isNotEmpty()) {
            $recentMessages = ChatMessage::whereIn('thread_id', $customerThreadIds)
                ->where('type', '!=', 'system')
                ->with(['user', 'thread.deal'])
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn ($msg) => [
                    'type' => 'message',
                    'icon' => 'chat-bubble-left-right',
                    'title' => ($msg->user?->name ?? __('Unknown')).': '.Str::limit($msg->content, 60),
                    'subtitle' => $msg->thread?->deal ? $msg->thread->deal->title : __('Direct Message'),
                    'user' => $msg->user?->name,
                    'date' => $msg->created_at,
                    'source' => 'Chat',
                    'is_completed' => null,
                    'activity_id' => null,
                    'activity_type_label' => 'Message',
                ]);
            $items = $items->merge($recentMessages);
        }

        return $items->sortByDesc('date')->take(30)->values();
    }

    #[Computed]
    public function activityTypes(): array
    {
        return [
            'task' => __('Task'),
            'note' => __('Note'),
            'call' => __('Phone Call'),
            'email' => __('Email'),
            'meeting' => __('Meeting'),
            'other' => __('Other'),
        ];
    }

    #[Computed]
    public function leadSources(): array
    {
        return Deal::LEAD_SOURCES;
    }

    #[Computed]
    public function sourceLabel(): string
    {
        return Customer::SOURCES[$this->customer->source] ?? $this->customer->source ?? '';
    }

    #[Computed]
    public function estimatePreferenceLabel(): string
    {
        return match ($this->customer->estimate_preference) {
            'virtual' => __('Virtual'),
            'in_person' => __('In-Person'),
            default => '',
        };
    }

    #[Computed]
    public function internalUsers()
    {
        return User::where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function updateAssignment(?int $userId): void
    {
        abort_unless(auth()->user()->can('people.edit'), 403);

        $this->customer->update([
            'assigned_to' => $userId ?: null,
        ]);
        $this->refreshCustomer();
    }

    #[Computed]
    public function stageColors(): array
    {
        return [
            'lead' => 'blue',
            'not_contacted' => 'zinc',
            'follow_up' => 'amber',
            'quote_scheduled' => 'purple',
            'quoted' => 'yellow',
            'signed' => 'green',
            'completed' => 'emerald',
            'lost' => 'orange',
            'cancelled' => 'red',
        ];
    }

    public function switchDeal(int $dealId): void
    {
        $this->focusDealId = $dealId;
        unset($this->activeDeal, $this->activeDealEstimates);
    }

    public function changeActiveDealStage(string $stage): void
    {
        abort_unless(auth()->user()->can('deals.edit'), 403);

        if (! $this->activeDeal || ! array_key_exists($stage, Deal::STAGES)) {
            return;
        }

        // Use DealStageService for planner stages (auto-creates follow-up activities)
        if (in_array($stage, Deal::PLANNER_STAGES)) {
            if ($stage === 'follow_up') {
                $this->followUpDate = now()->addDay()->toDateString();
                $this->showFollowUpModal = true;

                return;
            }
            app(DealStageService::class)->transitionTo($this->activeDeal, $stage);
        } elseif ($stage === 'quote_scheduled') {
            $this->quoteScheduleDate = now()->addDay()->setTime(9, 0)->format('Y-m-d\TH:i');
            $this->showQuoteScheduleModal = true;

            return;
        } else {
            $this->activeDeal->update(['stage' => $stage]);
        }

        $this->refreshCustomer();
    }

    public function confirmFollowUp(): void
    {
        abort_unless(auth()->user()->can('deals.edit'), 403);

        if (! $this->activeDeal) {
            return;
        }

        app(DealStageService::class)->transitionTo($this->activeDeal, 'follow_up', $this->followUpDate);
        $this->showFollowUpModal = false;
        $this->followUpDate = null;
        $this->refreshCustomer();
    }

    public function confirmQuoteSchedule(): void
    {
        abort_unless(auth()->user()->can('deals.edit'), 403);

        if (! $this->activeDeal) {
            return;
        }

        app(DealStageService::class)->transitionTo($this->activeDeal, 'quote_scheduled', $this->quoteScheduleDate);
        $this->showQuoteScheduleModal = false;
        $this->quoteScheduleDate = null;
        $this->refreshCustomer();
    }

    #[On('customer-updated')]
    #[On('property-created')]
    #[On('deal-created')]
    #[On('activity-created')]
    public function refreshCustomer(): void
    {
        $this->customer = $this->customer->fresh([
            'createdBy',
            'referredBy',
            'assignedTo',
            'properties',
            'deals.property',
            'deals.estimates',
            'deals.assignedTo',
            'deals.job',
            'activities.user',
        ]);

        // Reset computed caches
        unset($this->activeDeal, $this->activeDeals, $this->pastDeals, $this->activeDealEstimates, $this->unifiedActivityFeed, $this->activeJobs, $this->customerThreads);
    }

    public function toggleStatus(): void
    {
        abort_unless(auth()->user()->can('people.edit'), 403);

        $this->customer->update([
            'status' => $this->customer->status === 'active' ? 'inactive' : 'active',
        ]);
        $this->refreshCustomer();
        session()->flash('success', __('Customer status updated.'));
    }

    public function sendPortalInvite(): void
    {
        abort_unless(auth()->user()->can('people.edit'), 403);

        try {
            $invitationService = app(InvitationService::class);
            $invitationService->sendInvitation(
                inviter: auth()->user(),
                email: $this->customer->email,
                invitedUserType: 'customer'
            );
            session()->flash('success', __('Portal invitation sent to :email', ['email' => $this->customer->email]));
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // Quick Add Property
    public function openAddProperty(): void
    {
        $this->showAddProperty = true;
    }

    public function closeAddProperty(): void
    {
        $this->showAddProperty = false;
        $this->reset(['propertyAddress', 'propertyCity', 'propertyState', 'propertyZip', 'propertyType']);
        $this->resetValidation();
    }

    public function saveProperty(): void
    {
        abort_unless(auth()->user()->can('people.create'), 403);

        $this->validate([
            'propertyAddress' => ['required', 'string', 'max:255'],
            'propertyCity' => ['required', 'string', 'max:100'],
            'propertyState' => ['nullable', 'string', 'max:50'],
            'propertyZip' => ['nullable', 'string', 'max:20'],
            'propertyType' => ['required', 'in:residential,commercial,industrial'],
        ]);

        Property::create([
            'customer_id' => $this->customer->id,
            'address' => $this->propertyAddress,
            'city' => $this->propertyCity,
            'state' => $this->propertyState ?: '',
            'zip' => $this->propertyZip ?: '',
            'type' => $this->propertyType,
        ]);

        $this->dispatch('property-created');
        session()->flash('success', __('Property added successfully.'));
        $this->closeAddProperty();
    }

    // Quick Add Deal
    public function openAddDeal(): void
    {
        $this->dealTitle = $this->customer->full_name.' - New Project';
        $this->showAddDeal = true;
    }

    public function closeAddDeal(): void
    {
        $this->showAddDeal = false;
        $this->reset(['dealTitle', 'dealDescription', 'dealLeadSource', 'dealPropertyId']);
        $this->resetValidation();
    }

    public function saveDeal(): void
    {
        abort_unless(auth()->user()->can('people.create'), 403);

        $this->validate([
            'dealTitle' => ['required', 'string', 'max:255'],
            'dealDescription' => ['nullable', 'string', 'max:5000'],
            'dealLeadSource' => ['nullable', 'string', 'in:'.implode(',', array_keys(Deal::LEAD_SOURCES))],
            'dealPropertyId' => ['nullable', 'exists:properties,id'],
        ]);

        $deal = Deal::create([
            'customer_id' => $this->customer->id,
            'property_id' => $this->dealPropertyId,
            'created_by' => auth()->id(),
            'assigned_to' => $this->customer->assigned_to ?? auth()->id(),
            'title' => $this->dealTitle,
            'description' => $this->dealDescription ?: null,
            'lead_source' => $this->dealLeadSource ?: null,
            'stage' => 'lead',
            'sort_order' => Deal::where('stage', 'lead')->max('sort_order') + 1,
        ]);

        NewLeadCreated::dispatch($deal);

        $this->dispatch('deal-created');
        $this->focusDealId = $deal->id;
        session()->flash('success', __('Deal created successfully.'));
        $this->closeAddDeal();
    }

    // Quick Add Activity
    public function openAddActivity(): void
    {
        $this->showAddActivity = true;
    }

    public function closeAddActivity(): void
    {
        $this->showAddActivity = false;
        $this->reset(['activityTitle', 'activityType', 'activityDueAt', 'activityDescription']);
        $this->activityType = 'task';
        $this->resetValidation();
    }

    public function saveActivity(): void
    {
        abort_unless(auth()->user()->can('people.edit'), 403);

        $this->validate([
            'activityTitle' => ['required', 'string', 'max:255'],
            'activityType' => ['required', 'in:task,note,call,email,meeting,other'],
            'activityDueAt' => ['nullable', 'date'],
            'activityDescription' => ['nullable', 'string', 'max:5000'],
        ]);

        Activity::create([
            'user_id' => auth()->id(),
            'assigned_to' => auth()->id(),
            'subject_type' => Customer::class,
            'subject_id' => $this->customer->id,
            'type' => $this->activityType,
            'title' => $this->activityTitle,
            'description' => $this->activityDescription ?: null,
            'due_at' => $this->activityDueAt ?: null,
            'is_completed' => false,
        ]);

        $this->dispatch('activity-created');
        session()->flash('success', __('Activity added successfully.'));
        $this->closeAddActivity();
    }

    public function toggleActivityComplete(int $activityId): void
    {
        $activity = Activity::findOrFail($activityId);

        if ($activity->is_completed) {
            $activity->markAsIncomplete();
        } else {
            $activity->markAsCompleted();
        }

        $this->refreshCustomer();
    }
}; ?>

<div>
    <div class="max-w-6xl mx-auto">
        {{-- Header --}}
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('customers.index')" icon="arrow-left" wire:navigate>
                    {{ __('Back') }}
                </flux:button>
                <div>
                    <div class="flex items-center gap-3">
                        <flux:heading size="xl">{{ $customer->full_name }}</flux:heading>
                        @if ($customer->status === 'active')
                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="yellow">{{ __('Inactive') }}</flux:badge>
                        @endif
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                @can('people.edit')
                    @if (!$customer->hasPortalAccess())
                        <flux:button size="sm" icon="envelope" wire:click="sendPortalInvite" wire:confirm="{{ __('Send portal invitation to') }} {{ $customer->email }}?">
                            {{ __('Send Invite') }}
                        </flux:button>
                    @endif
                    @livewire('customers.edit-modal', ['customerId' => $customer->id])
                @endcan
            </div>
        </div>

        {{-- Customer Meta Bar --}}
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400 mb-4 pl-14">
            @if ($this->activeDeal?->lead_source)
                <span>{{ __('Lead Source:') }} <strong class="text-zinc-700 dark:text-zinc-300">{{ Deal::LEAD_SOURCES[$this->activeDeal->lead_source] ?? $this->activeDeal->lead_source }}</strong></span>
            @elseif ($this->sourceLabel)
                <span>{{ __('Source:') }} <strong class="text-zinc-700 dark:text-zinc-300">{{ $this->sourceLabel }}</strong></span>
            @endif
            @if ($this->estimatePreferenceLabel)
                <flux:badge size="sm" color="purple">{{ $this->estimatePreferenceLabel }} {{ __('Estimate') }}</flux:badge>
            @endif
            <span>{{ __('Customer since') }} <strong class="text-zinc-700 dark:text-zinc-300">{{ $customer->created_at->format('M j, Y') }}</strong></span>
            @if ($customer->hasPortalAccess())
                <flux:badge size="sm" color="green">{{ __('Portal Enabled') }}</flux:badge>
            @endif
        </div>

        {{-- Contact & Quick Actions Bar --}}
        <div class="flex flex-wrap items-center justify-between gap-3 mb-6 pl-14">
            {{-- Contact Buttons --}}
            <div class="flex items-center gap-2">
                @if ($customer->phone)
                    <flux:button size="sm" icon="phone" :href="'tel:' . $customer->phone">
                        {{ $customer->phone }}
                    </flux:button>
                @endif
                @if ($customer->email)
                    <flux:button size="sm" icon="envelope" :href="'mailto:' . $customer->email" variant="ghost">
                        {{ $customer->email }}
                    </flux:button>
                @endif
                @if ($this->customerThreads->isNotEmpty())
                    @php $totalUnread = $this->customerThreads->sum('unread_count'); @endphp
                    <flux:button size="sm" icon="chat-bubble-left-right" :href="route('chat.show', $this->customerThreads->first())" variant="ghost" wire:navigate>
                        {{ __('Messages') }}
                        @if ($totalUnread > 0)
                            <flux:badge size="sm" color="blue" class="ml-1">{{ $totalUnread }}</flux:badge>
                        @endif
                    </flux:button>
                @endif
            </div>

            {{-- Quick Actions --}}
            <div class="flex items-center gap-2">
                @can('people.create')
                    <flux:button size="sm" variant="ghost" icon="plus" wire:click="openAddDeal">
                        {{ __('New Deal') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" icon="home" wire:click="openAddProperty">
                        {{ __('Add Property') }}
                    </flux:button>
                @endcan
                @can('people.edit')
                    <flux:button size="sm" variant="ghost" icon="check-circle" wire:click="openAddActivity">
                        {{ __('Add Activity') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="toggleStatus" wire:confirm="{{ __('Change customer status to') }} {{ $customer->status === 'active' ? __('inactive') : __('active') }}?">
                        {{ $customer->status === 'active' ? __('Deactivate') : __('Reactivate') }}
                    </flux:button>
                @endcan
            </div>
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

        {{-- Active Deal Section --}}
        @if ($this->activeDeals->count() > 0)
            {{-- Active Deals Header with Dropdown Switcher --}}
            <div class="flex items-center gap-3 mb-3">
                <flux:heading size="lg">{{ __('Active Deals') }}</flux:heading>
                <flux:badge size="sm" color="blue">{{ $this->activeDeals->count() }}</flux:badge>
                @if ($this->activeDeals->count() > 1)
                    <flux:dropdown>
                        <flux:button variant="ghost" size="sm" icon-trailing="chevron-down">
                            {{ $this->activeDeal?->title ?? __('Select Deal') }}
                        </flux:button>
                        <flux:menu>
                            @foreach ($this->activeDeals as $deal)
                                <flux:menu.item
                                    wire:click="switchDeal({{ $deal->id }})"
                                    wire:key="deal-switch-{{ $deal->id }}"
                                >
                                    <div class="flex items-center gap-2">
                                        <span>{{ $deal->title }}</span>
                                        <flux:badge size="sm" :color="$this->stageColors[$deal->stage] ?? 'zinc'">
                                            {{ $deal->stage_label }}
                                        </flux:badge>
                                    </div>
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>

            {{-- Active Deal Banner --}}
            @if ($this->activeDeal)
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-5 mb-6 bg-white dark:bg-zinc-900">
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-3 mb-1">
                                <flux:heading size="lg">{{ $this->activeDeal->title }}</flux:heading>
                                @can('deals.edit')
                                    <flux:select
                                        wire:change="changeActiveDealStage($event.target.value)"
                                        class="!w-auto !text-sm"
                                    >
                                        @foreach (Deal::STAGES as $stageKey => $stageLabel)
                                            <option value="{{ $stageKey }}" @selected($this->activeDeal->stage === $stageKey)>{{ $stageLabel }}</option>
                                        @endforeach
                                    </flux:select>
                                @else
                                    <flux:badge :color="$this->stageColors[$this->activeDeal->stage] ?? 'zinc'">
                                        {{ $this->activeDeal->stage_label }}
                                    </flux:badge>
                                @endcan
                                @can('people.edit')
                                    <flux:select
                                        wire:change="updateAssignment($event.target.value)"
                                        class="!w-auto !text-sm"
                                    >
                                        <option value="">{{ __('Unassigned') }}</option>
                                        @foreach ($this->internalUsers as $member)
                                            <option value="{{ $member->id }}" @selected($customer->assigned_to === $member->id)>{{ $member->name }}</option>
                                        @endforeach
                                    </flux:select>
                                @else
                                    @if ($customer->assignedTo)
                                        <flux:text class="text-sm text-zinc-500">{{ $customer->assignedTo->name }}</flux:text>
                                    @endif
                                @endcan
                            </div>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
                                @if ($this->activeDeal->lead_source)
                                    <span>{{ __('Source:') }} {{ Deal::LEAD_SOURCES[$this->activeDeal->lead_source] ?? $this->activeDeal->lead_source }}</span>
                                @endif
                                @if ($this->activeDeal->assignedTo)
                                    <span>{{ __('Assigned:') }} {{ $this->activeDeal->assignedTo->name }}</span>
                                @endif
                                @if ($this->activeDeal->property)
                                    <span>{{ $this->activeDeal->property->address }}, {{ $this->activeDeal->property->city }}</span>
                                @endif
                                @if ($this->activeDeal->estimated_value)
                                    <span class="font-medium text-zinc-700 dark:text-zinc-300">${{ number_format($this->activeDeal->estimated_value, 0) }}</span>
                                @endif
                            </div>
                            @if ($this->activeDeal->description)
                                <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-2 italic">"{{ Str::limit($this->activeDeal->description, 200) }}"</p>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @can('estimates.create')
                            <flux:button :href="route('estimates.create', $this->activeDeal)" size="sm" icon="document-text" wire:navigate>
                                {{ __('Create Estimate') }}
                            </flux:button>
                        @endcan
                        @can('people.edit')
                            <flux:button size="sm" icon="check-circle" wire:click="openAddActivity">
                                {{ __('Log Activity') }}
                            </flux:button>
                        @endcan
                        @can('deals.edit')
                            @if ($this->activeDeal->stage !== 'lost')
                                <flux:button size="sm" variant="ghost" wire:click="changeActiveDealStage('lost')" wire:confirm="{{ __('Mark this deal as lost?') }}">
                                    {{ __('Mark Lost') }}
                                </flux:button>
                            @endif
                        @endcan
                        <flux:button size="sm" variant="ghost" :href="route('deals.show', $this->activeDeal)" icon="arrow-top-right-on-square" wire:navigate>
                            {{ __('Deal Details') }}
                        </flux:button>
                    </div>
                </div>
            @endif

            {{-- Active Deal Detail Section --}}
            @if ($this->activeDeal)
                {{-- Deal Info Grid --}}
                <div class="grid gap-4 md:grid-cols-3 mb-4">
                    @if ($this->activeDeal->description)
                        <div class="md:col-span-3 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3">
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Description') }}</dt>
                            <dd class="text-sm text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap">{{ $this->activeDeal->description }}</dd>
                        </div>
                    @endif
                    @if ($this->activeDeal->expected_start_date || $this->activeDeal->expected_end_date)
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3">
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Timeline') }}</dt>
                            <dd class="text-sm text-zinc-700 dark:text-zinc-300">
                                {{ $this->activeDeal->expected_start_date?->format('M j') ?? '?' }} - {{ $this->activeDeal->expected_end_date?->format('M j, Y') ?? __('TBD') }}
                            </dd>
                        </div>
                    @endif
                    @if ($this->activeDeal->job)
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3">
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Linked Job') }}</dt>
                            <dd class="text-sm">
                                <a href="{{ route('jobs.show', $this->activeDeal->job) }}" wire:navigate class="text-blue-600 hover:underline">
                                    {{ $this->activeDeal->job->job_number ?? __('View Job') }}
                                </a>
                                @php
                                    $jobStageColors = [
                                        'estimate_accepted' => 'blue',
                                        'scheduling_colors' => 'purple',
                                        'job_prep' => 'amber',
                                        'in_progress' => 'emerald',
                                        'completed' => 'green',
                                    ];
                                @endphp
                                <flux:badge size="sm" :color="$jobStageColors[$this->activeDeal->job->stage] ?? 'zinc'" class="ml-1">
                                    {{ str_replace('_', ' ', ucwords($this->activeDeal->job->stage, '_')) }}
                                </flux:badge>
                            </dd>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Two-Column: Estimates + Activity --}}
            <div class="grid gap-6 lg:grid-cols-2 mb-6">
                {{-- Estimates for Active Deal --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">{{ __('Estimates') }}</flux:heading>
                        @can('estimates.create')
                            @if ($this->activeDeal)
                                <flux:button size="xs" icon="plus" :href="route('estimates.create', $this->activeDeal)" wire:navigate>{{ __('New') }}</flux:button>
                            @endif
                        @endcan
                    </div>
                    @if ($this->activeDealEstimates->count() > 0)
                        <div class="space-y-2">
                            @foreach ($this->activeDealEstimates as $estimate)
                                <a href="{{ route('estimates.show', $estimate) }}" wire:navigate wire:key="est-{{ $estimate->id }}" class="block p-3 -mx-1 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <flux:text class="font-medium">{{ $estimate->estimate_number }}</flux:text>
                                            @if ($estimate->title)
                                                <flux:text size="sm" class="text-zinc-500">{{ $estimate->title }}</flux:text>
                                            @endif
                                        </div>
                                        <div class="text-right">
                                            @php
                                                $statusColors = [
                                                    'draft' => 'zinc',
                                                    'sent' => 'blue',
                                                    'accepted' => 'green',
                                                    'rejected' => 'red',
                                                ];
                                            @endphp
                                            <flux:badge size="sm" :color="$statusColors[$estimate->status] ?? 'zinc'">
                                                {{ ucfirst($estimate->status) }}
                                            </flux:badge>
                                            <flux:text size="sm" class="text-zinc-500 mt-0.5">
                                                ${{ number_format($estimate->total, 0) }}
                                            </flux:text>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-6">
                            <flux:icon name="document-text" class="w-8 h-8 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                            <flux:text size="sm" class="text-zinc-500">{{ __('No estimates for this deal yet') }}</flux:text>
                            @can('estimates.create')
                                @if ($this->activeDeal)
                                    <flux:button :href="route('estimates.create', $this->activeDeal)" size="sm" class="mt-2" wire:navigate>
                                        {{ __('Create Estimate') }}
                                    </flux:button>
                                @endif
                            @endcan
                        </div>
                    @endif
                </div>

                {{-- Unified Activity Feed --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">{{ __('Activity Feed') }}</flux:heading>
                        <flux:button size="xs" icon="plus" wire:click="openAddActivity">{{ __('Add') }}</flux:button>
                    </div>
                    @if ($this->unifiedActivityFeed->count() > 0)
                        <div class="space-y-3 max-h-96 overflow-y-auto">
                            @foreach ($this->unifiedActivityFeed as $index => $item)
                                <div wire:key="feed-{{ $index }}" class="flex gap-3 {{ ($item['is_completed'] ?? false) ? 'opacity-60' : '' }}">
                                    <div class="shrink-0 mt-0.5">
                                        @if ($item['type'] === 'task' && $item['activity_id'])
                                            <button
                                                wire:click="toggleActivityComplete({{ $item['activity_id'] }})"
                                                class="w-5 h-5 rounded border-2 flex items-center justify-center {{ ($item['is_completed'] ?? false) ? 'bg-green-500 border-green-500 text-white' : 'border-zinc-300 dark:border-zinc-600 hover:border-green-500' }}"
                                            >
                                                @if ($item['is_completed'] ?? false)
                                                    <flux:icon name="check" class="w-3 h-3" />
                                                @endif
                                            </button>
                                        @else
                                            <div class="w-5 h-5 rounded-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center">
                                                <flux:icon :name="$item['icon']" class="w-3 h-3 text-zinc-500" />
                                            </div>
                                        @endif
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <flux:text class="{{ ($item['is_completed'] ?? false) ? 'line-through' : '' }} text-sm">
                                            {{ $item['title'] }}
                                        </flux:text>
                                        <div class="flex flex-wrap items-center gap-2 mt-0.5">
                                            @php
                                                $sourceColors = [
                                                    'Customer' => 'blue',
                                                    'Deal' => 'purple',
                                                    'Estimate' => 'amber',
                                                    'Job' => 'emerald',
                                                    'Chat' => 'sky',
                                                ];
                                            @endphp
                                            <flux:badge size="sm" :color="$sourceColors[$item['source']] ?? 'zinc'">
                                                {{ __($item['source']) }}
                                            </flux:badge>
                                            <flux:text size="xs" class="text-zinc-500">
                                                {{ __($item['activity_type_label'] ?? '') }}
                                            </flux:text>
                                            @if ($item['date'])
                                                <flux:text size="xs" class="text-zinc-400">
                                                    {{ $item['date']->diffForHumans() }}
                                                </flux:text>
                                            @endif
                                            @if ($item['user'])
                                                <flux:text size="xs" class="text-zinc-400">
                                                    {{ $item['user'] }}
                                                </flux:text>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <flux:text size="sm" class="text-zinc-500">{{ __('No activity yet.') }}</flux:text>
                    @endif
                </div>
            </div>

            {{-- Customer Messages --}}
            @if ($this->customerThreads->isNotEmpty())
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg mb-6">
                    <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <flux:icon name="chat-bubble-left-right" class="w-4 h-4 text-zinc-500" />
                            <flux:heading size="sm">{{ __('Messages') }}</flux:heading>
                        </div>
                        @php $totalUnread = $this->customerThreads->sum('unread_count'); @endphp
                        @if ($totalUnread > 0)
                            <flux:badge size="sm" color="blue">{{ $totalUnread }} {{ __('unread') }}</flux:badge>
                        @endif
                    </div>
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->customerThreads as $thread)
                            <a href="{{ route('chat.show', $thread) }}" wire:navigate wire:key="thread-{{ $thread->id }}" class="flex items-start gap-3 p-3 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <flux:text class="font-medium text-sm {{ $thread->unread_count > 0 ? 'text-zinc-900 dark:text-white' : 'text-zinc-600 dark:text-zinc-400' }}">
                                            {{ $thread->getDisplayNameFor(auth()->user()) }}
                                        </flux:text>
                                        @if ($thread->unread_count > 0)
                                            <span class="inline-flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-blue-600 rounded-full">
                                                {{ $thread->unread_count }}
                                            </span>
                                        @endif
                                    </div>
                                    @if ($thread->latestMessage)
                                        <flux:text class="text-xs text-zinc-500 truncate mt-0.5">
                                            {{ $thread->latestMessage->user?->name }}: {{ Str::limit($thread->latestMessage->content, 60) }}
                                        </flux:text>
                                    @endif
                                </div>
                                <div class="shrink-0">
                                    <flux:text class="text-xs text-zinc-400">
                                        {{ ($thread->last_message_at ?? $thread->created_at)->diffForHumans() }}
                                    </flux:text>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Active Jobs Section --}}
            @if ($this->activeJobs->count() > 0)
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 mb-6">
                    <flux:heading size="sm" class="mb-3">{{ __('Active Jobs') }}</flux:heading>
                    <div class="space-y-2">
                        @foreach ($this->activeJobs as $job)
                            <a href="{{ route('jobs.show', $job) }}" wire:navigate wire:key="job-{{ $job->id }}" class="block p-3 -mx-1 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <flux:text class="font-medium text-sm">{{ $job->job_number ?? __('Job') }} - {{ $job->deal?->title }}</flux:text>
                                        @if ($job->scheduled_date)
                                            <flux:text size="xs" class="text-zinc-500">{{ __('Scheduled:') }} {{ $job->scheduled_date->format('M j, Y') }}</flux:text>
                                        @endif
                                    </div>
                                    <div>
                                        @php
                                            $jobStageColors = [
                                                'estimate_accepted' => 'blue',
                                                'scheduling_colors' => 'purple',
                                                'job_prep' => 'amber',
                                                'in_progress' => 'emerald',
                                                'completed' => 'green',
                                            ];
                                        @endphp
                                        <flux:badge size="sm" :color="$jobStageColors[$job->stage] ?? 'zinc'">
                                            {{ str_replace('_', ' ', ucwords($job->stage, '_')) }}
                                        </flux:badge>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        @else
            {{-- No Active Deals CTA --}}
            <div class="border-2 border-dashed border-zinc-300 dark:border-zinc-600 rounded-lg p-8 mb-6 text-center">
                <flux:icon name="briefcase" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                <flux:heading size="sm" class="mb-1">{{ __('No active leads for this customer') }}</flux:heading>
                <flux:text size="sm" class="text-zinc-500 mb-4">{{ __('Create a new lead to start the quote process.') }}</flux:text>
                @can('people.create')
                    <flux:button icon="plus" wire:click="openAddDeal">
                        {{ __('Create New Lead') }}
                    </flux:button>
                @endcan
            </div>
        @endif

        {{-- Contact & Property (compact two-column) --}}
        <div class="grid gap-6 lg:grid-cols-2 mb-6">
            {{-- Contact Info --}}
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:heading size="sm" class="mb-3">{{ __('Contact Info') }}</flux:heading>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <div>
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Email') }}</dt>
                        <dd class="text-zinc-900 dark:text-zinc-100">{{ $customer->email }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Phone') }}</dt>
                        <dd class="text-zinc-900 dark:text-zinc-100">{{ $customer->phone ?: '-' }}</dd>
                    </div>
                    @if ($customer->full_address)
                        <div class="col-span-2">
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Address') }}</dt>
                            <dd class="text-zinc-900 dark:text-zinc-100">{{ $customer->full_address }}</dd>
                        </div>
                    @endif
                    @if ($customer->referredBy)
                        <div>
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Referred By') }}</dt>
                            <dd class="text-zinc-900 dark:text-zinc-100">{{ $customer->referredBy->name }}</dd>
                        </div>
                    @endif
                    @if ($customer->createdBy)
                        <div>
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Created By') }}</dt>
                            <dd class="text-zinc-900 dark:text-zinc-100">{{ $customer->createdBy->name }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            {{-- Properties --}}
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <div class="flex items-center justify-between mb-3">
                    <flux:heading size="sm">{{ __('Properties') }} ({{ $customer->properties->count() }})</flux:heading>
                    @can('people.create')
                        <flux:button size="xs" icon="plus" wire:click="openAddProperty">{{ __('Add') }}</flux:button>
                    @endcan
                </div>
                @if ($customer->properties->count() > 0)
                    <div class="space-y-2">
                        @foreach ($customer->properties as $property)
                            <a href="{{ route('properties.show', $property) }}" wire:navigate wire:key="prop-{{ $property->id }}" class="block p-2 -mx-1 rounded hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <flux:text class="font-medium text-sm">{{ $property->address }}</flux:text>
                                        <flux:text size="xs" class="text-zinc-500">{{ $property->city }}, {{ $property->state }}</flux:text>
                                    </div>
                                    <flux:badge size="sm" color="zinc">{{ ucfirst($property->type) }}</flux:badge>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @else
                    <flux:text size="sm" class="text-zinc-500">{{ __('No properties yet.') }}</flux:text>
                @endif
            </div>
        </div>

        {{-- Past Deals (collapsible) --}}
        @if ($this->pastDeals->count() > 0)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg mb-6">
                <button
                    wire:click="$toggle('showPastDeals')"
                    class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors rounded-lg"
                >
                    <flux:heading size="sm">{{ __('Past Deals') }} ({{ $this->pastDeals->count() }})</flux:heading>
                    <flux:icon :name="$showPastDeals ? 'chevron-up' : 'chevron-down'" class="w-4 h-4 text-zinc-400" />
                </button>
                @if ($showPastDeals)
                    <div class="px-4 pb-4 space-y-2">
                        @foreach ($this->pastDeals as $deal)
                            <a href="{{ route('customers.show', ['customer' => $customer->id, 'deal' => $deal->id]) }}" wire:navigate wire:key="past-deal-{{ $deal->id }}" class="block p-2 -mx-1 rounded hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <flux:text class="font-medium text-sm">{{ $deal->title }}</flux:text>
                                        @if ($deal->property)
                                            <flux:text size="xs" class="text-zinc-500">{{ $deal->property->address }}</flux:text>
                                        @endif
                                    </div>
                                    <div class="text-right">
                                        <flux:badge size="sm" :color="$this->stageColors[$deal->stage] ?? 'zinc'">
                                            {{ $deal->stage_label }}
                                        </flux:badge>
                                        @if ($deal->estimated_value)
                                            <flux:text size="xs" class="text-zinc-500 mt-0.5">
                                                ${{ number_format($deal->estimated_value, 0) }}
                                            </flux:text>
                                        @endif
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Notes --}}
        @if ($customer->notes)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 mb-6">
                <flux:heading size="sm" class="mb-2">{{ __('Notes') }}</flux:heading>
                <flux:text class="whitespace-pre-wrap text-sm">{{ $customer->notes }}</flux:text>
            </div>
        @endif

    </div>

    {{-- Add Property Modal --}}
    <flux:modal wire:model="showAddProperty" variant="flyout" class="max-w-md">
        <form wire:submit="saveProperty">
            <flux:heading size="lg" class="mb-4">{{ __('Add Property') }}</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Address') }}</flux:label>
                    <flux:input wire:model="propertyAddress" placeholder="123 Main St" />
                    <flux:error name="propertyAddress" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:field>
                        <flux:label>{{ __('City') }}</flux:label>
                        <flux:input wire:model="propertyCity" placeholder="Denver" />
                        <flux:error name="propertyCity" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('State') }}</flux:label>
                        <flux:input wire:model="propertyState" placeholder="CO" />
                        <flux:error name="propertyState" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('ZIP') }}</flux:label>
                        <flux:input wire:model="propertyZip" placeholder="80202" />
                        <flux:error name="propertyZip" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Type') }}</flux:label>
                    <flux:select wire:model="propertyType">
                        <option value="residential">{{ __('Residential') }}</option>
                        <option value="commercial">{{ __('Commercial') }}</option>
                        <option value="industrial">{{ __('Industrial') }}</option>
                    </flux:select>
                    <flux:error name="propertyType" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeAddProperty" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Add Property') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Add Deal Modal --}}
    <flux:modal wire:model="showAddDeal" variant="flyout" class="max-w-md">
        <form wire:submit="saveDeal">
            <flux:heading size="lg" class="mb-4">{{ __('New Deal') }}</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Title') }}</flux:label>
                    <flux:input wire:model="dealTitle" :placeholder="__('Project name')" />
                    <flux:error name="dealTitle" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Description') }}</flux:label>
                    <flux:textarea wire:model="dealDescription" rows="3" :placeholder="__('Describe the project...')" />
                    <flux:error name="dealDescription" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Lead Source') }}</flux:label>
                    <flux:select wire:model="dealLeadSource">
                        <option value="">{{ __('Select source...') }}</option>
                        @foreach ($this->leadSources as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="dealLeadSource" />
                </flux:field>

                @if ($customer->properties->count() > 0)
                    <flux:field>
                        <flux:label>{{ __('Property (Optional)') }}</flux:label>
                        <flux:select wire:model="dealPropertyId">
                            <option value="">{{ __('Select a property...') }}</option>
                            @foreach ($customer->properties as $property)
                                <option value="{{ $property->id }}">{{ $property->address }}, {{ $property->city }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="dealPropertyId" />
                    </flux:field>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeAddDeal" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Create Deal') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Add Activity Modal --}}
    <flux:modal wire:model="showAddActivity" variant="flyout" class="max-w-md">
        <form wire:submit="saveActivity">
            <flux:heading size="lg" class="mb-4">{{ __('Add Activity') }}</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Title') }}</flux:label>
                    <flux:input wire:model="activityTitle" :placeholder="__('Follow up call')" />
                    <flux:error name="activityTitle" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Type') }}</flux:label>
                    <flux:select wire:model="activityType">
                        @foreach ($this->activityTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="activityType" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Due Date (Optional)') }}</flux:label>
                    <flux:input wire:model="activityDueAt" type="date" />
                    <flux:error name="activityDueAt" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Description (Optional)') }}</flux:label>
                    <flux:textarea wire:model="activityDescription" rows="3" :placeholder="__('Add notes...')" />
                    <flux:error name="activityDescription" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeAddActivity" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Add Activity') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Follow-Up Date Modal --}}
    <flux:modal wire:model="showFollowUpModal" class="max-w-sm">
        <form wire:submit="confirmFollowUp">
            <flux:heading size="lg" class="mb-4">{{ __('Schedule Follow-Up') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Follow-up Date') }}</flux:label>
                <flux:input wire:model="followUpDate" type="date" />
            </flux:field>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showFollowUpModal', false)" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Set Follow-Up') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Quote Schedule Modal --}}
    <flux:modal wire:model="showQuoteScheduleModal" class="max-w-sm">
        <form wire:submit="confirmQuoteSchedule">
            <flux:heading size="lg" class="mb-4">{{ __('Schedule Quote Appointment') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Appointment Date & Time') }}</flux:label>
                <flux:input wire:model="quoteScheduleDate" type="datetime-local" />
            </flux:field>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showQuoteScheduleModal', false)" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Schedule Quote') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
