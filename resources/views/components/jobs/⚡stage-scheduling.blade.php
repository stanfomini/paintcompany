<?php

use App\Models\CompanySetting;
use App\Models\Job;
use App\Models\JobColor;
use App\Models\JobServiceRequirement;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\JobDispatchService;
use App\Services\JobService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public Job $job;

    #[Validate('required|date|after:today')]
    public string $scheduledDate = '';

    #[Validate('required|integer|min:1|max:20')]
    public int $allowedColors = 3;

    public bool $showDispatchModal = false;

    public bool $showColorModal = false;

    public string $newColorName = '';

    public string $newColorBrand = '';

    public string $newColorCode = '';

    public string $newColorHex = '#ffffff';

    /** @var array<int> */
    public array $selectedCrewIds = [];

    public ?int $activeDispatchServiceId = null;

    /** @var array<int, int> */
    public array $requirementCrewSizes = [];

    /** @var array<int, string> */
    public array $requirementDates = [];

    public function mount(Job $job): void
    {
        $this->job = $job;
        $this->scheduledDate = $job->scheduled_date?->format('Y-m-d') ?? '';
        $this->allowedColors = $job->allowed_colors ?: 3;

        foreach ($this->serviceRequirements as $req) {
            $this->requirementCrewSizes[$req->id] = $req->required_crew_size;
            $this->requirementDates[$req->id] = $req->scheduled_date?->format('Y-m-d') ?? '';
        }
    }

    /**
     * Save scheduling settings (date + allowed colors).
     */
    public function saveScheduling(): void
    {
        $this->validate([
            'scheduledDate' => 'required|date|after:today',
            'allowedColors' => 'required|integer|min:1|max:20',
        ]);

        $service = app(JobService::class);
        $service->setScheduledDate($this->job, \Carbon\Carbon::parse($this->scheduledDate));
        $service->setAllowedColors($this->job, $this->allowedColors);
        $this->job->refresh();

        session()->flash('success', __('Scheduling details saved.'));
        $this->dispatch('job-updated');
    }

    /**
     * Save a service requirement's crew size and date.
     */
    public function saveServiceRequirement(int $requirementId): void
    {
        $requirement = $this->job->serviceRequirements()->findOrFail($requirementId);

        $crewSize = $this->requirementCrewSizes[$requirementId] ?? $requirement->required_crew_size;
        $date = $this->requirementDates[$requirementId] ?? null;

        $this->validate([
            "requirementCrewSizes.{$requirementId}" => 'required|integer|min:1|max:20',
            "requirementDates.{$requirementId}" => 'nullable|date',
        ]);

        $requirement->update([
            'required_crew_size' => $crewSize,
            'scheduled_date' => $date ?: null,
        ]);

        $this->job->refresh();
        unset($this->serviceRequirements);

        session()->flash('success', __(':name requirements saved.', ['name' => __($requirement->serviceCategory->name)]));
    }

    /**
     * Open the dispatch modal for a specific service requirement.
     */
    public function openDispatchModal(int $requirementId): void
    {
        // Auto-save the requirement so eligible crew filter uses current UI values
        $requirement = $this->job->serviceRequirements()->findOrFail($requirementId);
        $crewSize = $this->requirementCrewSizes[$requirementId] ?? $requirement->required_crew_size;
        $date = $this->requirementDates[$requirementId] ?? null;

        $requirement->update([
            'required_crew_size' => max(1, (int) $crewSize),
            'scheduled_date' => $date ?: null,
        ]);

        $this->job->refresh();
        unset($this->serviceRequirements);

        $this->activeDispatchServiceId = $requirementId;
        $this->selectedCrewIds = [];
        unset($this->eligibleCrews);
        $this->showDispatchModal = true;
    }

    /**
     * Confirm date from company side.
     */
    public function confirmCompanyDate(): void
    {
        if (! $this->job->scheduled_date) {
            session()->flash('error', __('Set a scheduled date first.'));

            return;
        }

        app(JobService::class)->confirmCompanyDate($this->job);
        $this->job->refresh();
        session()->flash('success', __('Company date confirmed.'));
        $this->dispatch('job-updated');
    }

    /**
     * Confirm colors from company side.
     */
    public function confirmCompanyColors(): void
    {
        if ($this->job->colors()->count() === 0) {
            session()->flash('error', __('Add at least one color first.'));

            return;
        }

        app(JobService::class)->confirmCompanyColors($this->job);
        $this->job->refresh();
        session()->flash('success', __('Company colors confirmed.'));
        $this->dispatch('job-updated');
    }

    /**
     * Add a color to the job.
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

        $this->job->colors()->create([
            'name' => $this->newColorName,
            'brand' => $this->newColorBrand ?: null,
            'code' => $this->newColorCode ?: null,
            'hex_value' => $this->newColorHex !== '#ffffff' ? $this->newColorHex : null,
            'sort_order' => $this->job->colors()->count(),
        ]);

        $this->reset('newColorName', 'newColorBrand', 'newColorCode', 'newColorHex');
        $this->showColorModal = false;
        $this->job->refresh();
        $this->dispatch('color-updated');
    }

    /**
     * Remove a color.
     */
    public function removeColor(int $colorId): void
    {
        JobColor::where('id', $colorId)
            ->where('job_id', $this->job->id)
            ->delete();

        $this->job->refresh();
        $this->dispatch('color-updated');
    }

    /**
     * Dispatch job to selected crews for the active service.
     */
    public function dispatchToCrews(): void
    {
        if (empty($this->selectedCrewIds)) {
            session()->flash('error', __('Select at least one crew.'));

            return;
        }

        $category = null;
        if ($this->activeDispatchServiceId) {
            $requirement = $this->job->serviceRequirements()->find($this->activeDispatchServiceId);
            $category = $requirement?->serviceCategory;
        }

        $dispatched = app(JobDispatchService::class)->dispatchToCrews(
            $this->job,
            $this->selectedCrewIds,
            $category
        );

        $this->job->refresh();
        $this->showDispatchModal = false;
        $this->activeDispatchServiceId = null;
        $this->selectedCrewIds = [];
        unset($this->serviceRequirements);

        $label = $category ? ' ' . __('for :name', ['name' => __($category->name)]) : '';
        session()->flash('success', __('Dispatched to :count crew(s)', ['count' => $dispatched]) . $label . '.');
        $this->dispatch('crew-dispatched');
    }

    /**
     * Remove a crew from the job entirely.
     */
    public function removeCrew(int $crewUserId, int $serviceCategoryId): void
    {
        $crew = User::findOrFail($crewUserId);
        $category = ServiceCategory::findOrFail($serviceCategoryId);

        app(JobDispatchService::class)->removeCrew($this->job, $crew, $category);

        $this->job->refresh();
        unset($this->serviceRequirements);

        session()->flash('success', __(':crew removed from :category.', ['crew' => $crew->name, 'category' => __($category->name)]));
        $this->dispatch('crew-dispatched');
    }

    /**
     * Replace an accepted crew for a specific service category.
     */
    public function replaceCrew(int $crewUserId, int $serviceCategoryId): void
    {
        $crew = User::findOrFail($crewUserId);
        $category = ServiceCategory::findOrFail($serviceCategoryId);

        app(JobDispatchService::class)->replaceCrew($this->job, $crew, $category);

        $this->job->refresh();
        unset($this->serviceRequirements);

        session()->flash('success', __(':crew replaced for :category.', ['crew' => $crew->name, 'category' => __($category->name)]));
    }

    #[Computed]
    public function serviceRequirements(): Collection
    {
        return $this->job->serviceRequirements()->with('serviceCategory')->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function eligibleCrews(): Collection
    {
        $category = null;
        if ($this->activeDispatchServiceId) {
            $requirement = $this->job->serviceRequirements()->find($this->activeDispatchServiceId);
            $category = $requirement?->serviceCategory;
        }

        return app(JobDispatchService::class)->getEligibleCrews($this->job, $category);
    }

    #[Computed]
    public function dispatchStatus(): string
    {
        if (! $this->job->isReadyForDispatch()) {
            return 'not_ready';
        }
        if ($this->job->dispatched_at) {
            return 'dispatched';
        }

        return 'ready';
    }

    #[Computed]
    public function companySettings(): CompanySetting
    {
        return CompanySetting::getInstance();
    }

    #[Computed]
    public function registeredCrewsCount(): int
    {
        return User::where('type', 'crew')->where('is_active', true)->count();
    }

    #[Computed]
    public function totalPaintersFromCrews(): int
    {
        return User::where('type', 'crew')
            ->where('is_active', true)
            ->whereHas('crewProfile')
            ->with('crewProfile')
            ->get()
            ->sum(fn ($crew) => $crew->crewProfile->crew_size ?? 0);
    }

    /**
     * Get the crews dispatched/accepted for a given service category.
     */
    public function getCrewsForService(int $serviceCategoryId): \Illuminate\Support\Collection
    {
        return $this->job->crews()
            ->wherePivot('service_category_id', $serviceCategoryId)
            ->get();
    }

    /**
     * Map a service requirement status to a Flux badge color.
     */
    public function statusColor(string $status): string
    {
        return match ($status) {
            JobServiceRequirement::STATUS_PENDING => 'zinc',
            JobServiceRequirement::STATUS_DISPATCHED => 'blue',
            JobServiceRequirement::STATUS_ACCEPTED => 'green',
            JobServiceRequirement::STATUS_COMPLETED => 'lime',
            default => 'zinc',
        };
    }

    /**
     * Refresh on Echo events.
     */
    #[On('echo-private:job.{job.id},.crew.dispatched')]
    #[On('echo-private:job.{job.id},.job.accepted')]
    #[On('echo-private:job.{job.id},.stage.changed')]
    public function refreshFromEcho(): void
    {
        $this->job->refresh();
        unset($this->serviceRequirements);
    }
}; ?>

