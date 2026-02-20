<?php

use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\JobPrepApproval;
use App\Models\JobPrepPlan;
use App\Services\ChatService;
use App\Services\JobPrepService;
use App\Services\JobService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('components.layouts.customer', ['title' => 'Job Progress'])] class extends Component
{
    public Job $job;

    public bool $showColorModal = false;

    public string $newColorName = '';

    public string $newColorBrand = '';

    public string $newColorCode = '';

    public string $newColorHex = '#ffffff';

    public string $proposedDate = '';

    public function mount(Job $job): void
    {
        $this->ensureCustomerOwnsJob($job);
        $this->job = $job->load(['deal.customer', 'deal.property', 'deal.chatThread', 'colors.selectedBy', 'estimate', 'prepPlan.days.tasks']);

        if ($job->scheduled_date) {
            $this->proposedDate = $job->scheduled_date->format('Y-m-d');
        }

        $this->ensureChatParticipant();

        // Reload chatThread since ensureChatParticipant may have created it
        $this->job->load('deal.chatThread');

        $this->mountActivityFeed();
    }

    public function boot(): void
    {
        $user = Auth::guard('customer')->user();
        if (! $user || ! $user->isCustomer()) {
            abort(403);
        }
    }

    /**
     * Echo listeners for real-time updates on the customer job channel.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        if (! $this->job) {
            return [];
        }

        $listeners = [
            "echo-private:customer.job.{$this->job->id},stage.changed" => 'onStageChanged',
            "echo-private:customer.job.{$this->job->id},date.updated" => 'onDateUpdated',
            "echo-private:customer.job.{$this->job->id},color.updated" => 'onColorUpdated',
            "echo-private:customer.job.{$this->job->id},prep.plan.updated" => 'onPrepPlanUpdated',
            "echo-private:customer.job.{$this->job->id},prep.approval.changed" => 'onPrepApprovalChanged',
            "echo-private:customer.job.{$this->job->id},photo.updated" => 'onPhotoUpdated',
            "echo-private:customer.job.{$this->job->id},job.notification" => 'onJobNotification',
            "echo-private:customer.job.{$this->job->id},.video.call.started" => 'onVideoCallStarted',
        ];

        $chatThread = $this->job->deal?->chatThread;
        if ($chatThread) {
            $listeners["echo-private:chat.thread.{$chatThread->id},.message.sent"] = 'onChatMessageReceived';
        }

        return $listeners;
    }

    public function onStageChanged(): void
    {
        $this->job->refresh();
        unset($this->prepPlan);
        unset($this->prepProgress);
    }

    public function onDateUpdated(): void
    {
        $this->job->refresh();
        if ($this->job->scheduled_date) {
            $this->proposedDate = $this->job->scheduled_date->format('Y-m-d');
        }
    }

    public function onColorUpdated(): void
    {
        $this->job->load('colors.selectedBy');
    }

    public function onPrepPlanUpdated(): void
    {
        unset($this->prepPlan);
        unset($this->prepProgress);
        $this->job->refresh();
    }

    public function onPrepApprovalChanged(): void
    {
        unset($this->prepPlan);
        unset($this->prepProgress);
        $this->job->refresh();
    }

    public function onPhotoUpdated(): void
    {
        $this->job->refresh();
    }

    public function onJobNotification(array $data): void
    {
        $message = $data['message'] ?? '';
        $type = $data['type'] ?? 'info';

        if ($message) {
            session()->flash($type === 'error' ? 'error' : 'success', $message);
        }
    }

    public function onVideoCallStarted(array $data): void
    {
        $this->dispatch('video-call-started', roomId: $data['room_id'] ?? '', initiator: $data['initiator_name'] ?? '');
    }

    public function onChatMessageReceived(): void
    {
        $this->job->load('deal.chatThread');
    }

    /**
     * Authorize: customer can only see their own jobs.
     */
    private function ensureCustomerOwnsJob(Job $job): void
    {
        $user = Auth::guard('customer')->user();
        $customer = Customer::where('user_id', $user->id)->first();

        if (! $customer || ! $job->deal?->customerCanAccess($customer)) {
            abort(404);
        }
    }

    /**
     * Ensure the customer is a participant in the deal's chat thread.
     */
    private function ensureChatParticipant(): void
    {
        $user = Auth::guard('customer')->user();
        $deal = $this->job->deal;

        if ($deal) {
            $thread = $deal->getOrCreateChatThread();
            if (! $thread->hasParticipant($user)) {
                $thread->addParticipant($user);
            }
        }
    }

    #[Computed]
    public function chatThread(): ?ChatThread
    {
        return $this->job->deal?->chatThread;
    }

    #[Computed]
    public function chatMessages()
    {
        $thread = $this->chatThread;
        if (! $thread) {
            return collect();
        }

        $user = Auth::guard('customer')->user();

        return app(ChatService::class)->getMessagesForThread($thread, $user, 30);
    }

    /**
     * Confirm the scheduled date from the customer side.
     */
    public function confirmDate(): void
    {
        if (! $this->job->scheduled_date) {
            session()->flash('error', __('No date has been set yet.'));

            return;
        }

        if ($this->job->customer_date_confirmed) {
            session()->flash('error', __('Date already confirmed.'));

            return;
        }

        app(JobService::class)->confirmCustomerDate($this->job);
        $this->job->refresh();
        session()->flash('success', __('Date confirmed! Thank you.'));
    }

    /**
     * Propose a preferred start date from the customer.
     */
    public function proposeDate(): void
    {
        $this->validate([
            'proposedDate' => 'required|date|after_or_equal:today',
        ]);

        $date = Carbon::parse($this->proposedDate);

        app(JobService::class)->proposeCustomerDate($this->job, $date);
        $this->job->refresh();
        $this->job->load('deal.chatThread');

        session()->flash('success', __('Your preferred date has been proposed. We\'ll review and confirm shortly.'));
    }

    /**
     * Add a customer-selected color.
     */
    public function addColor(): void
    {
        $this->validate([
            'newColorName' => 'required|string|max:255',
            'newColorBrand' => 'nullable|string|max:255',
            'newColorCode' => 'nullable|string|max:50',
            'newColorHex' => 'nullable|string|max:7',
        ]);

        if ($this->job->allowed_colors > 0 && $this->job->colors()->count() >= $this->job->allowed_colors) {
            session()->flash('error', __('Maximum number of colors reached.'));

            return;
        }

        $user = Auth::guard('customer')->user();

        $this->job->colors()->create([
            'name' => $this->newColorName,
            'brand' => $this->newColorBrand ?: null,
            'code' => $this->newColorCode ?: null,
            'hex_value' => $this->newColorHex !== '#ffffff' ? $this->newColorHex : null,
            'selected_by' => $user->id,
            'sort_order' => $this->job->colors()->count(),
        ]);

        $this->reset('newColorName', 'newColorBrand', 'newColorCode', 'newColorHex');
        $this->showColorModal = false;
        $this->job->refresh();
    }

    /**
     * Confirm colors from the customer side.
     */
    public function confirmColors(): void
    {
        if ($this->job->colors()->count() === 0) {
            session()->flash('error', __('Add at least one color first.'));

            return;
        }

        if ($this->job->customer_colors_confirmed) {
            session()->flash('error', __('Colors already confirmed.'));

            return;
        }

        app(JobService::class)->confirmCustomerColors($this->job);
        $this->job->refresh();
        session()->flash('success', __('Colors confirmed! Thank you.'));
    }

    #[On('message-sent')]
    public function onMessageSent(): void
    {
        $thread = $this->chatThread;
        if ($thread) {
            $user = Auth::guard('customer')->user();
            app(ChatService::class)->markThreadAsRead($thread, $user);
        }
    }

    #[Computed]
    public function isSchedulingStage(): bool
    {
        return $this->job->stage === 'scheduling_colors';
    }

    #[Computed]
    public function canAddColors(): bool
    {
        return $this->isSchedulingStage
            && ! $this->job->customer_colors_confirmed
            && ($this->job->allowed_colors === 0 || $this->job->colors()->count() < $this->job->allowed_colors);
    }

    #[Computed]
    public function prepPlan(): ?JobPrepPlan
    {
        return $this->job->prepPlan?->load(['days.tasks.serviceCategory', 'approvals.serviceCategory']);
    }

    #[Computed]
    public function prepProgress(): ?array
    {
        $plan = $this->prepPlan;

        if (! $plan) {
            return null;
        }

        return app(JobPrepService::class)->getProgress($plan);
    }

    /**
     * Check whether a service category's tasks are visible to the customer.
     * Requires both team AND crew approval for that service.
     */
    public function isServiceVisibleToCustomer(int $serviceCategoryId): bool
    {
        return $this->prepPlan?->isServiceVisibleToCustomer($serviceCategoryId) ?? false;
    }

    /**
     * Approve a specific service section from the customer side.
     */
    public function approveServiceSection(int $serviceCategoryId): void
    {
        $plan = $this->prepPlan;

        if (! $plan) {
            return;
        }

        // Only allow approval if the section is visible to customer (team + crew approved)
        if (! $plan->isServiceVisibleToCustomer($serviceCategoryId)) {
            return;
        }

        // Check if customer already approved this section
        $existing = $plan->approvals
            ->where('service_category_id', $serviceCategoryId)
            ->where('party', JobPrepApproval::PARTY_CUSTOMER)
            ->first();

        if ($existing?->approved) {
            return;
        }

        $user = Auth::guard('customer')->user();
        app(JobPrepService::class)->approveSection($plan, $user, 'customer', $serviceCategoryId);
        unset($this->prepPlan);
        unset($this->prepProgress);
        $this->job->refresh();
    }

    /**
     * Legacy: approve the entire plan (backwards compatibility for plans without service categories).
     */
    public function approvePrepPlan(): void
    {
        $plan = $this->prepPlan;

        if (! $plan || $plan->customer_approved) {
            return;
        }

        app(JobPrepService::class)->approvePlan($plan, Auth::guard('customer')->user(), 'customer');
        unset($this->prepPlan);
        unset($this->prepProgress);
        $this->job->refresh();
    }

    /**
     * Activity types that are safe/useful for customers to see.
     *
     * @var array<string>
     */
    private const CUSTOMER_VISIBLE_ACTIVITIES = [
        'stage_changed',
        'date_proposed',
        'date_confirmed_company',
        'date_confirmed_customer',
        'colors_confirmed_company',
        'colors_confirmed_customer',
        'color_added',
        'crew_accepted',
        'completed',
        'prep_plan_created',
        'prep_plan_changed',
        'prep_task_completed',
        'prep_approved_team',
        'prep_approved_crew',
        'prep_approved_customer',
        'job_started',
        'photo_uploaded',
        'photo_commented',
        'service_accepted',
        'prep_section_approved',
    ];

    public int $activityPage = 1;

    public int $activityPerPage = 15;

    public bool $hasMoreActivities = false;

    /** @var \Illuminate\Support\Collection<int, JobActivity> */
    public Collection $activityItems;

    public function mountActivityFeed(): void
    {
        $this->activityItems = collect();
        $this->loadActivities();
    }

    public function loadActivities(): void
    {
        $query = $this->job->jobActivities()
            ->whereIn('activity_type', self::CUSTOMER_VISIBLE_ACTIVITIES)
            ->latest()
            ->with('user');

        $activities = $query
            ->skip(($this->activityPage - 1) * $this->activityPerPage)
            ->take($this->activityPerPage + 1)
            ->get();

        $this->hasMoreActivities = $activities->count() > $this->activityPerPage;
        $newItems = $activities->take($this->activityPerPage);

        $this->activityItems = $this->activityItems->concat($newItems);
    }

    public function loadMoreActivities(): void
    {
        $this->activityPage++;
        $this->loadActivities();
    }

    /**
     * Get a customer-friendly icon for an activity type.
     */
    public function activityIcon(string $type): string
    {
        return match ($type) {
            'stage_changed' => 'arrow-path',
            'date_proposed', 'date_confirmed_company', 'date_confirmed_customer' => 'calendar',
            'colors_confirmed_company', 'colors_confirmed_customer', 'color_added' => 'swatch',
            'crew_accepted', 'service_accepted' => 'user-group',
            'completed' => 'check-circle',
            'prep_plan_created', 'prep_plan_changed' => 'clipboard-document-list',
            'prep_task_completed' => 'check',
            'prep_approved_team', 'prep_approved_crew', 'prep_approved_customer', 'prep_section_approved' => 'hand-thumb-up',
            'job_started' => 'play',
            'photo_uploaded', 'photo_commented' => 'photo',
            default => 'information-circle',
        };
    }

    /**
     * Get a customer-friendly color for an activity type.
     */
    public function activityColor(string $type): string
    {
        return match ($type) {
            'completed' => 'text-green-500',
            'stage_changed', 'job_started' => 'text-blue-500',
            'date_proposed', 'date_confirmed_company', 'date_confirmed_customer' => 'text-indigo-500',
            'colors_confirmed_company', 'colors_confirmed_customer', 'color_added' => 'text-pink-500',
            'crew_accepted', 'service_accepted' => 'text-orange-500',
            'prep_approved_team', 'prep_approved_crew', 'prep_approved_customer', 'prep_section_approved' => 'text-emerald-500',
            'photo_uploaded', 'photo_commented' => 'text-cyan-500',
            default => 'text-zinc-400',
        };
    }
}; ?>

