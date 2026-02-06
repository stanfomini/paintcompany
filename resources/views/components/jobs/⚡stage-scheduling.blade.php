<?php

use App\Models\CompanySetting;
use App\Models\Job;
use App\Models\JobColor;
use App\Models\User;
use App\Services\JobService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    public Job $job;

    #[Validate('required|date|after:today')]
    public string $scheduledDate = '';

    #[Validate('required|integer|min:1|max:20')]
    public int $allowedColors = 3;

    public int $requiredCrewSize = 1;

    public bool $showDispatchModal = false;

    public bool $showColorModal = false;

    public string $newColorName = '';

    public string $newColorBrand = '';

    public string $newColorCode = '';

    public string $newColorHex = '#ffffff';

    /** @var array<int> */
    public array $selectedCrewIds = [];

    public function mount(Job $job): void
    {
        $this->job = $job;
        $this->scheduledDate = $job->scheduled_date?->format('Y-m-d') ?? '';
        $this->allowedColors = $job->allowed_colors ?: 3;
        $this->requiredCrewSize = $job->required_crew_size;
    }

    /**
     * Save scheduling settings (date + allowed colors + crew size).
     */
    public function saveScheduling(): void
    {
        $this->validate([
            'scheduledDate' => 'required|date|after:today',
            'allowedColors' => 'required|integer|min:1|max:20',
            'requiredCrewSize' => 'required|integer|min:1|max:20',
        ]);

        $service = app(JobService::class);
        $service->setScheduledDate($this->job, \Carbon\Carbon::parse($this->scheduledDate));
        $service->setAllowedColors($this->job, $this->allowedColors);
        $this->job->update(['required_crew_size' => $this->requiredCrewSize]);
        $this->job->refresh();

        session()->flash('success', 'Scheduling details saved.');
        $this->dispatch('job-updated');
    }

    /**
     * Confirm date from company side.
     */
    public function confirmCompanyDate(): void
    {
        if (! $this->job->scheduled_date) {
            session()->flash('error', 'Set a scheduled date first.');

            return;
        }

        app(JobService::class)->confirmCompanyDate($this->job);
        $this->job->refresh();
        session()->flash('success', 'Company date confirmed.');
        $this->dispatch('job-updated');
    }

    /**
     * Confirm colors from company side.
     */
    public function confirmCompanyColors(): void
    {
        if ($this->job->colors()->count() === 0) {
            session()->flash('error', 'Add at least one color first.');

            return;
        }

        app(JobService::class)->confirmCompanyColors($this->job);
        $this->job->refresh();
        session()->flash('success', 'Company colors confirmed.');
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
            session()->flash('error', 'Maximum number of colors reached.');

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
     * Dispatch job to selected crews.
     */
    public function dispatchToCrews(): void
    {
        if (empty($this->selectedCrewIds)) {
            session()->flash('error', 'Select at least one crew.');

            return;
        }

        foreach ($this->selectedCrewIds as $crewUserId) {
            if (! $this->job->crews()->where('crew_user_id', $crewUserId)->exists()) {
                $this->job->crews()->attach($crewUserId, [
                    'status' => 'dispatched',
                    'dispatched_at' => now(),
                ]);
            }
        }

        $this->job->update(['dispatched_at' => now()]);
        $this->job->refresh();
        $this->showDispatchModal = false;
        $this->selectedCrewIds = [];

        session()->flash('success', 'Job dispatched to selected crews.');
        $this->dispatch('crew-dispatched');
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function eligibleCrews(): Collection
    {
        return User::query()
            ->where('type', 'crew')
            ->where('is_active', true)
            ->whereHas('crewProfile', function ($q) {
                $q->where('crew_size', '>=', $this->job->required_crew_size);
            })
            ->with('crewProfile')
            ->orderBy('name')
            ->get();
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
}; ?>

<div>
    <flux:heading size="sm" class="mb-4">Stage 2: Scheduling & Colors</flux:heading>

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
            <flux:heading size="xs">Scheduling</flux:heading>
            <div class="space-y-3">
                <flux:field>
                    <flux:label>Scheduled Date</flux:label>
                    <flux:input type="date" wire:model="scheduledDate" />
                    <flux:error name="scheduledDate" />
                </flux:field>

                <flux:field>
                    <flux:label>Allowed Colors</flux:label>
                    <flux:input type="number" wire:model="allowedColors" min="1" max="20" />
                    <flux:error name="allowedColors" />
                </flux:field>

                <flux:field>
                    <flux:label>Required Crew Size</flux:label>
                    <flux:input type="number" wire:model="requiredCrewSize" min="1" max="20" />
                </flux:field>

                <flux:button wire:click="saveScheduling" variant="primary" size="sm">
                    Save Scheduling Details
                </flux:button>
            </div>

            {{-- Company Date Confirmation --}}
            <div class="pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:text class="font-medium text-sm">Company Date Confirmation</flux:text>
                        <flux:text class="text-xs text-zinc-500">Confirm the scheduled date from the company side</flux:text>
                    </div>
                    @if ($job->company_date_confirmed)
                        <flux:badge size="sm" color="green">Confirmed</flux:badge>
                    @else
                        <flux:button wire:click="confirmCompanyDate" size="sm" variant="filled">
                            Confirm Date
                        </flux:button>
                    @endif
                </div>
            </div>

            {{-- Customer Date Status --}}
            <div class="flex items-center justify-between">
                <div>
                    <flux:text class="font-medium text-sm">Customer Date Confirmation</flux:text>
                    <flux:text class="text-xs text-zinc-500">Customer must confirm on their portal</flux:text>
                </div>
                @if ($job->customer_date_confirmed)
                    <flux:badge size="sm" color="green">Confirmed</flux:badge>
                @else
                    <flux:badge size="sm" color="zinc">Waiting</flux:badge>
                @endif
            </div>
        </div>

        {{-- Colors Section --}}
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <flux:heading size="xs">Colors ({{ $job->colors->count() }}/{{ $job->allowed_colors ?: '?' }})</flux:heading>
                <flux:button wire:click="$set('showColorModal', true)" size="sm" icon="plus">
                    Add Color
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
                <flux:text class="text-zinc-500 text-sm">No colors added yet.</flux:text>
            @endif

            {{-- Company Colors Confirmation --}}
            <div class="pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:text class="font-medium text-sm">Company Colors Confirmation</flux:text>
                        <flux:text class="text-xs text-zinc-500">Confirm the color selections</flux:text>
                    </div>
                    @if ($job->company_colors_confirmed)
                        <flux:badge size="sm" color="green">Confirmed</flux:badge>
                    @else
                        <flux:button wire:click="confirmCompanyColors" size="sm" variant="filled">
                            Confirm Colors
                        </flux:button>
                    @endif
                </div>
            </div>

            {{-- Customer Colors Status --}}
            <div class="flex items-center justify-between">
                <div>
                    <flux:text class="font-medium text-sm">Customer Colors Confirmation</flux:text>
                    <flux:text class="text-xs text-zinc-500">Customer must confirm on their portal</flux:text>
                </div>
                @if ($job->customer_colors_confirmed)
                    <flux:badge size="sm" color="green">Confirmed</flux:badge>
                @else
                    <flux:badge size="sm" color="zinc">Waiting</flux:badge>
                @endif
            </div>
        </div>
    </div>

    {{-- Dispatch Section --}}
    <div class="mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xs">Crew Dispatch</flux:heading>
                <flux:text class="text-sm text-zinc-500">
                    @if ($this->dispatchStatus === 'not_ready')
                        All 4 confirmations must be completed before dispatching.
                    @elseif ($this->dispatchStatus === 'dispatched')
                        Job has been dispatched to crews.
                    @else
                        Ready to dispatch to paint crews.
                    @endif
                </flux:text>
            </div>
            @if ($this->dispatchStatus === 'ready' || $this->dispatchStatus === 'dispatched')
                <flux:button wire:click="$set('showDispatchModal', true)" variant="primary" size="sm" icon="paper-airplane">
                    {{ $this->dispatchStatus === 'dispatched' ? 'Dispatch to More' : 'Dispatch to Crews' }}
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Add Color Modal --}}
    <flux:modal wire:model="showColorModal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">Add Color</flux:heading>

            <flux:field>
                <flux:label>Color Name</flux:label>
                <flux:input wire:model="newColorName" placeholder="e.g. Simply White" />
                <flux:error name="newColorName" />
            </flux:field>

            <flux:field>
                <flux:label>Brand (optional)</flux:label>
                <flux:input wire:model="newColorBrand" placeholder="e.g. Benjamin Moore" />
            </flux:field>

            <flux:field>
                <flux:label>Color Code (optional)</flux:label>
                <flux:input wire:model="newColorCode" placeholder="e.g. OC-17" />
            </flux:field>

            <flux:field>
                <flux:label>Hex Color (optional)</flux:label>
                <flux:input type="color" wire:model="newColorHex" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showColorModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="addColor" variant="primary">Add Color</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Dispatch Modal --}}
    <flux:modal wire:model="showDispatchModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">Dispatch to Crews</flux:heading>
            <flux:text class="text-zinc-500">
                Select crews to dispatch this job to. Only crews with {{ $job->required_crew_size }}+ members are shown.
            </flux:text>

            {{-- Crew Stats --}}
            <div class="flex gap-4 text-sm">
                <div class="flex items-center gap-1.5">
                    <flux:icon name="users" class="w-4 h-4 text-zinc-400" />
                    <span class="text-zinc-500">Company:</span>
                    <span class="font-medium">{{ $this->companySettings->total_painters ?? 4 }} painters</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <flux:icon name="user-group" class="w-4 h-4 text-zinc-400" />
                    <span class="text-zinc-500">Registered:</span>
                    <span class="font-medium">{{ $this->registeredCrewsCount }} crews ({{ $this->totalPaintersFromCrews }} painters)</span>
                </div>
            </div>

            @if ($this->eligibleCrews->count() > 0)
                <div class="space-y-2 max-h-64 overflow-y-auto">
                    @foreach ($this->eligibleCrews as $crew)
                        @php
                            $alreadyDispatched = $job->crews->contains('id', $crew->id);
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
                                    Crew of {{ $crew->crewProfile->crew_size }}
                                    @if ($crew->crewProfile->specialties)
                                        &middot; {{ implode(', ', array_slice($crew->crewProfile->specialties, 0, 2)) }}
                                    @endif
                                </flux:text>
                            </div>
                            @if ($alreadyDispatched)
                                <flux:badge size="sm" color="blue">Already dispatched</flux:badge>
                            @endif
                        </label>
                    @endforeach
                </div>

                <div class="flex justify-end gap-2">
                    <flux:button wire:click="$set('showDispatchModal', false)" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="dispatchToCrews" variant="primary" icon="paper-airplane">
                        Dispatch
                    </flux:button>
                </div>
            @else
                <div class="text-center py-6">
                    <flux:icon name="user-group" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                    <flux:text class="text-zinc-500">No eligible crews found.</flux:text>
                    <flux:text class="text-sm text-zinc-400 mt-1">
                        No registered crews meet the minimum size of {{ $job->required_crew_size }}.
                    </flux:text>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
