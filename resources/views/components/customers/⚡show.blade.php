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

    // Email compose modal
    public bool $showCompose = false;

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
            'deals.appointmentBooking',
            'deals.originVendor',
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
                $q->orWhere(function ($q2) {
                    $q2->where('type', ChatThread::TYPE_SUPPORT)
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
     * Open tasks linked to this customer (direct + via deals).
     */
    #[Computed]
    public function customerOpenTasks()
    {
        $dealIds = $this->customer->deals->pluck('id');

        return Activity::query()
            ->with(['user', 'assignee'])
            ->incomplete()
            ->where(function ($q) use ($dealIds) {
                $q->where(function ($q2) {
                    $q2->where('subject_type', Customer::class)
                        ->where('subject_id', $this->customer->id);
                });
                if ($dealIds->isNotEmpty()) {
                    $q->orWhere(function ($q2) use ($dealIds) {
                        $q2->where('subject_type', Deal::class)
                            ->whereIn('subject_id', $dealIds);
                    });
                }
            })
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();
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
        unset($this->activeDeal, $this->activeDeals, $this->pastDeals, $this->activeDealEstimates, $this->unifiedActivityFeed, $this->customerOpenTasks, $this->activeJobs, $this->customerThreads);
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

    public function openCompose(): void
    {
        $this->showCompose = true;
    }

    #[On('email-sent')]
    public function closeCompose(): void
    {
        $this->showCompose = false;
    }
}; ?>

<div>
    <div class="max-w-6xl mx-auto">
        {{-- Header: Clean — name, badge, edit, new deal --}}
        <div class="flex items-center gap-2 sm:gap-3 mb-2 min-w-0">
            <flux:button variant="ghost" :href="route('customers.index')" icon="arrow-left" wire:navigate class="shrink-0" size="sm">
                <span class="hidden sm:inline">{{ __('Back') }}</span>
            </flux:button>
            <flux:heading size="xl" class="truncate min-w-0">{{ $customer->full_name }}</flux:heading>
            @if ($customer->status === 'active')
                <flux:badge size="sm" color="green" class="shrink-0">{{ __('Active') }}</flux:badge>
            @else
                <flux:badge size="sm" color="yellow" class="shrink-0">{{ __('Inactive') }}</flux:badge>
            @endif

            <div class="ml-auto flex items-center gap-1.5 sm:gap-2 shrink-0">
                @can('people.edit')
                    @livewire('customers.edit-modal', ['customerId' => $customer->id])
                @endcan
                @can('people.create')
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="openAddDeal">
                        <span class="hidden sm:inline">{{ __('New Deal') }}</span>
                    </flux:button>
                @endcan

                {{-- Overflow menu for secondary actions on mobile --}}
                <div class="lg:hidden">
                    <flux:dropdown>
                        <flux:button icon="ellipsis-vertical" variant="ghost" size="sm" />
                        <flux:menu>
                            @can('people.create')
                                <flux:menu.item icon="home" wire:click="openAddProperty">
                                    {{ __('Add Property') }}
                                </flux:menu.item>
                            @endcan
                            @can('people.edit')
                                <flux:menu.item icon="check-circle" wire:click="openAddActivity">
                                    {{ __('Add Activity') }}
                                </flux:menu.item>
                                @if (!$customer->hasPortalAccess())
                                    <flux:menu.item icon="envelope" wire:click="sendPortalInvite" wire:confirm="{{ __('Send portal invitation to') }} {{ $customer->email }}?">
                                        {{ __('Send Invite') }}
                                    </flux:menu.item>
                                @endif
                                <flux:menu.item wire:click="toggleStatus" wire:confirm="{{ __('Change customer status to') }} {{ $customer->status === 'active' ? __('inactive') : __('active') }}?">
                                    {{ $customer->status === 'active' ? __('Deactivate') : __('Reactivate') }}
                                </flux:menu.item>
                            @endcan
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </div>
        </div>

        {{-- Customer Meta Bar --}}
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400 mb-3 sm:pl-12">
            @if ($this->activeDeal?->lead_source)
                <span>{{ Deal::LEAD_SOURCES[$this->activeDeal->lead_source] ?? $this->activeDeal->lead_source }}</span>
            @elseif ($this->sourceLabel)
                <span>{{ $this->sourceLabel }}</span>
            @endif
            @if ($this->estimatePreferenceLabel)
                <span class="text-zinc-300 dark:text-zinc-600">&middot;</span>
                <flux:badge size="sm" color="purple">{{ $this->estimatePreferenceLabel }} {{ __('Est') }}</flux:badge>
            @endif
            <span class="text-zinc-300 dark:text-zinc-600">&middot;</span>
            <span>{{ __('Since') }} {{ $customer->created_at->format('M j, Y') }}</span>
            @if ($customer->hasPortalAccess())
                <span class="text-zinc-300 dark:text-zinc-600">&middot;</span>
                <flux:badge size="sm" color="green">{{ __('Portal') }}</flux:badge>
            @endif
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

        {{-- Deal Switcher (if multiple active deals) --}}
        @if ($this->activeDeals->count() > 1)
            <div class="flex items-center gap-3 mb-4">
                <flux:heading size="lg">{{ __('Active Deals') }}</flux:heading>
                <flux:badge size="sm" color="blue">{{ $this->activeDeals->count() }}</flux:badge>
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
            </div>
        @endif

        {{-- Two-Column Layout --}}
        <div class="flex flex-col lg:flex-row gap-6 mb-6">
            {{-- Main Column --}}
            <div class="lg:w-2/3 space-y-4">
                @if ($this->activeDeals->count() > 0 && $this->activeDeal)

                    {{-- Deal Status Bar (compact) --}}
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 bg-white dark:bg-zinc-900">
                        <div class="flex flex-col md:flex-row md:items-center gap-2 md:gap-3 mb-2">
                            <flux:heading size="lg" class="min-w-0 truncate">{{ $this->activeDeal->title }}</flux:heading>
                            <div class="flex flex-wrap items-center gap-2">
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
                        </div>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
                            @if ($this->activeDeal->lead_source)
                                <flux:badge size="sm" color="zinc">{{ Deal::LEAD_SOURCES[$this->activeDeal->lead_source] ?? $this->activeDeal->lead_source }}</flux:badge>
                            @endif
                            @if ($this->activeDeal->property)
                                <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($this->activeDeal->property->address . ', ' . $this->activeDeal->property->city . ', ' . $this->activeDeal->property->state) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                    <flux:icon name="map-pin" class="w-3.5 h-3.5" />
                                    {{ $this->activeDeal->property->address }}, {{ $this->activeDeal->property->city }}
                                    <flux:icon name="arrow-top-right-on-square" class="w-3 h-3 opacity-50" />
                                </a>
                            @endif
                            @if ($this->activeDeal->estimated_value)
                                <flux:badge size="sm" color="green">${{ number_format($this->activeDeal->estimated_value, 0) }}</flux:badge>
                            @endif
                            @if ($this->activeDeal->expected_start_date || $this->activeDeal->expected_end_date)
                                <span>{{ $this->activeDeal->expected_start_date?->format('M j') ?? '?' }} - {{ $this->activeDeal->expected_end_date?->format('M j, Y') ?? __('TBD') }}</span>
                            @endif
                        </div>
                        @if ($this->activeDeal->description)
                            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-2 italic">"{{ Str::limit($this->activeDeal->description, 200) }}"</p>
                        @endif
                    </div>

                    {{-- Appointment Booking --}}
                    @if ($this->activeDeal->appointmentBooking)
                        @php $booking = $this->activeDeal->appointmentBooking; @endphp
                        <div class="p-4 rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <flux:icon name="calendar-days" class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
                                    <flux:text class="font-medium text-emerald-700 dark:text-emerald-300">{{ __('Estimate Appointment') }}</flux:text>
                                    <flux:badge size="sm" :color="match($booking->status) {
                                        'confirmed' => 'green',
                                        'completed' => 'blue',
                                        'cancelled' => 'red',
                                        'no_show' => 'amber',
                                        'rescheduled' => 'purple',
                                        default => 'zinc',
                                    }">
                                        {{ ucfirst($booking->status) }}
                                    </flux:badge>
                                </div>
                                @if ($this->activeDeal->originVendor)
                                    <flux:badge size="sm" color="indigo">
                                        <flux:icon name="building-storefront" class="w-3 h-3 mr-0.5" />{{ $this->activeDeal->originVendor->name }}
                                    </flux:badge>
                                @endif
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                                <div>
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Date') }}</span>
                                    <p class="font-medium text-zinc-900 dark:text-white">{{ $booking->booked_date->format('M j, Y') }}</p>
                                </div>
                                <div>
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Time') }}</span>
                                    <p class="font-medium text-zinc-900 dark:text-white">{{ \Carbon\Carbon::parse($booking->booked_start_time)->format('g:i A') }} - {{ \Carbon\Carbon::parse($booking->booked_end_time)->format('g:i A') }}</p>
                                </div>
                                <div>
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Type') }}</span>
                                    <p class="font-medium text-zinc-900 dark:text-white">{{ $booking->estimate_preference === 'in_person' ? __('In Person') : __('Virtual') }}</p>
                                </div>
                                <div>
                                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Contact') }}</span>
                                    <p class="font-medium text-zinc-900 dark:text-white">{{ $booking->prospect_phone ?? $booking->prospect_email }}</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Estimates & Jobs Combined --}}
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-4">
                            <flux:heading size="sm">{{ __('Estimates & Jobs') }}</flux:heading>
                            @can('estimates.create')
                                @if ($this->activeDeal)
                                    <flux:button size="xs" icon="plus" :href="route('estimates.create', $this->activeDeal)" wire:navigate>{{ __('New Estimate') }}</flux:button>
                                @endif
                            @endcan
                        </div>
                        @php
                            $hasEstimates = $this->activeDealEstimates->count() > 0;
                            $hasJobs = $this->activeJobs->count() > 0 || $this->activeDeal?->job;
                            $jobStageColors = [
                                'estimate_accepted' => 'blue',
                                'scheduling_colors' => 'purple',
                                'job_prep' => 'amber',
                                'in_progress' => 'emerald',
                                'completed' => 'green',
                            ];
                            $statusColors = [
                                'draft' => 'zinc',
                                'sent' => 'blue',
                                'accepted' => 'green',
                                'rejected' => 'red',
                            ];
                        @endphp
                        @if ($hasEstimates || $hasJobs)
                            <div class="grid gap-3 sm:grid-cols-2">
                                {{-- Estimate Cards --}}
                                @foreach ($this->activeDealEstimates as $estimate)
                                    <a href="{{ route('estimates.show', $estimate) }}" wire:navigate wire:key="est-{{ $estimate->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 hover:shadow-md hover:border-blue-300 dark:hover:border-blue-600 transition cursor-pointer block">
                                        <div class="flex items-center justify-between mb-1">
                                            <flux:text class="font-medium text-sm">{{ $estimate->estimate_number }}</flux:text>
                                            <flux:badge size="sm" :color="$statusColors[$estimate->status] ?? 'zinc'">
                                                {{ ucfirst($estimate->status) }}
                                            </flux:badge>
                                        </div>
                                        @if ($estimate->title)
                                            <flux:text size="xs" class="text-zinc-500 truncate">{{ $estimate->title }}</flux:text>
                                        @endif
                                        <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mt-1">${{ number_format($estimate->total, 0) }}</flux:text>
                                    </a>
                                @endforeach

                                {{-- Job Cards --}}
                                @foreach ($this->activeJobs as $job)
                                    <a href="{{ route('jobs.show', $job) }}" wire:navigate wire:key="job-{{ $job->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 hover:shadow-md hover:border-blue-300 dark:hover:border-blue-600 transition cursor-pointer block">
                                        <div class="flex items-center justify-between mb-1">
                                            <flux:text class="font-medium text-sm">{{ $job->job_number ?? __('Job') }}</flux:text>
                                            <flux:badge size="sm" :color="$jobStageColors[$job->stage] ?? 'zinc'">
                                                {{ str_replace('_', ' ', ucwords($job->stage, '_')) }}
                                            </flux:badge>
                                        </div>
                                        <flux:text size="xs" class="text-zinc-500 truncate">{{ $job->deal?->title }}</flux:text>
                                        @if ($job->scheduled_date)
                                            <flux:text size="xs" class="text-zinc-500 mt-1">{{ __('Scheduled:') }} {{ $job->scheduled_date->format('M j, Y') }}</flux:text>
                                        @endif
                                    </a>
                                @endforeach

                                {{-- Deal's direct job if not already in activeJobs --}}
                                @if ($this->activeDeal->job && !$this->activeJobs->contains('id', $this->activeDeal->job->id))
                                    <a href="{{ route('jobs.show', $this->activeDeal->job) }}" wire:navigate class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 hover:shadow-md hover:border-blue-300 dark:hover:border-blue-600 transition cursor-pointer block">
                                        <div class="flex items-center justify-between mb-1">
                                            <flux:text class="font-medium text-sm">{{ $this->activeDeal->job->job_number ?? __('Job') }}</flux:text>
                                            <flux:badge size="sm" :color="$jobStageColors[$this->activeDeal->job->stage] ?? 'zinc'">
                                                {{ str_replace('_', ' ', ucwords($this->activeDeal->job->stage, '_')) }}
                                            </flux:badge>
                                        </div>
                                        @if ($this->activeDeal->job->scheduled_date)
                                            <flux:text size="xs" class="text-zinc-500 mt-1">{{ __('Scheduled:') }} {{ $this->activeDeal->job->scheduled_date->format('M j, Y') }}</flux:text>
                                        @endif
                                    </a>
                                @endif
                            </div>
                        @else
                            <div class="text-center py-6">
                                <flux:icon name="document-text" class="w-8 h-8 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                                <flux:text size="sm" class="text-zinc-500">{{ __('No estimates or jobs for this deal yet') }}</flux:text>
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

                    {{-- Open Tasks --}}
                    @if ($this->customerOpenTasks->isNotEmpty())
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <flux:heading size="sm">{{ __('Open Tasks') }}</flux:heading>
                                    <flux:badge size="sm" color="amber">{{ $this->customerOpenTasks->count() }}</flux:badge>
                                </div>
                                @can('people.edit')
                                    <flux:button size="xs" icon="plus" wire:click="openAddActivity">{{ __('Add') }}</flux:button>
                                @endcan
                            </div>
                            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($this->customerOpenTasks as $task)
                                    <div wire:key="open-task-{{ $task->id }}" class="flex items-center gap-3 py-2 {{ $task->isOverdue() ? 'bg-red-50/50 dark:bg-red-900/10 -mx-2 px-2 rounded' : '' }}">
                                        <button
                                            wire:click="toggleActivityComplete({{ $task->id }})"
                                            class="w-5 h-5 shrink-0 rounded border-2 flex items-center justify-center border-zinc-300 dark:border-zinc-600 hover:border-green-500 transition-colors"
                                        >
                                        </button>
                                        <div class="flex-1 min-w-0">
                                            <flux:text class="text-sm {{ $task->isOverdue() ? 'text-red-600 dark:text-red-400' : '' }}">{{ $task->title }}</flux:text>
                                            @if ($task->description)
                                                <flux:text size="xs" class="text-zinc-500 truncate">{{ Str::limit($task->description, 80) }}</flux:text>
                                            @endif
                                        </div>
                                        <div class="shrink-0 flex items-center gap-2">
                                            <flux:badge size="sm" color="zinc">{{ $task->type_label }}</flux:badge>
                                            @if ($task->due_at)
                                                <flux:text size="xs" class="{{ $task->isOverdue() ? 'text-red-500 font-medium' : 'text-zinc-500' }}">
                                                    @if ($task->isOverdue())
                                                        {{ __('Overdue') }} {{ $task->due_at->diffForHumans() }}
                                                    @elseif ($task->due_at->isToday())
                                                        {{ __('Today') }} {{ $task->due_at->format('g:ia') }}
                                                    @else
                                                        {{ $task->due_at->format('M j') }}
                                                    @endif
                                                </flux:text>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Activity Feed --}}
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-4">
                            <flux:heading size="sm">{{ __('Activity Feed') }}</flux:heading>
                            <flux:button size="xs" icon="plus" wire:click="openAddActivity">{{ __('Add') }}</flux:button>
                        </div>
                        @if ($this->unifiedActivityFeed->count() > 0)
                            <div class="space-y-3 max-h-96 overflow-y-auto lg:max-h-none lg:overflow-visible">
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

                @else
                    {{-- No Active Deals CTA --}}
                    <div class="border-2 border-dashed border-zinc-300 dark:border-zinc-600 rounded-lg p-8 text-center">
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
            </div>

            {{-- Sidebar (renders first on mobile) --}}
            <div class="order-first lg:order-none lg:w-1/3 space-y-4">
                {{-- Contact & Properties — 2 columns side by side --}}
                <div class="grid grid-cols-2 gap-4">
                    {{-- Contact Info Card --}}
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                        <flux:heading size="sm" class="mb-3">{{ __('Contact') }}</flux:heading>
                        <div class="space-y-2.5">
                            @if ($customer->phone)
                                <a href="tel:{{ $customer->phone }}" class="flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 transition-colors">
                                    <flux:icon name="phone" class="w-4 h-4 shrink-0" />
                                    <span class="truncate">{{ $customer->phone }}</span>
                                </a>
                            @endif
                            @if ($customer->email)
                                <button wire:click="openCompose" class="flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 transition-colors">
                                    <flux:icon name="envelope" class="w-4 h-4 shrink-0" />
                                    <span class="truncate">{{ $customer->email }}</span>
                                </button>
                            @endif
                            @if ($customer->full_address)
                                <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($customer->full_address) }}" target="_blank" rel="noopener" class="flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 transition-colors group">
                                    <flux:icon name="map-pin" class="w-4 h-4 shrink-0" />
                                    <span class="truncate">{{ $customer->full_address }}</span>
                                    <flux:icon name="arrow-top-right-on-square" class="w-3 h-3 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity" />
                                </a>
                            @endif
                        </div>

                        @if ($customer->referredBy || $customer->createdBy)
                            <div class="mt-3 pt-3 border-t border-zinc-100 dark:border-zinc-800 space-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                @if ($customer->referredBy)
                                    <p>{{ __('Referred by') }} {{ $customer->referredBy->name }}</p>
                                @endif
                                @if ($customer->createdBy)
                                    <p>{{ __('Created by') }} {{ $customer->createdBy->name }}</p>
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- Properties Card --}}
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-3">
                            <flux:heading size="sm">{{ __('Properties') }}</flux:heading>
                            @can('people.create')
                                <flux:button size="xs" icon="plus" wire:click="openAddProperty">{{ __('Add') }}</flux:button>
                            @endcan
                        </div>
                        @if ($customer->properties->count() > 0)
                            <div class="space-y-1">
                                @foreach ($customer->properties as $property)
                                    <div wire:key="prop-{{ $property->id }}" class="flex items-start justify-between gap-2 p-2 -mx-1 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors group">
                                        <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($property->address . ', ' . $property->city . ', ' . $property->state . ' ' . $property->zip) }}" target="_blank" rel="noopener" class="flex items-start gap-2 min-w-0 text-sm text-zinc-700 dark:text-zinc-300 hover:text-blue-600 dark:hover:text-blue-400 transition-colors">
                                            <flux:icon name="home" class="w-4 h-4 text-zinc-400 shrink-0 mt-0.5" />
                                            <div class="min-w-0">
                                                <span class="font-medium block truncate">{{ $property->address }}</span>
                                                <span class="text-xs text-zinc-500">{{ $property->city }}, {{ $property->state }}</span>
                                            </div>
                                            <flux:icon name="arrow-top-right-on-square" class="w-3 h-3 text-zinc-400 opacity-0 group-hover:opacity-100 transition-opacity shrink-0 mt-1" />
                                        </a>
                                        <flux:badge size="sm" color="zinc" class="shrink-0">{{ ucfirst($property->type) }}</flux:badge>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <flux:text size="sm" class="text-zinc-500">{{ __('No properties yet.') }}</flux:text>
                        @endif
                    </div>
                </div>

                {{-- Actions Card (only when deal is active) --}}
                @if ($this->activeDeal)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                        <flux:heading size="sm" class="mb-3">{{ __('Actions') }}</flux:heading>
                        <div class="flex flex-wrap gap-2 lg:flex-col">
                            @can('estimates.create')
                                <flux:button :href="route('estimates.create', $this->activeDeal)" variant="primary" icon="document-text" wire:navigate class="lg:w-full lg:justify-start" size="sm">
                                    <span class="hidden lg:inline">{{ __('Create Estimate') }}</span>
                                </flux:button>
                            @endcan
                            @can('people.edit')
                                <flux:button variant="ghost" icon="check-circle" wire:click="openAddActivity" class="lg:w-full lg:justify-start" size="sm">
                                    <span class="hidden lg:inline">{{ __('Log Activity') }}</span>
                                </flux:button>
                            @endcan
                            <flux:button variant="ghost" icon="chat-bubble-left-right" wire:click="$dispatch('open-communications-drawer')" class="lg:w-full lg:justify-start" size="sm">
                                <span class="hidden lg:inline">{{ __('Communications') }}</span>
                            </flux:button>
                            <flux:button variant="ghost" icon="arrow-top-right-on-square" :href="route('deals.show', $this->activeDeal)" wire:navigate class="lg:w-full lg:justify-start" size="sm">
                                <span class="hidden lg:inline">{{ __('Deal Details') }}</span>
                            </flux:button>
                            @can('deals.edit')
                                @if ($this->activeDeal->stage !== 'lost')
                                    <flux:button variant="ghost" icon="x-circle" wire:click="changeActiveDealStage('lost')" wire:confirm="{{ __('Mark this deal as lost?') }}" class="lg:w-full lg:justify-start text-red-600 hover:text-red-700 dark:text-red-400" size="sm">
                                        <span class="hidden lg:inline">{{ __('Mark Lost') }}</span>
                                    </flux:button>
                                @endif
                            @endcan
                        </div>

                        {{-- Secondary actions (desktop only, mobile uses overflow menu) --}}
                        <div class="hidden lg:block mt-3 pt-3 border-t border-zinc-100 dark:border-zinc-800 space-y-1">
                            @can('people.create')
                                <flux:button variant="ghost" icon="home" wire:click="openAddProperty" class="w-full justify-start" size="sm">
                                    {{ __('Add Property') }}
                                </flux:button>
                            @endcan
                            @can('people.edit')
                                @if (!$customer->hasPortalAccess())
                                    <flux:button variant="ghost" icon="envelope" wire:click="sendPortalInvite" wire:confirm="{{ __('Send portal invitation to') }} {{ $customer->email }}?" class="w-full justify-start" size="sm">
                                        {{ __('Send Invite') }}
                                    </flux:button>
                                @endif
                                <flux:button variant="ghost" wire:click="toggleStatus" wire:confirm="{{ __('Change customer status to') }} {{ $customer->status === 'active' ? __('inactive') : __('active') }}?" class="w-full justify-start" size="sm">
                                    {{ $customer->status === 'active' ? __('Deactivate') : __('Reactivate') }}
                                </flux:button>
                            @endcan
                        </div>
                    </div>
                @endif

                {{-- Messages Card --}}
                @if ($this->customerThreads->isNotEmpty())
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                        <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <flux:heading size="sm">{{ __('Messages') }}</flux:heading>
                                @php $totalUnread = $this->customerThreads->sum('unread_count'); @endphp
                                @if ($totalUnread > 0)
                                    <flux:badge size="sm" color="blue">{{ $totalUnread }}</flux:badge>
                                @endif
                            </div>
                        </div>
                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->customerThreads->take(3) as $thread)
                                <a href="{{ route('chat.show', $thread) }}" wire:navigate wire:key="thread-{{ $thread->id }}" class="flex items-start gap-3 p-3 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
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
                                                {{ $thread->latestMessage->user?->name }}: {{ Str::limit($thread->latestMessage->content, 40) }}
                                            </flux:text>
                                        @endif
                                    </div>
                                    <flux:text class="text-xs text-zinc-400 shrink-0">
                                        {{ ($thread->last_message_at ?? $thread->created_at)->diffForHumans() }}
                                    </flux:text>
                                </a>
                            @endforeach
                        </div>
                        @if ($this->customerThreads->count() > 3)
                            <div class="px-4 py-2 border-t border-zinc-200 dark:border-zinc-700">
                                <flux:button variant="ghost" size="xs" wire:click="$dispatch('open-communications-drawer')" class="w-full">
                                    {{ __('View All') }} &rarr;
                                </flux:button>
                            </div>
                        @endif
                    </div>
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

    {{-- Email Compose Flyout --}}
    <flux:modal wire:model="showCompose" name="compose-email" variant="flyout" class="w-full max-w-2xl">
        <div class="h-[80vh]">
            @if ($showCompose)
                <livewire:email.compose-form :to="$customer->email" :key="'compose-'.$customer->id" />
            @endif
        </div>
    </flux:modal>

    {{-- Communications Drawer --}}
    <livewire:customers.communications-drawer :customer="$customer" />
</div>