<div>
    <div class="max-w-4xl mx-auto">
        {{-- Header --}}
        <div class="flex flex-col gap-4 mb-4">
            <div class="flex items-center gap-3">
                <flux:button variant="ghost" :href="route('customer.jobs.index')" icon="arrow-left" wire:navigate class="shrink-0">
                    {{ __('Back') }}
                </flux:button>
                <div class="flex-1 min-w-0">
                    <flux:heading size="xl" class="truncate">{{ $job->title }}</flux:heading>
                    <flux:text class="text-zinc-500">{{ $job->job_number }}</flux:text>
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if (! $job->isCompleted())
                    <livewire:video-call.job-call :job="$job" :key="'video-call-'.$job->id" />
                @endif
                @if ($job->estimate)
                    <flux:button
                        :href="route('customer.estimates.show', $job->estimate)"
                        variant="ghost"
                        icon="document-text"
                        wire:navigate
                        class="shrink-0"
                    >
                        {{ __('View Estimate') }}
                    </flux:button>
                @endif
            </div>
        </div>

        {{-- Quick Info Bar --}}
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 mb-6 px-4 py-3 bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700 rounded-lg text-sm">
            @if ($job->deal?->property)
                <div class="flex items-center gap-2">
                    <flux:icon name="map-pin" class="w-4 h-4 text-zinc-400 shrink-0" />
                    <flux:text class="text-zinc-600 dark:text-zinc-300">
                        {{ $job->deal->property->full_address ?: $job->deal->property->address }}
                    </flux:text>
                </div>
            @endif
            @if ($job->estimate)
                <div class="flex items-center gap-2">
                    <flux:icon name="document-text" class="w-4 h-4 text-zinc-400 shrink-0" />
                    <flux:text class="text-zinc-600 dark:text-zinc-300">
                        {{ __('Estimate') }} {{ $job->estimate->estimate_number }}
                        @if ($job->estimate->total)
                            &mdash; ${{ number_format($job->estimate->total, 2) }}
                        @endif
                    </flux:text>
                </div>
            @endif
            @if ($job->scheduled_date)
                <div class="flex items-center gap-2">
                    <flux:icon name="calendar" class="w-4 h-4 text-zinc-400 shrink-0" />
                    <flux:text class="text-zinc-600 dark:text-zinc-300">
                        {{ $job->scheduled_date->format('M j, Y') }}
                    </flux:text>
                </div>
            @endif
            <div class="flex items-center gap-2">
                <flux:icon name="signal" class="w-4 h-4 text-zinc-400 shrink-0" />
                <flux:badge size="sm" :color="match($job->stage) {
                    'estimate_accepted' => 'zinc',
                    'scheduling_colors' => 'blue',
                    'job_prep' => 'yellow',
                    'in_progress' => 'orange',
                    'completed' => 'green',
                    default => 'zinc',
                }">
                    {{ App\Models\Job::STAGES[$job->stage] ?? $job->stage }}
                </flux:badge>
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

        {{-- Progress Timeline --}}
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
            <flux:heading size="sm" class="mb-6">{{ __('Job Progress') }}</flux:heading>
            <div class="flex items-center justify-between">
                @foreach (App\Models\Job::STAGES as $stageKey => $stageLabel)
                    @php
                        $stageNum = App\Models\Job::STAGE_ORDER[$stageKey];
                        $currentNum = $job->stage_number;
                        $isActive = $stageKey === $job->stage;
                        $isCompleted = $stageNum < $currentNum;
                        $isFuture = $stageNum > $currentNum;
                    @endphp
                    <div class="flex-1 flex flex-col items-center relative" wire:key="progress-{{ $stageKey }}">
                        @if (! $loop->first)
                            <div class="absolute top-5 -left-1/2 w-full h-0.5 {{ $isCompleted || $isActive ? 'bg-green-500' : 'bg-zinc-300 dark:bg-zinc-600' }}"></div>
                        @endif

                        <div class="relative z-10 flex items-center justify-center w-10 h-10 rounded-full border-2 transition-all
                            {{ $isCompleted ? 'bg-green-500 border-green-500 text-white' : '' }}
                            {{ $isActive ? 'bg-blue-500 border-blue-500 text-white ring-4 ring-blue-100 dark:ring-blue-900' : '' }}
                            {{ $isFuture ? 'bg-white dark:bg-zinc-800 border-zinc-300 dark:border-zinc-600 text-zinc-400' : '' }}
                        ">
                            @if ($isCompleted)
                                <flux:icon name="check" class="w-5 h-5" />
                            @else
                                <span class="text-sm font-bold">{{ $stageNum }}</span>
                            @endif
                        </div>

                        <flux:text class="mt-2 text-xs text-center leading-tight {{ $isActive ? 'font-semibold text-blue-600 dark:text-blue-400' : 'text-zinc-500' }}">
                            {{ $stageLabel }}
                        </flux:text>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Stage Content --}}
        @if ($job->stage === 'estimate_accepted')
            {{-- Stage 1: Estimate Accepted --}}
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="check-circle" class="w-16 h-16 mx-auto text-green-500 mb-4" />
                <flux:heading size="lg">{{ __('Your Estimate Has Been Accepted') }}</flux:heading>
                <flux:text class="text-zinc-500 mt-2 max-w-md mx-auto">
                    {{ __('Great news! Your painting project is confirmed. Our team will begin scheduling and color selection soon.') }}
                </flux:text>
                @if ($job->estimate)
                    <div class="mt-4">
                        <flux:badge size="sm" color="green">
                            {{ __('Estimate') }}: {{ $job->estimate->estimate_number }}
                        </flux:badge>
                    </div>
                @endif
            </div>

        @elseif ($job->stage === 'scheduling_colors')
            {{-- Stage 2: Scheduling & Colors (Interactive) --}}
            <div class="space-y-6">
                {{-- Date Confirmation --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Schedule Your Start Date') }}</flux:heading>

                    @if ($job->scheduled_date)
                        <div class="flex items-center justify-between">
                            <div>
                                <flux:heading size="lg">{{ $job->scheduled_date->format('l, F j, Y') }}</flux:heading>
                                <flux:text class="text-zinc-500 mt-1">
                                    @if ($job->company_date_confirmed)
                                        {{ __('Our team has confirmed this date.') }}
                                    @else
                                        {{ __('Our team is reviewing this date.') }}
                                    @endif
                                </flux:text>
                            </div>
                            <div>
                                @if ($job->customer_date_confirmed)
                                    <flux:badge size="lg" color="green">
                                        <flux:icon name="check" class="w-4 h-4 mr-1" /> {{ __('Date Confirmed') }}
                                    </flux:badge>
                                @else
                                    <flux:button wire:click="confirmDate" variant="primary" icon="check">
                                        {{ __('Confirm This Date') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-4">
                            <div class="flex items-center gap-2">
                                @if ($job->company_date_confirmed)
                                    <flux:icon name="check-circle" class="w-5 h-5 text-green-500" />
                                @else
                                    <flux:icon name="clock" class="w-5 h-5 text-zinc-400" />
                                @endif
                                <flux:text class="text-sm">{{ __('Company confirmation') }}</flux:text>
                            </div>
                            <div class="flex items-center gap-2">
                                @if ($job->customer_date_confirmed)
                                    <flux:icon name="check-circle" class="w-5 h-5 text-green-500" />
                                @else
                                    <flux:icon name="clock" class="w-5 h-5 text-zinc-400" />
                                @endif
                                <flux:text class="text-sm">{{ __('Your confirmation') }}</flux:text>
                            </div>
                        </div>

                        {{-- Propose a different date --}}
                        @if (! $job->customer_date_confirmed)
                            <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700" x-data="{ showDatePicker: false }">
                                <flux:button variant="ghost" size="sm" icon="calendar" x-on:click="showDatePicker = !showDatePicker">
                                    {{ __('Propose a Different Date') }}
                                </flux:button>
                                <div x-show="showDatePicker" x-cloak class="mt-3">
                                    <form wire:submit="proposeDate" class="flex items-end gap-3">
                                        <flux:field class="flex-1">
                                            <flux:label>{{ __('Your Preferred Date') }}</flux:label>
                                            <flux:input type="date" wire:model="proposedDate" :min="now()->format('Y-m-d')" />
                                            <flux:error name="proposedDate" />
                                        </flux:field>
                                        <flux:button type="submit" variant="primary" size="sm">
                                            {{ __('Propose This Date') }}
                                        </flux:button>
                                    </form>
                                </div>
                            </div>
                        @endif
                    @else
                        {{-- No date set yet - let customer propose one --}}
                        <div class="py-4">
                            <flux:text class="text-zinc-500 mb-4">
                                {{ __('No date has been set yet. You can propose a preferred start date below, or wait for our team to schedule one.') }}
                            </flux:text>
                            <form wire:submit="proposeDate" class="flex items-end gap-3">
                                <flux:field class="flex-1">
                                    <flux:label>{{ __('Preferred Start Date') }}</flux:label>
                                    <flux:input type="date" wire:model="proposedDate" :min="now()->format('Y-m-d')" />
                                    <flux:error name="proposedDate" />
                                </flux:field>
                                <flux:button type="submit" variant="primary" icon="calendar">
                                    {{ __('Propose Date') }}
                                </flux:button>
                            </form>
                        </div>
                    @endif
                </div>

                {{-- Color Selection --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <flux:heading size="sm">{{ __('Select Your Colors') }}</flux:heading>
                            @if ($job->allowed_colors > 0)
                                <flux:text class="text-sm text-zinc-500">
                                    {{ __('You can choose up to :count colors.', ['count' => $job->allowed_colors]) }}
                                    {{ __(':count selected.', ['count' => $job->colors->count()]) }}
                                </flux:text>
                            @endif
                        </div>
                        @if ($this->canAddColors)
                            <flux:button wire:click="$set('showColorModal', true)" size="sm" icon="plus">
                                {{ __('Add Color') }}
                            </flux:button>
                        @endif
                    </div>

                    @if ($job->colors->count() > 0)
                        <div class="space-y-3">
                            @foreach ($job->colors as $color)
                                <div class="flex items-center gap-3 p-3 rounded-lg border border-zinc-200 dark:border-zinc-700" wire:key="cust-color-{{ $color->id }}">
                                    @if ($color->hex_value)
                                        <div class="w-10 h-10 rounded-lg border border-zinc-300 dark:border-zinc-600 shrink-0" style="background-color: {{ $color->hex_value }}"></div>
                                    @endif
                                    <div class="flex-1">
                                        <flux:text class="font-medium">{{ $color->name }}</flux:text>
                                        @if ($color->brand || $color->code)
                                            <flux:text class="text-sm text-zinc-500">
                                                {{ collect([$color->brand, $color->code])->filter()->join(' - ') }}
                                            </flux:text>
                                        @endif
                                    </div>
                                    @if ($color->selectedBy)
                                        <flux:text class="text-xs text-zinc-400">
                                            {{ __('Selected by :name', ['name' => $color->selectedBy->name]) }}
                                        </flux:text>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="text-center py-6">
                            <flux:icon name="swatch" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                            <flux:text class="text-zinc-500">{{ __('No colors selected yet. Add your preferred paint colors.') }}</flux:text>
                        </div>
                    @endif

                    <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                        <div class="flex items-center justify-between">
                            <div class="grid grid-cols-2 gap-4 flex-1">
                                <div class="flex items-center gap-2">
                                    @if ($job->company_colors_confirmed)
                                        <flux:icon name="check-circle" class="w-5 h-5 text-green-500" />
                                    @else
                                        <flux:icon name="clock" class="w-5 h-5 text-zinc-400" />
                                    @endif
                                    <flux:text class="text-sm">{{ __('Company approved') }}</flux:text>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if ($job->customer_colors_confirmed)
                                        <flux:icon name="check-circle" class="w-5 h-5 text-green-500" />
                                    @else
                                        <flux:icon name="clock" class="w-5 h-5 text-zinc-400" />
                                    @endif
                                    <flux:text class="text-sm">{{ __('Your approval') }}</flux:text>
                                </div>
                            </div>

                            @if (! $job->customer_colors_confirmed && $job->colors->count() > 0)
                                <flux:button wire:click="confirmColors" variant="primary" size="sm" icon="check">
                                    {{ __('Confirm Colors') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

        @elseif ($job->stage === 'job_prep')
            {{-- Stage 3: Job Prep --}}
            @if ($this->prepPlan)
                @php
                    $plan = $this->prepPlan;
                    $progress = $this->prepProgress;

                    // Collect all distinct service categories from tasks
                    $allServiceCategories = $plan->days
                        ->flatMap(fn ($day) => $day->tasks)
                        ->pluck('serviceCategory')
                        ->filter()
                        ->unique('id')
                        ->sortBy('sort_order');

                    // Determine which are visible (team + crew approved)
                    $visibleCategories = $allServiceCategories->filter(
                        fn ($cat) => $plan->isServiceVisibleToCustomer($cat->id)
                    );

                    $hasHiddenCategories = $allServiceCategories->count() > $visibleCategories->count();

                    // Check if this plan uses service-based approvals
                    $hasServiceApprovals = $plan->approvals->isNotEmpty();

                    // Tasks with no service category (legacy/untagged)
                    $untaggedTasks = $plan->days
                        ->flatMap(fn ($day) => $day->tasks)
                        ->whereNull('service_category_id');
                @endphp
                <div class="space-y-6">
                    {{-- Plan Summary --}}
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <flux:icon name="wrench-screwdriver" class="w-6 h-6 text-yellow-500" />
                            <flux:heading size="sm">{{ __('Prep Plan') }}</flux:heading>
                        </div>
                        <div class="grid grid-cols-3 gap-4">
                            <div class="text-center p-3 bg-zinc-50 dark:bg-zinc-800 rounded-lg">
                                <flux:text class="text-2xl font-bold">{{ $plan->crew_size }}</flux:text>
                                <flux:text class="text-xs text-zinc-500">{{ __('Crew Size') }}</flux:text>
                            </div>
                            <div class="text-center p-3 bg-zinc-50 dark:bg-zinc-800 rounded-lg">
                                <flux:text class="text-2xl font-bold">{{ number_format($plan->hours_per_day, 1) }}</flux:text>
                                <flux:text class="text-xs text-zinc-500">{{ __('Hours / Day') }}</flux:text>
                            </div>
                            <div class="text-center p-3 bg-zinc-50 dark:bg-zinc-800 rounded-lg">
                                <flux:text class="text-2xl font-bold">{{ $plan->total_days }}</flux:text>
                                <flux:text class="text-xs text-zinc-500">{{ __('Days') }}</flux:text>
                            </div>
                        </div>
                        @if ($job->scheduled_date)
                            <div class="mt-4 flex items-center gap-2">
                                <flux:icon name="calendar" class="w-4 h-4 text-zinc-400" />
                                <flux:text class="text-sm text-zinc-500">
                                    {{ __('Scheduled: :date', ['date' => $job->scheduled_date->format('M j, Y')]) }}
                                </flux:text>
                            </div>
                        @endif
                    </div>

                    @if ($hasServiceApprovals && $allServiceCategories->isNotEmpty())
                        {{-- Service-grouped view with gated visibility --}}
                        @foreach ($visibleCategories as $category)
                            @php
                                $categoryApprovals = $plan->approvals->where('service_category_id', $category->id);
                                $teamApproval = $categoryApprovals->where('party', 'team')->first();
                                $crewApproval = $categoryApprovals->where('party', 'crew')->first();
                                $customerApproval = $categoryApprovals->where('party', 'customer')->first();
                                $isCustomerApproved = $customerApproval?->approved ?? false;
                            @endphp
                            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden" wire:key="service-section-{{ $category->id }}">
                                {{-- Service Header --}}
                                <div class="flex items-center justify-between p-4 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                                    <div class="flex items-center gap-3">
                                        @if ($category->icon)
                                            <flux:icon :name="$category->icon" class="w-5 h-5" style="color: {{ $category->color ?? '#6b7280' }}" />
                                        @endif
                                        <flux:heading size="sm">{{ __(':name Tasks', ['name' => __($category->name)]) }}</flux:heading>
                                        <div class="flex items-center gap-2 ml-2">
                                            <flux:badge size="sm" :color="$teamApproval?->approved ? 'green' : 'zinc'">
                                                {{ __('Team') }} {{ $teamApproval?->approved ? '✓' : '...' }}
                                            </flux:badge>
                                            <flux:badge size="sm" :color="$crewApproval?->approved ? 'green' : 'zinc'">
                                                {{ __('Crew') }} {{ $crewApproval?->approved ? '✓' : '...' }}
                                            </flux:badge>
                                            <flux:badge size="sm" :color="$isCustomerApproved ? 'green' : 'zinc'">
                                                {{ __('You') }} {{ $isCustomerApproved ? '✓' : '...' }}
                                            </flux:badge>
                                        </div>
                                    </div>
                                </div>

                                {{-- Day-by-Day Tasks for this service --}}
                                <div class="divide-y divide-zinc-200 dark:divide-zinc-700" x-data="{ activeDay: {{ $plan->days->first()?->day_number ?? 1 }} }">
                                    {{-- Day Tab Bar --}}
                                    <div class="flex border-b border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 overflow-x-auto">
                                        @foreach ($plan->days as $day)
                                            @php
                                                $serviceTasks = $day->tasksForService($category->id);
                                            @endphp
                                            @if ($serviceTasks->count() > 0)
                                                <button
                                                    x-on:click="activeDay = {{ $day->day_number }}"
                                                    :class="activeDay === {{ $day->day_number }}
                                                        ? 'border-b-2 border-blue-500 text-blue-600 dark:text-blue-400 bg-white dark:bg-zinc-900'
                                                        : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                                                    class="px-4 py-2 text-sm font-medium whitespace-nowrap transition-colors"
                                                    wire:key="svc-{{ $category->id }}-day-tab-{{ $day->id }}"
                                                >
                                                    {{ $day->label }}
                                                    <flux:badge size="sm" class="ml-1">{{ $serviceTasks->count() }}</flux:badge>
                                                </button>
                                            @endif
                                        @endforeach
                                    </div>

                                    {{-- Day Content --}}
                                    @foreach ($plan->days as $day)
                                        @php
                                            $serviceTasks = $day->tasksForService($category->id);
                                        @endphp
                                        @if ($serviceTasks->count() > 0)
                                            <div x-show="activeDay === {{ $day->day_number }}" x-cloak class="p-4" wire:key="svc-{{ $category->id }}-day-content-{{ $day->id }}">
                                                <div class="space-y-2">
                                                    @foreach ($serviceTasks as $task)
                                                        <div class="flex items-start gap-3 p-3 rounded-lg border border-zinc-200 dark:border-zinc-700" wire:key="svc-task-{{ $task->id }}">
                                                            @if ($task->task_type === 'surface')
                                                                <div class="w-2 h-8 rounded-full bg-blue-400 shrink-0 mt-0.5"></div>
                                                            @elseif ($task->task_type === 'addon')
                                                                <div class="w-2 h-8 rounded-full bg-purple-400 shrink-0 mt-0.5"></div>
                                                            @else
                                                                <div class="w-2 h-8 rounded-full bg-zinc-400 shrink-0 mt-0.5"></div>
                                                            @endif

                                                            <div class="flex-1 min-w-0">
                                                                <flux:text class="font-medium text-sm truncate">{{ $task->title }}</flux:text>
                                                                @if ($task->area_name)
                                                                    <flux:text class="text-xs text-zinc-500">{{ $task->area_name }}</flux:text>
                                                                @endif
                                                                @if ($task->description)
                                                                    <flux:text class="text-xs text-zinc-400 mt-1">{{ $task->description }}</flux:text>
                                                                @endif
                                                            </div>

                                                            @if ($task->estimated_hours)
                                                                <flux:badge size="sm">
                                                                    {{ number_format($task->estimated_hours, 1) }}h
                                                                </flux:badge>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>

                                {{-- Per-Service Approval Button --}}
                                @if (! $isCustomerApproved)
                                    <div class="p-4 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 flex justify-end">
                                        <flux:button wire:click="approveServiceSection({{ $category->id }})" variant="primary" size="sm" icon="check">
                                            {{ __('Approve :name Section', ['name' => __($category->name)]) }}
                                        </flux:button>
                                    </div>
                                @else
                                    <div class="p-4 border-t border-zinc-200 dark:border-zinc-700 bg-green-50 dark:bg-green-900/20 flex items-center gap-2">
                                        <flux:icon name="check-circle" class="w-5 h-5 text-green-500" />
                                        <flux:text class="text-sm text-green-700 dark:text-green-400">
                                            {{ __('You approved this section') }}{{ $customerApproval?->approved_at ? ' ' . $customerApproval->approved_at->diffForHumans() : '' }}
                                        </flux:text>
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        {{-- Hidden services message --}}
                        @if ($hasHiddenCategories)
                            <flux:callout variant="info">
                                {{ __('Additional service details will appear once confirmed by the team.') }}
                            </flux:callout>
                        @endif
                    @else
                        {{-- Legacy view: no service-based approvals, show all tasks --}}
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden" x-data="{ activeDay: 1 }">
                            {{-- Day Tab Bar --}}
                            <div class="flex border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 overflow-x-auto">
                                @foreach ($plan->days as $day)
                                    <button
                                        x-on:click="activeDay = {{ $day->day_number }}"
                                        :class="activeDay === {{ $day->day_number }}
                                            ? 'border-b-2 border-blue-500 text-blue-600 dark:text-blue-400 bg-white dark:bg-zinc-900'
                                            : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                                        class="px-4 py-3 text-sm font-medium whitespace-nowrap transition-colors"
                                        wire:key="customer-day-tab-{{ $day->id }}"
                                    >
                                        {{ $day->label }}
                                        <flux:badge size="sm" class="ml-1">{{ $day->tasks->count() }}</flux:badge>
                                    </button>
                                @endforeach
                            </div>

                            {{-- Day Content --}}
                            @foreach ($plan->days as $day)
                                <div x-show="activeDay === {{ $day->day_number }}" x-cloak class="p-4" wire:key="customer-day-content-{{ $day->id }}">
                                    @if ($day->tasks->count() > 0)
                                        <div class="space-y-2">
                                            @foreach ($day->tasks as $task)
                                                <div class="flex items-start gap-3 p-3 rounded-lg border border-zinc-200 dark:border-zinc-700" wire:key="customer-task-{{ $task->id }}">
                                                    @if ($task->task_type === 'surface')
                                                        <div class="w-2 h-8 rounded-full bg-blue-400 shrink-0 mt-0.5"></div>
                                                    @elseif ($task->task_type === 'addon')
                                                        <div class="w-2 h-8 rounded-full bg-purple-400 shrink-0 mt-0.5"></div>
                                                    @else
                                                        <div class="w-2 h-8 rounded-full bg-zinc-400 shrink-0 mt-0.5"></div>
                                                    @endif

                                                    <div class="flex-1 min-w-0">
                                                        <flux:text class="font-medium text-sm truncate">{{ $task->title }}</flux:text>
                                                        @if ($task->area_name)
                                                            <flux:text class="text-xs text-zinc-500">{{ $task->area_name }}</flux:text>
                                                        @endif
                                                        @if ($task->description)
                                                            <flux:text class="text-xs text-zinc-400 mt-1">{{ $task->description }}</flux:text>
                                                        @endif
                                                    </div>

                                                    @if ($task->estimated_hours)
                                                        <flux:badge size="sm">
                                                            {{ number_format($task->estimated_hours, 1) }}h
                                                        </flux:badge>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <div class="text-center py-6">
                                            <flux:text class="text-sm text-zinc-500">{{ __('No tasks assigned to this day.') }}</flux:text>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        {{-- Legacy Tri-Party Approval --}}
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                            <flux:heading size="sm" class="mb-4">{{ __('Plan Approval') }}</flux:heading>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div class="p-4 rounded-lg border {{ $plan->team_approved ? 'border-green-300 dark:border-green-700 bg-green-50 dark:bg-green-900/20' : 'border-zinc-200 dark:border-zinc-700' }}">
                                    <div class="flex items-center gap-2 mb-2">
                                        @if ($plan->team_approved)
                                            <flux:icon name="check-circle" class="w-5 h-5 text-green-500" />
                                        @else
                                            <flux:icon name="clock" class="w-5 h-5 text-zinc-400" />
                                        @endif
                                        <flux:text class="font-medium text-sm">{{ __('Team') }}</flux:text>
                                    </div>
                                    <flux:text class="text-xs text-zinc-500">
                                        @if ($plan->team_approved)
                                            {{ __('Approved') }} {{ $plan->team_approved_at?->diffForHumans() }}
                                        @else
                                            {{ __('Pending approval') }}
                                        @endif
                                    </flux:text>
                                </div>

                                <div class="p-4 rounded-lg border {{ $plan->crew_approved ? 'border-green-300 dark:border-green-700 bg-green-50 dark:bg-green-900/20' : 'border-zinc-200 dark:border-zinc-700' }}">
                                    <div class="flex items-center gap-2 mb-2">
                                        @if ($plan->crew_approved)
                                            <flux:icon name="check-circle" class="w-5 h-5 text-green-500" />
                                        @else
                                            <flux:icon name="clock" class="w-5 h-5 text-zinc-400" />
                                        @endif
                                        <flux:text class="font-medium text-sm">{{ __('Crew') }}</flux:text>
                                    </div>
                                    <flux:text class="text-xs text-zinc-500">
                                        @if ($plan->crew_approved)
                                            {{ __('Approved') }} {{ $plan->crew_approved_at?->diffForHumans() }}
                                        @else
                                            {{ __('Pending approval') }}
                                        @endif
                                    </flux:text>
                                </div>

                                <div class="p-4 rounded-lg border {{ $plan->customer_approved ? 'border-green-300 dark:border-green-700 bg-green-50 dark:bg-green-900/20' : 'border-zinc-200 dark:border-zinc-700' }}">
                                    <div class="flex items-center gap-2 mb-2">
                                        @if ($plan->customer_approved)
                                            <flux:icon name="check-circle" class="w-5 h-5 text-green-500" />
                                        @else
                                            <flux:icon name="clock" class="w-5 h-5 text-zinc-400" />
                                        @endif
                                        <flux:text class="font-medium text-sm">{{ __('You') }}</flux:text>
                                    </div>
                                    <flux:text class="text-xs text-zinc-500">
                                        @if ($plan->customer_approved)
                                            {{ __('Approved') }} {{ $plan->customer_approved_at?->diffForHumans() }}
                                        @else
                                            {{ __('Awaiting your approval') }}
                                        @endif
                                    </flux:text>
                                </div>
                            </div>

                            @if (! $plan->customer_approved)
                                <div class="mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700 flex justify-end">
                                    <flux:button wire:click="approvePrepPlan" variant="primary" icon="check">
                                        {{ __('Approve Plan') }}
                                    </flux:button>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @else
                {{-- No prep plan yet - show placeholder --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                    <flux:icon name="wrench-screwdriver" class="w-16 h-16 mx-auto text-yellow-500 mb-4" />
                    <flux:heading size="lg">{{ __('Preparing Your Project') }}</flux:heading>
                    <flux:text class="text-zinc-500 mt-2 max-w-md mx-auto">
                        {{ __('Our team is gathering materials and preparing for your painting project. We\'ll let you know when we\'re ready to begin.') }}
                    </flux:text>
                    @if ($job->scheduled_date)
                        <div class="mt-4">
                            <flux:badge size="sm" color="blue">
                                {{ __('Scheduled: :date', ['date' => $job->scheduled_date->format('M j, Y')]) }}
                            </flux:badge>
                        </div>
                    @endif
                </div>
            @endif

        @elseif ($job->stage === 'in_progress')
            {{-- Stage 4: In Progress --}}
            @if ($this->prepPlan && $this->prepProgress)
                @php
                    $plan = $this->prepPlan;
                    $progress = $this->prepProgress;
                @endphp
                <div class="space-y-6">
                    {{-- Progress Header --}}
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <flux:icon name="paint-brush" class="w-6 h-6 text-orange-500" />
                            <div class="flex-1">
                                <flux:heading size="sm">{{ __('Painting In Progress') }}</flux:heading>
                                @if ($job->actual_start_date)
                                    <flux:text class="text-xs text-zinc-500">
                                        {{ __('Started :date', ['date' => $job->actual_start_date->format('M j, Y')]) }}
                                    </flux:text>
                                @endif
                            </div>
                            <flux:text class="text-lg font-bold">{{ $progress['completed'] }}/{{ $progress['total'] }}</flux:text>
                        </div>

                        {{-- Overall Progress Bar --}}
                        <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-3">
                            <div
                                class="h-3 rounded-full transition-all {{ $progress['percent'] >= 100 ? 'bg-green-500' : 'bg-orange-500' }}"
                                style="width: {{ $progress['percent'] }}%"
                            ></div>
                        </div>
                        <flux:text class="text-xs text-zinc-500 mt-1">{{ __(':percent% complete', ['percent' => $progress['percent']]) }}</flux:text>
                    </div>

                    {{-- Day Tabs with Task Status --}}
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden" x-data="{ activeDay: 1 }">
                        {{-- Day Tab Bar --}}
                        <div class="flex border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 overflow-x-auto">
                            @foreach ($plan->days as $day)
                                @php
                                    $dayProgress = collect($progress['per_day'])->firstWhere('day_number', $day->day_number);
                                @endphp
                                <button
                                    x-on:click="activeDay = {{ $day->day_number }}"
                                    :class="activeDay === {{ $day->day_number }}
                                        ? 'border-b-2 border-orange-500 text-orange-600 dark:text-orange-400 bg-white dark:bg-zinc-900'
                                        : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                                    class="px-4 py-3 text-sm font-medium whitespace-nowrap transition-colors"
                                    wire:key="customer-progress-tab-{{ $day->id }}"
                                >
                                    {{ $day->label }}
                                    @if ($dayProgress && $dayProgress['percent'] >= 100)
                                        <flux:icon name="check-circle" class="inline w-4 h-4 text-green-500 ml-1" />
                                    @else
                                        <flux:badge size="sm" class="ml-1">
                                            {{ $dayProgress['completed'] ?? 0 }}/{{ $dayProgress['total'] ?? 0 }}
                                        </flux:badge>
                                    @endif
                                </button>
                            @endforeach
                        </div>

                        {{-- Day Content --}}
                        @foreach ($plan->days as $day)
                            <div x-show="activeDay === {{ $day->day_number }}" x-cloak class="p-4" wire:key="customer-progress-content-{{ $day->id }}">
                                @if ($day->tasks->count() > 0)
                                    <div class="space-y-2">
                                        @foreach ($day->tasks as $task)
                                            @php
                                                $isCompleted = $task->status === 'completed';
                                            @endphp
                                            <div class="flex items-center gap-3 p-3 rounded-lg border {{ $isCompleted ? 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20' : 'border-zinc-200 dark:border-zinc-700' }}" wire:key="customer-progress-task-{{ $task->id }}">
                                                {{-- Completion indicator --}}
                                                @if ($isCompleted)
                                                    <flux:icon name="check-circle" class="w-5 h-5 text-green-500 shrink-0" />
                                                @else
                                                    <div class="w-5 h-5 rounded-full border-2 border-zinc-300 dark:border-zinc-600 shrink-0"></div>
                                                @endif

                                                {{-- Type indicator --}}
                                                @if ($task->task_type === 'surface')
                                                    <div class="w-2 h-8 rounded-full bg-blue-400 shrink-0"></div>
                                                @elseif ($task->task_type === 'addon')
                                                    <div class="w-2 h-8 rounded-full bg-purple-400 shrink-0"></div>
                                                @else
                                                    <div class="w-2 h-8 rounded-full bg-zinc-400 shrink-0"></div>
                                                @endif

                                                <div class="flex-1 min-w-0">
                                                    <flux:text class="font-medium text-sm truncate {{ $isCompleted ? 'line-through text-zinc-400' : '' }}">
                                                        {{ $task->title }}
                                                    </flux:text>
                                                    @if ($task->area_name)
                                                        <flux:text class="text-xs text-zinc-500">{{ $task->area_name }}</flux:text>
                                                    @endif
                                                </div>

                                                @if ($task->estimated_hours)
                                                    <flux:badge size="sm" :color="$isCompleted ? 'green' : 'zinc'">
                                                        {{ number_format($task->estimated_hours, 1) }}h
                                                    </flux:badge>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="text-center py-6">
                                        <flux:text class="text-sm text-zinc-500">No tasks assigned to this day.</flux:text>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                {{-- Fallback: no prep plan --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                    <flux:icon name="paint-brush" class="w-16 h-16 mx-auto text-orange-500 mb-4" />
                    <flux:heading size="lg">{{ __('Painting In Progress') }}</flux:heading>
                    <flux:text class="text-zinc-500 mt-2 max-w-md mx-auto">
                        {{ __('Your painting project is underway! Our crew is on site working on your project.') }}
                    </flux:text>
                    @if ($job->actual_start_date)
                        <div class="mt-4">
                            <flux:badge size="sm" color="orange">
                                {{ __('Started: :date', ['date' => $job->actual_start_date->format('M j, Y')]) }}
                            </flux:badge>
                        </div>
                    @endif
                </div>
            @endif

        @elseif ($job->stage === 'completed')
            {{-- Stage 5: Completed --}}
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="check-circle" class="w-16 h-16 mx-auto text-green-500 mb-4" />
                <flux:heading size="lg">{{ __('Project Completed!') }}</flux:heading>
                <flux:text class="text-zinc-500 mt-2 max-w-md mx-auto">
                    {{ __('Your painting project is complete. Thank you for choosing us!') }}
                </flux:text>
                @if ($job->actual_end_date)
                    <div class="mt-4">
                        <flux:badge size="sm" color="green">
                            {{ __('Completed: :date', ['date' => $job->actual_end_date->format('M j, Y')]) }}
                        </flux:badge>
                    </div>
                @endif
                <div class="mt-4 flex items-center justify-center gap-3">
                    @if ($job->estimate)
                        <flux:button :href="route('customer.estimates.show', $job->estimate)" variant="ghost" icon="document-text" wire:navigate>
                            {{ __('View Estimate') }}
                        </flux:button>
                    @endif
                    @php
                        $finalInvoice = $job->invoices()->where('type', 'final')->latest()->first();
                    @endphp
                    @if ($finalInvoice)
                        <flux:button :href="route('customer.invoices.show', $finalInvoice)" variant="ghost" icon="banknotes" wire:navigate>
                            {{ __('View Final Invoice') }}
                        </flux:button>
                    @endif
                </div>
            </div>
        @endif

        {{-- Color Summary (always visible when colors exist, except on scheduling stage) --}}
        @if ($job->stage !== 'scheduling_colors' && $job->colors->count() > 0)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mt-6">
                <flux:heading size="sm" class="mb-4">{{ __('Selected Colors') }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
                    @foreach ($job->colors as $color)
                        <div class="flex items-center gap-3 p-3 rounded-lg border border-zinc-200 dark:border-zinc-700" wire:key="summary-color-{{ $color->id }}">
                            @if ($color->hex_value)
                                <div class="w-8 h-8 rounded border border-zinc-300" style="background-color: {{ $color->hex_value }}"></div>
                            @endif
                            <div>
                                <flux:text class="font-medium text-sm">{{ $color->name }}</flux:text>
                                @if ($color->brand)
                                    <flux:text class="text-xs text-zinc-500">{{ $color->brand }}</flux:text>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Media (Photos & Videos) --}}
        <livewire:shared.media-section :property="$job->deal?->property" :job="$job" context="job-customer" :readOnly="true" :key="'media-'.$job->id" />

        {{-- Project Conversation (visible on all stages) --}}
        @if ($this->chatThread)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg mt-6 overflow-hidden">
                {{-- Chat Header --}}
                <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="chat-bubble-left-right" class="w-5 h-5 text-zinc-500" />
                        <flux:heading size="sm">{{ __('Project Conversation') }}</flux:heading>
                    </div>
                    <flux:text class="text-xs text-zinc-400 mt-1">{{ __('Discuss scheduling, colors, or any questions about your project.') }}</flux:text>
                </div>

                {{-- Messages --}}
                <div class="max-h-80 overflow-y-auto p-4 space-y-3 bg-white dark:bg-zinc-900" x-data x-init="$el.scrollTop = $el.scrollHeight">
                    @forelse ($this->chatMessages as $message)
                        @php
                            $isOwnMessage = $message->user_id === auth('customer')->id();
                            $isSystem = $message->type === 'system';
                        @endphp

                        <div wire:key="msg-{{ $message->id }}" class="{{ $isOwnMessage ? 'flex justify-end' : 'flex justify-start' }}">
                            @if ($isSystem)
                                <div class="w-full text-center py-1">
                                    <flux:text class="text-xs text-zinc-400 italic">{{ $message->content }}</flux:text>
                                </div>
                            @else
                                <div class="flex items-start gap-2 max-w-[80%] {{ $isOwnMessage ? 'flex-row-reverse' : '' }}">
                                    @if ($message->user)
                                        <flux:avatar size="xs" :initials="$message->user->initials()" class="bg-zinc-200 dark:bg-zinc-700 shrink-0" />
                                    @else
                                        <div class="w-6 h-6 rounded-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center shrink-0">
                                            <flux:icon name="user" class="w-3 h-3 text-zinc-500" />
                                        </div>
                                    @endif

                                    <div class="{{ $isOwnMessage ? 'text-right' : 'text-left' }}">
                                        <div class="flex items-center gap-1 mb-0.5 {{ $isOwnMessage ? 'flex-row-reverse' : '' }}">
                                            <flux:text class="text-xs font-medium text-zinc-500">{{ $message->user?->name ?? __('Unknown') }}</flux:text>
                                            <flux:text class="text-xs text-zinc-400">{{ $message->created_at->diffForHumans() }}</flux:text>
                                        </div>
                                        <div class="rounded-lg px-3 py-2 text-sm {{ $isOwnMessage ? 'bg-blue-50 text-zinc-800 dark:bg-blue-600 dark:text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100' }}">
                                            @if ($message->isAudio())
                                                <div class="flex items-center gap-2">
                                                    <flux:icon name="microphone" class="w-4 h-4 {{ $isOwnMessage ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-400' }}" />
                                                    <audio controls preload="metadata" class="h-8 max-w-[200px]">
                                                        <source src="{{ $message->metadata['audio_url'] ?? '' }}">
                                                    </audio>
                                                </div>
                                                @if (! empty($message->metadata['transcription']))
                                                    <flux:text class="text-xs italic mt-1 {{ $isOwnMessage ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500' }}">
                                                        "{{ $message->metadata['transcription'] }}"
                                                    </flux:text>
                                                    {{-- Audio transcription translation toggle --}}
                                                    @if ($message->needsTranslationFor(app()->getLocale()))
                                                        <div x-data="{ showTranslation: false }" class="mt-1">
                                                            <button
                                                                @@click="showTranslation = !showTranslation"
                                                                class="text-xs underline opacity-70 hover:opacity-100 transition-opacity {{ $isOwnMessage ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500 dark:text-zinc-400' }}"
                                                            >
                                                                <span x-show="!showTranslation">{{ __('See translation') }}</span>
                                                                <span x-show="showTranslation" x-cloak>{{ __('Show original') }}</span>
                                                            </button>
                                                            <div x-show="showTranslation" x-cloak class="mt-1">
                                                                <flux:text class="text-xs italic {{ $isOwnMessage ? 'text-blue-400 dark:text-blue-200' : 'text-zinc-500' }}">
                                                                    "{{ $message->getTranslation(app()->getLocale()) }}"
                                                                </flux:text>
                                                                <span class="text-xs opacity-60">{{ __('Translated from :lang', ['lang' => strtoupper($message->getDetectedLanguage())]) }}</span>
                                                            </div>
                                                        </div>
                                                    @endif
                                                @endif
                                            @else
                                                <flux:text class="whitespace-pre-wrap break-words">{{ $message->content }}</flux:text>

                                                {{-- Translation toggle --}}
                                                @if ($message->needsTranslationFor(app()->getLocale()))
                                                    <div x-data="{ showTranslation: false }" class="mt-1">
                                                        <button
                                                            @@click="showTranslation = !showTranslation"
                                                            class="text-xs underline opacity-70 hover:opacity-100 transition-opacity"
                                                        >
                                                            <span x-show="!showTranslation">{{ __('See translation') }}</span>
                                                            <span x-show="showTranslation" x-cloak>{{ __('Show original') }}</span>
                                                        </button>
                                                        <div x-show="showTranslation" x-cloak class="mt-1">
                                                            <flux:text class="whitespace-pre-wrap break-words">{{ $message->getTranslation(app()->getLocale()) }}</flux:text>
                                                            <span class="text-xs opacity-60">{{ __('Translated from :lang', ['lang' => strtoupper($message->getDetectedLanguage())]) }}</span>
                                                        </div>
                                                    </div>
                                                @endif
                                            @endif
                                        </div>

                                        @if ($message->attachments->isNotEmpty())
                                            <div class="mt-1 space-y-1">
                                                @foreach ($message->attachments as $attachment)
                                                    <div class="flex items-center gap-1 text-xs {{ $isOwnMessage ? 'justify-end' : 'justify-start' }}">
                                                        <flux:icon name="paper-clip" class="w-3 h-3 text-zinc-400" />
                                                        <flux:text class="text-zinc-500">{{ $attachment->original_filename }}</flux:text>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-center py-6">
                            <flux:icon name="chat-bubble-left" class="mx-auto h-8 w-8 text-zinc-300 dark:text-zinc-600 mb-2" />
                            <flux:text class="text-sm text-zinc-500">{{ __('No messages yet. Send a message to discuss your project.') }}</flux:text>
                        </div>
                    @endforelse
                </div>

                {{-- Message Composer --}}
                <div class="border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-3">
                    <livewire:customer.chat.message-composer :thread="$this->chatThread" :key="'job-composer-'.$this->chatThread->id" />
                </div>
            </div>
        @endif

        {{-- Activity Feed --}}
        @if ($activityItems->isNotEmpty())
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg mt-6 overflow-hidden" x-data="{ open: false }">
                <button
                    x-on:click="open = !open"
                    type="button"
                    class="w-full flex items-center justify-between p-4 bg-zinc-50 dark:bg-zinc-800 hover:bg-zinc-100 dark:hover:bg-zinc-700/50 transition-colors text-left"
                >
                    <div class="flex items-center gap-2">
                        <flux:icon name="clock" class="w-5 h-5 text-zinc-500" />
                        <flux:heading size="sm">{{ __('Activity Timeline') }}</flux:heading>
                        <flux:badge size="sm">{{ $activityItems->count() }}{{ $hasMoreActivities ? '+' : '' }}</flux:badge>
                    </div>
                    <flux:icon name="chevron-down" class="w-5 h-5 text-zinc-400 transition-transform" x-bind:class="open && 'rotate-180'" />
                </button>

                <div x-show="open" x-collapse x-cloak class="border-t border-zinc-200 dark:border-zinc-700">
                    <div
                        class="max-h-96 overflow-y-auto p-4"
                        x-data
                        x-on:scroll.debounce.150ms="
                            if ($el.scrollTop + $el.clientHeight >= $el.scrollHeight - 50) {
                                $wire.loadMoreActivities()
                            }
                        "
                    >
                        <div class="relative">
                            {{-- Timeline line --}}
                            <div class="absolute left-4 top-0 bottom-0 w-px bg-zinc-200 dark:bg-zinc-700"></div>

                            <div class="space-y-0">
                                @foreach ($activityItems as $activity)
                                    <div class="relative flex gap-4 pb-4" wire:key="activity-{{ $activity->id }}">
                                        {{-- Timeline dot --}}
                                        <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 shrink-0">
                                            <flux:icon
                                                :name="$this->activityIcon($activity->activity_type)"
                                                class="w-4 h-4 {{ $this->activityColor($activity->activity_type) }}"
                                            />
                                        </div>

                                        {{-- Content --}}
                                        <div class="flex-1 min-w-0 pt-1">
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="min-w-0">
                                                    <flux:text class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                                        {{ $activity->activity_type_label }}
                                                    </flux:text>
                                                    @if ($activity->description)
                                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                                                            {{ $activity->description }}
                                                        </flux:text>
                                                    @endif
                                                </div>
                                                <flux:text class="text-xs text-zinc-400 whitespace-nowrap shrink-0">
                                                    {{ $activity->created_at->diffForHumans() }}
                                                </flux:text>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            {{-- Load more indicator --}}
                            @if ($hasMoreActivities)
                                <div class="flex justify-center py-3" wire:loading.remove wire:target="loadMoreActivities">
                                    <flux:button wire:click="loadMoreActivities" variant="ghost" size="sm">
                                        {{ __('Load more') }}
                                    </flux:button>
                                </div>
                                <div class="flex justify-center py-3" wire:loading wire:target="loadMoreActivities">
                                    <svg class="animate-spin h-5 w-5 text-zinc-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Video Call Recordings --}}
        <livewire:video-call.recording-player :job="$job" :key="'recordings-'.$job->id" />
    </div>

    {{-- Add Color Modal --}}
    <flux:modal wire:model="showColorModal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Choose a Color') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Color Name') }}</flux:label>
                <flux:input wire:model="newColorName" placeholder="e.g. Simply White" />
                <flux:error name="newColorName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Brand (optional)') }}</flux:label>
                <flux:input wire:model="newColorBrand" placeholder="e.g. Benjamin Moore" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Color Code (optional)') }}</flux:label>
                <flux:input wire:model="newColorCode" placeholder="e.g. OC-17" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Preview Color (optional)') }}</flux:label>
                <flux:input type="color" wire:model="newColorHex" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showColorModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="addColor" variant="primary">{{ __('Add Color') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