<div>
    <flux:heading size="sm" class="mb-4">{{ __('Stage 2: Scheduling & Colors') }}</flux:heading>

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

    <div class="grid gap-6 md:grid-cols-2">
        {{-- Scheduling Section --}}
        <div class="space-y-4">
            <flux:heading size="xs">{{ __('Scheduling') }}</flux:heading>
            <div class="space-y-3">
                <flux:field>
                    <flux:label>{{ __('Scheduled Date') }}</flux:label>
                    <flux:input type="date" wire:model="scheduledDate" />
                    <flux:error name="scheduledDate" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Allowed Colors') }}</flux:label>
                    <flux:input type="number" wire:model="allowedColors" min="1" max="20" />
                    <flux:error name="allowedColors" />
                </flux:field>

                <flux:button wire:click="saveScheduling" variant="primary" size="sm">
                    {{ __('Save Scheduling Details') }}
                </flux:button>
            </div>

            {{-- Company Date Confirmation --}}
            <div class="pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:text class="font-medium text-sm">{{ __('Company Date Confirmation') }}</flux:text>
                        <flux:text class="text-xs text-zinc-500">{{ __('Confirm the scheduled date from the company side') }}</flux:text>
                    </div>
                    @if ($job->company_date_confirmed)
                        <flux:badge size="sm" color="green">{{ __('Confirmed') }}</flux:badge>
                    @else
                        <flux:button wire:click="confirmCompanyDate" size="sm" variant="filled">
                            {{ __('Confirm Date') }}
                        </flux:button>
                    @endif
                </div>
            </div>

            {{-- Customer Date Status --}}
            <div class="flex items-center justify-between">
                <div>
                    <flux:text class="font-medium text-sm">{{ __('Customer Date Confirmation') }}</flux:text>
                    <flux:text class="text-xs text-zinc-500">{{ __('Customer must confirm on their portal') }}</flux:text>
                </div>
                @if ($job->customer_date_confirmed)
                    <flux:badge size="sm" color="green">{{ __('Confirmed') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="zinc">{{ __('Waiting') }}</flux:badge>
                @endif
            </div>
        </div>

        {{-- Colors Section --}}
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="xs">{{ __('Colors') }} ({{ $job->colors->count() }}/{{ $job->allowed_colors ?: '?' }})</flux:heading>
                <flux:button wire:click="$set('showColorModal', true)" size="sm" icon="plus">
                    {{ __('Add Color') }}
                </flux:button>
            </div>

            @if ($job->colors->count() > 0)
                <div class="space-y-2">
                    @foreach ($job->colors as $color)
                        <div class="flex items-center justify-between p-2 rounded-lg border border-zinc-200 dark:border-zinc-700" wire:key="color-{{ $color->id }}">
                            <div class="flex items-center gap-2">
                                @if ($color->hex_value)
                                    <div class="w-6 h-6 rounded border border-zinc-300" style="background-color: {{ $color->hex_value }}"></div>
                                @endif
                                <div>
                                    <flux:text class="text-sm font-medium">{{ $color->name }}</flux:text>
                                    @if ($color->brand || $color->code)
                                        <flux:text class="text-xs text-zinc-500">
                                            {{ collect([$color->brand, $color->code])->filter()->join(' - ') }}
                                        </flux:text>
                                    @endif
                                </div>
                            </div>
                            <flux:button wire:click="removeColor({{ $color->id }})" size="xs" variant="ghost" icon="x-mark" />
                        </div>
                    @endforeach
                </div>
            @else
                <flux:text class="text-zinc-500 text-sm">{{ __('No colors added yet.') }}</flux:text>
            @endif

            {{-- Company Colors Confirmation --}}
            <div class="pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:text class="font-medium text-sm">{{ __('Company Colors Confirmation') }}</flux:text>
                        <flux:text class="text-xs text-zinc-500">{{ __('Confirm the color selections') }}</flux:text>
                    </div>
                    @if ($job->company_colors_confirmed)
                        <flux:badge size="sm" color="green">{{ __('Confirmed') }}</flux:badge>
                    @else
                        <flux:button wire:click="confirmCompanyColors" size="sm" variant="filled">
                            {{ __('Confirm Colors') }}
                        </flux:button>
                    @endif
                </div>
            </div>

            {{-- Customer Colors Status --}}
            <div class="flex items-center justify-between">
                <div>
                    <flux:text class="font-medium text-sm">{{ __('Customer Colors Confirmation') }}</flux:text>
                    <flux:text class="text-xs text-zinc-500">{{ __('Customer must confirm on their portal') }}</flux:text>
                </div>
                @if ($job->customer_colors_confirmed)
                    <flux:badge size="sm" color="green">{{ __('Confirmed') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="zinc">{{ __('Waiting') }}</flux:badge>
                @endif
            </div>
        </div>
    </div>

    {{-- Service Requirements & Dispatch Section --}}
    <div class="mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
        <flux:heading size="xs" class="mb-4">{{ __('Crew Dispatch by Service') }}</flux:heading>

        @if ($this->serviceRequirements->isEmpty())
            <flux:text class="text-zinc-500 text-sm">{{ __('No service requirements configured for this job.') }}</flux:text>
        @else
            <div class="space-y-4">
                @foreach ($this->serviceRequirements as $requirement)
                    @php
                        $category = $requirement->serviceCategory;
                        $serviceCrews = $this->getCrewsForService($category->id);
                        $acceptedCrews = $serviceCrews->filter(fn ($c) => $c->pivot->status === 'accepted');
                        $dispatchedCrews = $serviceCrews->filter(fn ($c) => $c->pivot->status === 'dispatched');
                    @endphp
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4" wire:key="service-req-{{ $requirement->id }}">
                        {{-- Service Header --}}
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2">
                                @if ($category->icon)
                                    <flux:icon :name="$category->icon" class="w-5 h-5 text-zinc-500" />
                                @endif
                                <flux:heading size="xs">{{ __($category->name) }}</flux:heading>
                                <flux:badge size="sm" :color="$this->statusColor($requirement->status)">
                                    {{ ucfirst($requirement->status) }}
                                </flux:badge>
                            </div>
                            @if ($this->dispatchStatus !== 'not_ready')
                                <flux:button wire:click="openDispatchModal({{ $requirement->id }})" size="sm" icon="paper-airplane">
                                    {{ $requirement->isDispatched() || $requirement->isAccepted() ? __('Dispatch More') : __('Dispatch') }}
                                </flux:button>
                            @endif
                        </div>

                        {{-- Service Settings --}}
                        <div class="grid gap-3 sm:grid-cols-2 mb-3">
                            <flux:field>
                                <flux:label>{{ __('Required Crew Size') }}</flux:label>
                                <flux:input
                                    type="number"
                                    wire:model="requirementCrewSizes.{{ $requirement->id }}"
                                    min="1"
                                    max="20"
                                />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Service Date') }}</flux:label>
                                <flux:input
                                    type="date"
                                    wire:model="requirementDates.{{ $requirement->id }}"
                                />
                            </flux:field>
                        </div>
                        <flux:button wire:click="saveServiceRequirement({{ $requirement->id }})" size="xs" variant="filled">
                            {{ __('Save') }} {{ __($category->name) }} {{ __('Settings') }}
                        </flux:button>

                        {{-- Dispatched/Accepted Crews for this service --}}
                        @if ($serviceCrews->isNotEmpty())
                            <flux:separator class="my-3" />
                            <div class="space-y-2">
                                @foreach ($serviceCrews as $crew)
                                    <div class="flex items-center justify-between p-2 rounded-lg bg-zinc-50 dark:bg-zinc-800" wire:key="service-crew-{{ $requirement->id }}-{{ $crew->id }}">
                                        <div class="flex items-center gap-2">
                                            <flux:avatar size="sm" name="{{ $crew->name }}" />
                                            <div>
                                                <flux:text class="text-sm font-medium">{{ $crew->name }}</flux:text>
                                                <flux:text class="text-xs text-zinc-500">
                                                    {{ __('Crew of') }} {{ $crew->crewProfile->crew_size ?? '?' }}
                                                </flux:text>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            @if ($crew->pivot->status === 'accepted')
                                                <flux:badge size="sm" color="green">{{ __('Accepted') }}</flux:badge>
                                                <flux:button
                                                    wire:click="replaceCrew({{ $crew->id }}, {{ $category->id }})"
                                                    wire:confirm="{{ __('Replace :name for :category? This will reset this service to dispatched status.', ['name' => $crew->name, 'category' => __($category->name)]) }}"
                                                    size="xs"
                                                    variant="ghost"
                                                >
                                                    {{ __('Replace') }}
                                                </flux:button>
                                            @elseif ($crew->pivot->status === 'dispatched')
                                                <flux:badge size="sm" color="blue">{{ __('Dispatched') }}</flux:badge>
                                            @elseif ($crew->pivot->status === 'rejected')
                                                <flux:badge size="sm" color="red">{{ __('Rejected') }}</flux:badge>
                                            @elseif ($crew->pivot->status === 'replaced')
                                                <flux:badge size="sm" color="zinc">{{ __('Replaced') }}</flux:badge>
                                            @endif
                                            <flux:button
                                                wire:click="removeCrew({{ $crew->id }}, {{ $category->id }})"
                                                wire:confirm="{{ __('Remove :name from this job? This will permanently unlink them.', ['name' => $crew->name]) }}"
                                                size="xs"
                                                variant="ghost"
                                                icon="x-mark"
                                            />
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if ($this->dispatchStatus === 'not_ready')
            <flux:text class="text-sm text-zinc-500 mt-3">
                {{ __('All 4 confirmations (company date, customer date, company colors, customer colors) must be completed before dispatching.') }}
            </flux:text>
        @endif
    </div>

    {{-- Add Color Modal --}}
    <flux:modal wire:model="showColorModal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Add Color') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Color Name') }}</flux:label>
                <flux:input wire:model="newColorName" :placeholder="__('e.g. Simply White')" />
                <flux:error name="newColorName" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Brand (optional)') }}</flux:label>
                <flux:input wire:model="newColorBrand" :placeholder="__('e.g. Benjamin Moore')" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Color Code (optional)') }}</flux:label>
                <flux:input wire:model="newColorCode" :placeholder="__('e.g. OC-17')" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Hex Color (optional)') }}</flux:label>
                <flux:input type="color" wire:model="newColorHex" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showColorModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="addColor" variant="primary">{{ __('Add Color') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Per-Service Dispatch Modal --}}
    <flux:modal wire:model="showDispatchModal" class="max-w-lg">
        <div class="space-y-4">
            @php
                $activeReq = $activeDispatchServiceId
                    ? $this->serviceRequirements->firstWhere('id', $activeDispatchServiceId)
                    : null;
                $activeCategoryName = $activeReq?->serviceCategory ? __($activeReq->serviceCategory->name) : __('Crews');
                $requiredSize = $activeReq?->required_crew_size ?? $job->required_crew_size;
            @endphp

            <flux:heading size="lg">{{ __('Dispatch') }} {{ $activeCategoryName }} {{ __('Crews') }}</flux:heading>
            <flux:text class="text-zinc-500">
                {{ __('Select crews to dispatch for :category. Only crews with :size+ members in this service category are shown.', ['category' => strtolower($activeCategoryName), 'size' => $requiredSize]) }}
            </flux:text>

            {{-- Crew Stats --}}
            <div class="flex gap-4 text-sm">
                <div class="flex items-center gap-1.5">
                    <flux:icon name="users" class="w-4 h-4 text-zinc-400" />
                    <span class="text-zinc-500">{{ __('Company:') }}</span>
                    <span class="font-medium">{{ $this->companySettings->total_painters ?? 4 }} {{ __('painters') }}</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <flux:icon name="user-group" class="w-4 h-4 text-zinc-400" />
                    <span class="text-zinc-500">{{ __('Registered:') }}</span>
                    <span class="font-medium">{{ $this->registeredCrewsCount }} {{ __('crews') }} ({{ $this->totalPaintersFromCrews }} {{ __('painters') }})</span>
                </div>
            </div>

            @if ($this->eligibleCrews->count() > 0)
                <div class="space-y-2 max-h-64 overflow-y-auto">
                    @foreach ($this->eligibleCrews as $crew)
                        @php
                            $alreadyDispatched = $activeReq
                                ? $job->crews()
                                    ->wherePivot('service_category_id', $activeReq->service_category_id)
                                    ->where('crew_user_id', $crew->id)
                                    ->whereIn('job_crew.status', ['dispatched', 'accepted'])
                                    ->exists()
                                : $job->crews->contains('id', $crew->id);
                        @endphp
                        <label class="flex items-center gap-3 p-3 rounded-lg border border-zinc-200 dark:border-zinc-700 cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-700/50 {{ $alreadyDispatched ? 'opacity-50' : '' }}" wire:key="dispatch-crew-{{ $crew->id }}">
                            <input
                                type="checkbox"
                                wire:model="selectedCrewIds"
                                value="{{ $crew->id }}"
                                class="rounded border-zinc-300"
                                {{ $alreadyDispatched ? 'disabled' : '' }}
                            />
                            <flux:avatar size="sm" name="{{ $crew->name }}" />
                            <div class="flex-1">
                                <flux:text class="font-medium text-sm">{{ $crew->name }}</flux:text>
                                <flux:text class="text-xs text-zinc-500">
                                    {{ __('Crew of') }} {{ $crew->crewProfile->crew_size }}
                                    @if ($crew->crewProfile->specialties)
                                        &middot; {{ implode(', ', array_slice($crew->crewProfile->specialties, 0, 2)) }}
                                    @endif
                                </flux:text>
                            </div>
                            @if ($alreadyDispatched)
                                <flux:badge size="sm" color="blue">{{ __('Already dispatched') }}</flux:badge>
                            @endif
                        </label>
                    @endforeach
                </div>

                <div class="flex justify-end gap-2">
                    <flux:button wire:click="$set('showDispatchModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                    <flux:button wire:click="dispatchToCrews" variant="primary" icon="paper-airplane">
                        {{ __('Dispatch') }}
                    </flux:button>
                </div>
            @else
                <div class="text-center py-6">
                    <flux:icon name="user-group" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                    <flux:text class="text-zinc-500">{{ __('No eligible crews found.') }}</flux:text>
                    <flux:text class="text-sm text-zinc-400 mt-1">
                        {{ __('No registered :category crews meet the minimum size of :size.', ['category' => strtolower($activeCategoryName), 'size' => $requiredSize]) }}
                    </flux:text>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
