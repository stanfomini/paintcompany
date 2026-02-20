<?php

use App\Models\ChatThread;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\ChatService;
use App\Services\InvoiceService;
use App\Services\JobDispatchService;
use App\Services\JobService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('jobs.view')) {
            abort(403);
        }
    }

    public Job $job;

    public string $viewingStage = '';

    public function mount(Job $job): void
    {
        $this->job = $job->load([
            'deal.customer',
            'deal.property',
            'deal.chatThread',
            'estimate',
            'colors.selectedBy',
            'crews.crewProfile',
            'acceptedCrew',
            'serviceRequirements.serviceCategory',
            'salesPerson',
            'productionManager',
        ]);

        $this->viewingStage = $this->job->stage;

        $this->ensureChatParticipant();
    }

    /**
     * Switch the visible stage panel without changing the job's actual stage.
     */
    public function viewStage(string $stage): void
    {
        if (! array_key_exists($stage, Job::STAGES)) {
            return;
        }

        // Only allow viewing completed or current stages, not future ones
        $stageNum = Job::STAGE_ORDER[$stage];
        $currentNum = $this->job->stage_number;

        if ($stageNum > $currentNum) {
            return;
        }

        $this->viewingStage = $stage;
    }

    public function getListeners(): array
    {
        return [
            "echo-private:job.{$this->job->id},stage.changed" => 'onStageChanged',
            "echo-private:job.{$this->job->id},date.updated" => 'onDateUpdated',
            "echo-private:job.{$this->job->id},color.updated" => 'onColorUpdated',
            "echo-private:job.{$this->job->id},crew.dispatched" => 'onCrewDispatched',
            "echo-private:job.{$this->job->id},job.accepted" => 'onJobAccepted',
            "echo-private:job.{$this->job->id},prep.plan.updated" => 'onPrepPlanUpdated',
            "echo-private:job.{$this->job->id},prep.approval.changed" => 'onPrepApprovalChanged',
            "echo-private:job.{$this->job->id},photo.updated" => 'onPhotoUpdated',
            "echo-private:job.{$this->job->id},job.notification" => 'onJobNotification',
            'job-updated' => 'refreshJob',
            'color-updated' => 'refreshJob',
            'crew-dispatched' => 'refreshJob',
            'message-sent' => 'onMessageSent',
        ];
    }

    private function ensureChatParticipant(): void
    {
        $user = Auth::user();
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
    public function jobActivities()
    {
        return $this->job->jobActivities()
            ->with('user')
            ->latest()
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function invoices()
    {
        return $this->job->invoices()
            ->with('payments')
            ->latest()
            ->get();
    }

    #[Computed]
    public function internalUsers()
    {
        return User::where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function assignProductionManager(?int $userId): void
    {
        abort_unless(auth()->user()->can('jobs.edit'), 403);

        $this->job->update(['assigned_to' => $userId ?: null]);
        $this->job->load('productionManager');
    }

    #[Computed]
    public function crewsByCategory(): array
    {
        $grouped = [];
        $categories = [];

        foreach ($this->job->crews as $crew) {
            $categoryId = $crew->pivot->service_category_id;
            $key = $categoryId ?? 0;

            if (! isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $crew;

            if ($categoryId && ! isset($categories[$key])) {
                $categories[$key] = ServiceCategory::find($categoryId);
            }
        }

        $result = [];
        foreach ($grouped as $key => $crews) {
            $category = $categories[$key] ?? null;
            $result[] = [
                'category' => $category,
                'label' => $category ? __($category->name) . ' ' . __('Crew') : __('General'),
                'icon' => $category->icon ?? 'users',
                'color' => $category->color ?? 'zinc',
                'crews' => $crews,
            ];
        }

        return $result;
    }

    public function createDepositInvoice(): void
    {
        abort_unless(auth()->user()->can('invoices.create'), 403);

        if ($this->invoices->where('type', Invoice::TYPE_DEPOSIT)->isNotEmpty()) {
            session()->flash('error', __('A deposit invoice already exists for this job.'));
            return;
        }

        $service = app(InvoiceService::class);
        $service->createDepositInvoice($this->job->estimate, $this->job);

        unset($this->invoices);
        session()->flash('success', __('Deposit invoice created.'));
    }

    public function createFinalInvoice(): void
    {
        abort_unless(auth()->user()->can('invoices.create'), 403);

        if ($this->invoices->where('type', Invoice::TYPE_FINAL)->isNotEmpty()) {
            session()->flash('error', __('A final invoice already exists for this job.'));
            return;
        }

        $service = app(InvoiceService::class);
        $service->createFinalInvoice($this->job);

        unset($this->invoices);
        session()->flash('success', __('Final invoice created.'));
    }

    public function sendInvoice(int $invoiceId): void
    {
        abort_unless(auth()->user()->can('invoices.send'), 403);

        $invoice = Invoice::findOrFail($invoiceId);
        app(InvoiceService::class)->sendInvoice($invoice);

        unset($this->invoices);
        session()->flash('success', __('Invoice :number sent to customer.', ['number' => $invoice->invoice_number]));
    }

    public function refreshJob(): void
    {
        $this->job = $this->job->fresh([
            'deal.customer',
            'deal.property',
            'deal.chatThread',
            'estimate',
            'colors.selectedBy',
            'crews.crewProfile',
            'acceptedCrew',
            'serviceRequirements.serviceCategory',
            'salesPerson',
            'productionManager',
        ]);

        unset($this->jobActivities, $this->invoices, $this->crewsByCategory);
    }

    public function onStageChanged(): void
    {
        $this->refreshJob();
    }

    public function onDateUpdated(): void
    {
        $this->refreshJob();
    }

    public function onColorUpdated(): void
    {
        $this->refreshJob();
    }

    public function onCrewDispatched(): void
    {
        $this->refreshJob();
    }

    public function onJobAccepted(): void
    {
        $this->refreshJob();
    }

    public function onPrepPlanUpdated(): void
    {
        $this->refreshJob();
    }

    public function onPrepApprovalChanged(): void
    {
        $this->refreshJob();
    }

    public function onPhotoUpdated(): void
    {
        $this->refreshJob();
    }

    public function onJobNotification($data): void
    {
        $this->refreshJob();
        $message = $data['message'] ?? 'Job updated.';
        session()->flash('success', $message);
    }

    public function onMessageSent(): void
    {
        $thread = $this->chatThread;
        if ($thread) {
            app(ChatService::class)->markThreadAsRead($thread, Auth::user());
        }
    }

    /**
     * Advance the job to the next stage.
     */
    public function advanceStage(): void
    {
        abort_unless(auth()->user()->can('jobs.edit'), 403);

        $service = app(JobService::class);

        if ($this->job->stage === 'scheduling_colors' && ! $this->job->isReadyForDispatch()) {
            session()->flash('error', __('All confirmations must be completed before advancing.'));

            return;
        }

        if ($this->job->stage === 'job_prep' && ! $this->job->isPrepApproved()) {
            session()->flash('error', __('All three parties must approve the prep plan before advancing.'));

            return;
        }

        if ($service->advanceStage($this->job)) {
            $this->refreshJob();
            $this->viewingStage = $this->job->stage;
            session()->flash('success', __('Job advanced to :stage', ['stage' => $this->job->stage_label]));
        }
    }

    /**
     * Remove a crew from the job entirely.
     */
    public function removeCrew(int $crewUserId, ?int $serviceCategoryId = null): void
    {
        abort_unless(auth()->user()->can('jobs.edit'), 403);

        $crew = User::findOrFail($crewUserId);
        $category = $serviceCategoryId ? ServiceCategory::find($serviceCategoryId) : null;

        $removed = app(JobDispatchService::class)->removeCrew($this->job, $crew, $category);

        $this->refreshJob();

        if ($removed) {
            session()->flash('success', __(':crew removed from job.', ['crew' => $crew->name]));
        } else {
            session()->flash('error', __('Crew not found on this job.'));
        }
    }

    #[Computed]
    public function stageColors(): array
    {
        return [
            'estimate_accepted' => 'blue',
            'scheduling_colors' => 'purple',
            'job_prep' => 'yellow',
            'in_progress' => 'orange',
            'completed' => 'green',
        ];
    }
}; ?>

<div>
    <div class="max-w-5xl mx-auto">
        {{-- Header --}}
        <div class="flex items-center gap-2 sm:gap-3 mb-2 min-w-0">
            <flux:button variant="ghost" :href="route('jobs.index')" icon="arrow-left" wire:navigate class="shrink-0" size="sm">
                <span class="hidden sm:inline">{{ __('Back') }}</span>
            </flux:button>
            <div class="min-w-0">
                <flux:heading size="xl" class="truncate">{{ $job->title }}</flux:heading>
                <flux:text class="text-zinc-500 text-sm">{{ $job->job_number }}</flux:text>
            </div>
            <flux:badge size="sm" color="{{ $this->stageColors[$job->stage] ?? 'zinc' }}" class="shrink-0 whitespace-nowrap">
                {{ $job->stage_label }}
            </flux:badge>
        </div>

        {{-- Actions Bar --}}
        <div class="flex flex-wrap items-center gap-1.5 sm:gap-2 mb-6 sm:pl-12">
            @if (! $job->isCompleted())
                <livewire:video-call.job-call :job="$job" :key="'video-call-'.$job->id" />
            @endif
            @can('financials.view_cost_breakdown')
                <flux:button variant="ghost" :href="route('jobs.cost-card', $job)" icon="calculator" size="sm" wire:navigate>
                    <span class="hidden sm:inline">{{ __('Cost Card') }}</span>
                </flux:button>
            @endcan
            @can('financials.view_painter_breakdown')
                <flux:button variant="ghost" :href="route('jobs.painter-breakdown', $job)" icon="clipboard-document-list" size="sm" wire:navigate>
                    <span class="hidden sm:inline">{{ __("Painter's Breakdown") }}</span>
                </flux:button>
            @endcan
            @can('jobs.edit')
                @if (! $job->isCompleted())
                    <flux:button wire:click="advanceStage" variant="primary" size="sm" icon="arrow-right">
                        <span class="hidden sm:inline">{{ __('Advance Stage') }}</span>
                    </flux:button>
                @endif
            @endcan
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
            <flux:heading size="sm" class="mb-4">{{ __('Progress') }}</flux:heading>
            <div class="flex items-center justify-between">
                @foreach (App\Models\Job::STAGES as $stageKey => $stageLabel)
                    @php
                        $stageNum = App\Models\Job::STAGE_ORDER[$stageKey];
                        $currentNum = $job->stage_number;
                        $isActive = $stageKey === $job->stage;
                        $isCompleted = $stageNum < $currentNum;
                        $isFuture = $stageNum > $currentNum;
                        $isClickable = $isCompleted || $isActive;
                        $isViewing = $stageKey === $viewingStage;
                    @endphp
                    <div
                        class="flex-1 flex flex-col items-center relative {{ $isClickable ? 'cursor-pointer group' : '' }}"
                        wire:key="stage-{{ $stageKey }}"
                        @if ($isClickable)
                            wire:click="viewStage('{{ $stageKey }}')"
                        @endif
                    >
                        {{-- Connector line --}}
                        @if (! $loop->first)
                            <div class="absolute top-4 -left-1/2 w-full h-0.5 {{ $isCompleted || $isActive ? 'bg-green-500' : 'bg-zinc-300 dark:bg-zinc-600' }}"></div>
                        @endif

                        {{-- Stage circle --}}
                        <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full border-2 transition-transform
                            {{ $isCompleted ? 'bg-green-500 border-green-500 text-white' : '' }}
                            {{ $isActive ? 'bg-blue-500 border-blue-500 text-white' : '' }}
                            {{ $isFuture ? 'bg-white dark:bg-zinc-800 border-zinc-300 dark:border-zinc-600 text-zinc-400' : '' }}
                            {{ $isViewing && ! $isActive ? 'ring-2 ring-blue-400 ring-offset-2 dark:ring-offset-zinc-900' : '' }}
                            {{ $isClickable ? 'group-hover:scale-110' : '' }}
                        ">
                            @if ($isCompleted)
                                <flux:icon name="check" class="w-4 h-4" />
                            @else
                                <span class="text-xs font-bold">{{ $stageNum }}</span>
                            @endif
                        </div>

                        {{-- Label --}}
                        <flux:text class="mt-2 text-xs text-center
                            {{ $isActive ? 'font-semibold text-blue-600 dark:text-blue-400' : '' }}
                            {{ $isViewing && ! $isActive ? 'font-semibold text-blue-600 dark:text-blue-400' : '' }}
                            {{ ! $isActive && ! $isViewing ? 'text-zinc-500' : '' }}
                        ">
                            {{ $stageLabel }}
                        </flux:text>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Viewing past stage banner --}}
        @if ($viewingStage !== $job->stage)
            <div class="flex flex-wrap items-center justify-between gap-2 p-3 mb-6 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
                <div class="flex items-center gap-2 min-w-0">
                    <flux:icon name="eye" class="w-4 h-4 text-blue-500 shrink-0" />
                    <flux:text class="text-sm text-blue-700 dark:text-blue-300">
                        {{ __('Viewing:') }} <span class="font-semibold">{{ App\Models\Job::STAGES[$viewingStage] ?? $viewingStage }}</span>
                        <span class="text-blue-500 dark:text-blue-400 mx-1">&middot;</span>
                        {{ __('Current:') }} <span class="font-semibold">{{ $job->stage_label }}</span>
                    </flux:text>
                </div>
                <flux:button wire:click="viewStage('{{ $job->stage }}')" size="sm" variant="ghost" class="shrink-0">
                    {{ __('Back to current') }}
                </flux:button>
            </div>
        @endif

        {{-- Job Info Cards --}}
        <div class="grid gap-6 md:grid-cols-2 mb-6">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">{{ __('Job Information') }}</flux:heading>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Customer') }}</dt>
                        <dd class="mt-1">
                            @if ($job->deal?->customer)
                                <a href="{{ route('customers.show', $job->deal->customer) }}" class="text-blue-600 hover:underline" wire:navigate>
                                    {{ $job->deal->customer->full_name }}
                                </a>
                            @else
                                <flux:text>-</flux:text>
                            @endif
                        </dd>
                    </div>
                    @if ($job->deal?->property)
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Property') }}</dt>
                            <dd class="mt-1">
                                <a href="{{ route('properties.show', $job->deal->property) }}" class="text-blue-600 hover:underline" wire:navigate>
                                    {{ $job->deal->property->display_name }}
                                </a>
                            </dd>
                        </div>
                    @endif
                    @if ($job->estimate)
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Estimate') }}</dt>
                            <dd class="mt-1">
                                <a href="{{ route('estimates.show', $job->estimate) }}" class="text-blue-600 hover:underline" wire:navigate>
                                    {{ $job->estimate->estimate_number }}
                                </a>
                            </dd>
                        </div>
                    @endif
                    @if ($job->description)
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Description') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $job->description }}</flux:text>
                            </dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Required Crew Size') }}</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $job->required_crew_size }} {{ Str::plural('person', $job->required_crew_size) }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Sales Person') }}</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $job->salesPerson?->name ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Production Manager') }}</dt>
                        <dd class="mt-1">
                            @can('jobs.edit')
                                <flux:select wire:change="assignProductionManager($event.target.value)" class="w-full">
                                    <option value="">{{ __('Unassigned') }}</option>
                                    @foreach ($this->internalUsers as $user)
                                        <option value="{{ $user->id }}" @selected($job->assigned_to === $user->id)>
                                            {{ $user->name }}
                                        </option>
                                    @endforeach
                                </flux:select>
                            @else
                                <flux:text>{{ $job->productionManager?->name ?? __('Unassigned') }}</flux:text>
                            @endcan
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">{{ __('Schedule & Confirmations') }}</flux:heading>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Scheduled Date') }}</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $job->scheduled_date?->format('M j, Y') ?? __('Not set') }}</flux:text>
                        </dd>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Company Date') }}</dt>
                            <dd class="mt-1">
                                @if ($job->company_date_confirmed)
                                    <flux:badge size="sm" color="green">{{ __('Confirmed') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Pending') }}</flux:badge>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Customer Date') }}</dt>
                            <dd class="mt-1">
                                @if ($job->customer_date_confirmed)
                                    <flux:badge size="sm" color="green">{{ __('Confirmed') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Pending') }}</flux:badge>
                                @endif
                            </dd>
                        </div>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Allowed Colors') }}</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $job->allowed_colors ?: __('Not set') }}</flux:text>
                        </dd>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Company Colors') }}</dt>
                            <dd class="mt-1">
                                @if ($job->company_colors_confirmed)
                                    <flux:badge size="sm" color="green">{{ __('Confirmed') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Pending') }}</flux:badge>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Customer Colors') }}</dt>
                            <dd class="mt-1">
                                @if ($job->customer_colors_confirmed)
                                    <flux:badge size="sm" color="green">{{ __('Confirmed') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Pending') }}</flux:badge>
                                @endif
                            </dd>
                        </div>
                    </div>
                </dl>
            </div>
        </div>

        {{-- Stage-specific content --}}
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
            @if ($viewingStage === 'estimate_accepted')
                <flux:heading size="sm" class="mb-4">{{ __('Stage 1: Estimate Accepted') }}</flux:heading>
                <div class="text-center py-8">
                    <flux:icon name="check-circle" class="w-16 h-16 mx-auto text-green-500 mb-4" />
                    <flux:heading size="lg">{{ __('Estimate Signed') }}</flux:heading>
                    <flux:text class="text-zinc-500 mt-2">
                        {{ __('The customer has signed and accepted the estimate. Advance to the next stage to begin scheduling and color selection.') }}
                    </flux:text>
                    @can('jobs.edit')
                        @if ($job->stage === 'estimate_accepted')
                            <div class="mt-4">
                                <flux:button wire:click="advanceStage" variant="primary" icon="arrow-right">
                                    {{ __('Begin Scheduling & Colors') }}
                                </flux:button>
                            </div>
                        @endif
                    @endcan
                </div>
            @elseif ($viewingStage === 'scheduling_colors')
                @livewire('jobs.stage-scheduling', ['job' => $job], key('stage-scheduling-' . $job->id))
            @elseif ($viewingStage === 'job_prep')
                @livewire('jobs.stage-prep', ['job' => $job], key('stage-prep-' . $job->id))
            @elseif ($viewingStage === 'in_progress')
                @livewire('jobs.stage-in-progress', ['job' => $job], key('stage-in-progress-' . $job->id))
            @elseif ($viewingStage === 'completed')
                @livewire('jobs.stage-completed', ['job' => $job], key('stage-completed-' . $job->id))
            @endif
        </div>

        {{-- Colors Section --}}
        @if ($job->colors->count() > 0)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <flux:heading size="sm" class="mb-4">{{ __('Selected Colors') }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
                    @foreach ($job->colors as $color)
                        <div class="flex items-center gap-3 p-3 rounded-lg border border-zinc-200 dark:border-zinc-700" wire:key="color-{{ $color->id }}">
                            @if ($color->hex_value)
                                <div class="w-10 h-10 rounded-lg border border-zinc-300 dark:border-zinc-600" style="background-color: {{ $color->hex_value }}"></div>
                            @endif
                            <div>
                                <flux:text class="font-medium text-sm">{{ $color->name }}</flux:text>
                                @if ($color->brand)
                                    <flux:text class="text-xs text-zinc-500">{{ $color->brand }}</flux:text>
                                @endif
                                @if ($color->code)
                                    <flux:text class="text-xs text-zinc-400">{{ $color->code }}</flux:text>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Service Requirements Summary --}}
        @if ($job->serviceRequirements->isNotEmpty())
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <flux:heading size="sm" class="mb-4">{{ __('Service Status') }}</flux:heading>
                <div class="flex flex-wrap gap-3">
                    @foreach ($job->serviceRequirements as $req)
                        @php
                            $reqColor = match ($req->status) {
                                'accepted' => 'green',
                                'dispatched' => 'yellow',
                                'completed' => 'green',
                                default => 'zinc',
                            };
                            $reqIcon = match ($req->status) {
                                'accepted' => 'check-circle',
                                'dispatched' => 'clock',
                                'completed' => 'check-badge',
                                default => 'ellipsis-horizontal-circle',
                            };
                        @endphp
                        <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-zinc-50 dark:bg-zinc-800" wire:key="req-{{ $req->id }}">
                            <flux:icon name="{{ $reqIcon }}" class="w-4 h-4 text-{{ $reqColor }}-500" />
                            <flux:text class="text-sm font-medium">{{ __($req->serviceCategory?->name ?? 'Unknown') }}:</flux:text>
                            <flux:badge size="sm" color="{{ $reqColor }}">{{ ucfirst($req->status) }}</flux:badge>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Crew Section (grouped by service category) --}}
        @if ($job->crews->count() > 0)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <flux:heading size="sm" class="mb-4">{{ __('Assigned Crews') }}</flux:heading>
                <div class="space-y-5">
                    @foreach ($this->crewsByCategory as $group)
                        <div>
                            <div class="flex items-center gap-2 mb-3">
                                <flux:icon name="{{ $group['icon'] }}" class="w-4 h-4 text-{{ $group['color'] }}-500" />
                                <flux:text class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $group['label'] }}</flux:text>
                            </div>
                            <div class="space-y-2">
                                @foreach ($group['crews'] as $crew)
                                    <div class="flex items-center justify-between p-3 rounded-lg border border-zinc-200 dark:border-zinc-700" wire:key="crew-{{ $crew->id }}-{{ $crew->pivot->service_category_id }}">
                                        <div class="flex items-center gap-3">
                                            <flux:avatar size="sm" name="{{ $crew->name }}" />
                                            <div>
                                                <flux:text class="font-medium text-sm">{{ $crew->name }}</flux:text>
                                                @if ($crew->crewProfile)
                                                    <flux:text class="text-xs text-zinc-500">
                                                        {{ __('Crew of') }} {{ $crew->crewProfile->crew_size }}
                                                    </flux:text>
                                                @endif
                                            </div>
                                        </div>
                                        @php
                                            $statusColor = match ($crew->pivot->status) {
                                                'accepted' => 'green',
                                                'rejected' => 'red',
                                                default => 'yellow',
                                            };
                                        @endphp
                                        <div class="flex items-center gap-2">
                                            <flux:badge size="sm" color="{{ $statusColor }}">{{ ucfirst($crew->pivot->status) }}</flux:badge>
                                            @can('jobs.edit')
                                                <flux:button
                                                    wire:click="removeCrew({{ $crew->id }}, {{ $crew->pivot->service_category_id ?? 'null' }})"
                                                    wire:confirm="{{ __('Remove :name from this job? This will permanently unlink them.', ['name' => $crew->name]) }}"
                                                    size="xs"
                                                    variant="ghost"
                                                    icon="x-mark"
                                                />
                                            @endcan
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Media Section (Photos & Videos) --}}
        <livewire:shared.media-section :property="$job->deal?->property" :job="$job" context="job-internal" :key="'media-'.$job->id" />

        {{-- Invoices Section --}}
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="sm">{{ __('Invoices') }}</flux:heading>
                @can('invoices.create')
                    <div class="flex gap-2">
                        @if ($this->invoices->where('type', 'deposit')->isEmpty() && $job->estimate)
                            <flux:button wire:click="createDepositInvoice" size="sm" variant="ghost" icon="plus">
                                {{ __('Deposit Invoice') }}
                            </flux:button>
                        @endif
                        @if ($job->stage === 'completed' && $this->invoices->where('type', 'final')->isEmpty())
                            <flux:button wire:click="createFinalInvoice" size="sm" variant="ghost" icon="plus">
                                {{ __('Final Invoice') }}
                            </flux:button>
                        @endif
                    </div>
                @endcan
            </div>

            @if ($this->invoices->isEmpty())
                <div class="text-center py-6">
                    <flux:icon name="document-text" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                    <flux:text class="text-zinc-500">{{ __('No invoices yet.') }}</flux:text>
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($this->invoices as $invoice)
                        <div class="flex items-center justify-between p-4 rounded-lg border border-zinc-200 dark:border-zinc-700" wire:key="invoice-{{ $invoice->id }}">
                            <div class="flex items-center gap-4">
                                <div>
                                    <div class="flex items-center gap-2">
                                        <flux:text class="font-medium text-sm">{{ $invoice->invoice_number }}</flux:text>
                                        <flux:badge size="sm" color="{{ match($invoice->type) {
                                            'deposit' => 'blue',
                                            'final' => 'green',
                                            'change_order' => 'yellow',
                                            default => 'zinc',
                                        } }}">{{ \App\Models\Invoice::TYPES[$invoice->type] ?? $invoice->type }}</flux:badge>
                                    </div>
                                    <flux:text class="text-xs text-zinc-500 mt-0.5">
                                        {{ __('Due') }} {{ $invoice->due_date?->format('M j, Y') ?? 'N/A' }}
                                        @if ($invoice->sent_at)
                                            &middot; {{ __('Sent') }} {{ $invoice->sent_at->format('M j, Y') }}
                                        @endif
                                    </flux:text>
                                </div>
                            </div>
                            <div class="flex items-center gap-4">
                                <div class="text-right">
                                    <flux:text class="font-semibold text-sm">${{ number_format($invoice->total, 2) }}</flux:text>
                                    @if ((float) $invoice->amount_paid > 0 && ! $invoice->isPaid())
                                        <flux:text class="text-xs text-zinc-500">
                                            {{ __('Paid:') }} ${{ number_format($invoice->amount_paid, 2) }}
                                        </flux:text>
                                    @endif
                                </div>
                                @php
                                    $statusColor = match($invoice->status) {
                                        'paid' => 'green',
                                        'sent' => 'blue',
                                        'partially_paid' => 'yellow',
                                        'overdue' => 'red',
                                        'void' => 'zinc',
                                        default => 'zinc',
                                    };
                                @endphp
                                <flux:badge size="sm" color="{{ $statusColor }}">{{ \App\Models\Invoice::STATUSES[$invoice->status] ?? $invoice->status }}</flux:badge>
                                @can('invoices.send')
                                    @if ($invoice->status === 'draft')
                                        <flux:button wire:click="sendInvoice({{ $invoice->id }})" size="sm" variant="ghost" icon="paper-airplane">
                                            {{ __('Send') }}
                                        </flux:button>
                                    @endif
                                @endcan
                                <flux:button :href="route('invoices.index')" size="sm" variant="ghost" icon="arrow-top-right-on-square" wire:navigate />
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Activity History --}}
        @if ($this->jobActivities->isNotEmpty())
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <flux:heading size="sm" class="mb-4">{{ __('Activity History') }}</flux:heading>
                <div class="space-y-3">
                    @foreach ($this->jobActivities as $activity)
                        <div class="flex items-start gap-3" wire:key="activity-{{ $activity->id }}">
                            <div class="w-2 h-2 rounded-full mt-2 shrink-0 {{ match($activity->activity_type) {
                                'completed' => 'bg-green-500',
                                'stage_changed' => 'bg-blue-500',
                                'date_proposed', 'date_confirmed_company', 'date_confirmed_customer' => 'bg-purple-500',
                                'colors_confirmed_company', 'colors_confirmed_customer', 'color_added' => 'bg-amber-500',
                                'crew_dispatched', 'crew_accepted' => 'bg-teal-500',
                                'crew_rejected' => 'bg-red-500',
                                'photo_uploaded', 'photo_annotated' => 'bg-cyan-500',
                                'photo_commented' => 'bg-indigo-500',
                                'photo_deleted' => 'bg-red-500',
                                default => 'bg-zinc-400',
                            } }}"></div>
                            <div class="flex-1 min-w-0">
                                <flux:text class="text-sm">{{ $activity->description }}</flux:text>
                                <div class="flex items-center gap-2 mt-0.5">
                                    @if ($activity->user)
                                        <flux:text class="text-xs text-zinc-400">{{ $activity->user->name }}</flux:text>
                                        <span class="text-zinc-300 dark:text-zinc-600">&middot;</span>
                                    @endif
                                    <flux:text class="text-xs text-zinc-400">{{ $activity->created_at->diffForHumans() }}</flux:text>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Deal Link --}}
        @if ($job->deal)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <flux:heading size="sm" class="mb-4">{{ __('Related Deal') }}</flux:heading>
                <a href="{{ route('deals.show', $job->deal) }}" class="text-blue-600 hover:underline" wire:navigate>
                    {{ $job->deal->title }}
                </a>
                <flux:text class="text-sm text-zinc-500 mt-1">
                    {{ __('Stage:') }} {{ $job->deal->stage_label }}
                </flux:text>
            </div>
        @endif

        {{-- Deal Conversation --}}
        @if ($this->chatThread)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="chat-bubble-left-right" class="w-5 h-5 text-zinc-500" />
                        <flux:heading size="sm">{{ __('Deal Conversation') }}</flux:heading>
                    </div>
                    <flux:text class="text-xs text-zinc-400 mt-1">
                        {{ __('Messages shared with the customer and team about this job.') }}
                    </flux:text>
                </div>
                <div class="h-[400px]">
                    @livewire('chat.thread-view', ['thread' => $this->chatThread, 'embedded' => true], key('job-chat-' . $this->chatThread->id))
                </div>
            </div>
        @endif

    </div>
</div>
