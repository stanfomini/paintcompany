<?php

use App\Models\Applicant;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public bool $show = false;

    public ?Applicant $applicant = null;

    public string $rejectionReason = '';

    public bool $showRejectForm = false;

    public string $offerAmount = '';

    public string $offerType = 'hourly';

    public bool $showOfferForm = false;

    public ?string $interviewDate = null;

    public bool $showInterviewForm = false;

    public string $newNote = '';

    #[On('open-applicant-detail')]
    public function open(int $applicantId): void
    {
        $this->reset(['rejectionReason', 'showRejectForm', 'offerAmount', 'offerType', 'showOfferForm', 'interviewDate', 'showInterviewForm', 'newNote']);
        $this->applicant = Applicant::with('jobListing')->findOrFail($applicantId);

        if ($this->applicant->offer_amount) {
            $this->offerAmount = (string) $this->applicant->offer_amount;
            $this->offerType = $this->applicant->offer_type ?? 'hourly';
        }

        if ($this->applicant->interview_date) {
            $this->interviewDate = $this->applicant->interview_date->format('Y-m-d\TH:i');
        }

        $this->show = true;
    }

    public function close(): void
    {
        $this->show = false;
        $this->applicant = null;
    }

    public function setRating(int $rating): void
    {
        if (! auth()->user()?->can('hiring.manage')) {
            abort(403);
        }

        $this->applicant->update(['rating' => $rating]);
        $this->dispatch('applicant-updated');
    }

    public function advanceStage(): void
    {
        if (! auth()->user()?->can('hiring.manage')) {
            abort(403);
        }

        $this->applicant->advanceStage();
        $this->applicant->refresh();
        $this->dispatch('applicant-updated');
    }

    public function reject(): void
    {
        if (! auth()->user()?->can('hiring.manage')) {
            abort(403);
        }

        $this->applicant->reject($this->rejectionReason ?: null);
        $this->applicant->refresh();
        $this->showRejectForm = false;
        $this->dispatch('applicant-updated');
    }

    public function scheduleInterview(): void
    {
        if (! auth()->user()?->can('hiring.manage')) {
            abort(403);
        }

        $this->validate([
            'interviewDate' => ['required', 'date'],
        ]);

        $this->applicant->update([
            'interview_date' => $this->interviewDate,
        ]);

        $this->showInterviewForm = false;
        $this->dispatch('applicant-updated');
    }

    public function makeOffer(): void
    {
        if (! auth()->user()?->can('hiring.manage')) {
            abort(403);
        }

        $this->validate([
            'offerAmount' => ['required', 'numeric', 'min:0'],
            'offerType' => ['required', 'in:hourly,salary'],
        ]);

        $this->applicant->update([
            'offer_amount' => $this->offerAmount,
            'offer_type' => $this->offerType,
            'stage' => 'offer',
        ]);

        $this->applicant->refresh();
        $this->showOfferForm = false;
        $this->dispatch('applicant-updated');
    }

    public function addNote(): void
    {
        if (! auth()->user()?->can('hiring.manage')) {
            abort(403);
        }

        $this->validate([
            'newNote' => ['required', 'string', 'max:2000'],
        ]);

        $timestamp = now()->format('M d, Y g:i A');
        $userName = auth()->user()->name;
        $noteEntry = "[{$timestamp} - {$userName}] {$this->newNote}";

        $existingNotes = $this->applicant->notes;
        $this->applicant->update([
            'notes' => $existingNotes ? $existingNotes."\n".$noteEntry : $noteEntry,
        ]);

        $this->newNote = '';
    }

    public function openHireModal(): void
    {
        $this->dispatch('open-hire-modal', applicantId: $this->applicant->id);
        $this->show = false;
    }
}; ?>

<div>
    <flux:modal wire:model="show" variant="flyout" class="max-w-xl">
        @if ($applicant)
            <div>
                {{-- Header --}}
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <flux:heading size="lg">{{ $applicant->getFullName() }}</flux:heading>
                        <flux:text class="text-zinc-500">{{ $applicant->jobListing?->title ?? __('No listing') }}</flux:text>
                    </div>
                    @php
                        $stageColors = [
                            'applied' => 'zinc',
                            'screening' => 'blue',
                            'phone_interview' => 'indigo',
                            'in_person' => 'purple',
                            'background_check' => 'yellow',
                            'offer' => 'amber',
                            'hired' => 'green',
                            'rejected' => 'red',
                        ];
                    @endphp
                    <flux:badge color="{{ $stageColors[$applicant->stage] ?? 'zinc' }}">
                        {{ Applicant::STAGE_LABELS[$applicant->stage] ?? ucfirst($applicant->stage) }}
                    </flux:badge>
                </div>

                {{-- Stage Timeline --}}
                <div class="mb-6">
                    <div class="flex items-center gap-1 overflow-x-auto pb-2">
                        @php
                            $stages = array_keys(collect(Applicant::STAGE_LABELS)->except(['rejected'])->all());
                            $currentIndex = array_search($applicant->stage, $stages);
                            if ($applicant->stage === 'rejected') {
                                $currentIndex = -1;
                            }
                        @endphp
                        @foreach ($stages as $idx => $stage)
                            @php
                                $isComplete = $currentIndex !== false && $idx < $currentIndex;
                                $isCurrent = $applicant->stage === $stage;
                            @endphp
                            <div class="flex items-center gap-1 shrink-0">
                                <div class="h-2 w-8 rounded-full {{ $isComplete || $isCurrent ? 'bg-blue-500' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="flex justify-between text-xs text-zinc-400 mt-1">
                        <span>{{ __('Applied') }}</span>
                        <span>{{ __('Hired') }}</span>
                    </div>
                </div>

                {{-- Contact Info --}}
                <div class="space-y-3 mb-6">
                    <flux:heading size="sm">{{ __('Contact Information') }}</flux:heading>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <flux:text class="text-xs text-zinc-500">{{ __('Email') }}</flux:text>
                            <flux:text class="text-sm">{{ $applicant->email }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-xs text-zinc-500">{{ __('Phone') }}</flux:text>
                            <flux:text class="text-sm">{{ $applicant->phone ?? '-' }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-xs text-zinc-500">{{ __('Source') }}</flux:text>
                            <flux:text class="text-sm">{{ Applicant::SOURCES[$applicant->source] ?? $applicant->source ?? '-' }}</flux:text>
                        </div>
                        <div>
                            <flux:text class="text-xs text-zinc-500">{{ __('Applied') }}</flux:text>
                            <flux:text class="text-sm">{{ $applicant->created_at->format('M d, Y') }}</flux:text>
                        </div>
                    </div>
                </div>

                {{-- Rating --}}
                <div class="mb-6">
                    <flux:heading size="sm" class="mb-2">{{ __('Rating') }}</flux:heading>
                    <div class="flex gap-1">
                        @for ($i = 1; $i <= 5; $i++)
                            <button
                                wire:click="setRating({{ $i }})"
                                class="focus:outline-none"
                                @cannot('hiring.manage') disabled @endcannot
                            >
                                <flux:icon
                                    name="star"
                                    class="h-6 w-6 {{ $i <= ($applicant->rating ?? 0) ? 'text-yellow-400 fill-yellow-400' : 'text-zinc-300 dark:text-zinc-600' }} {{ auth()->user()?->can('hiring.manage') ? 'hover:text-yellow-400 cursor-pointer' : '' }}"
                                />
                            </button>
                        @endfor
                    </div>
                </div>

                {{-- Resume & Cover Letter --}}
                @if ($applicant->resume_path || $applicant->cover_letter)
                    <div class="mb-6 space-y-3">
                        @if ($applicant->resume_path)
                            <div>
                                <flux:heading size="sm" class="mb-1">{{ __('Resume') }}</flux:heading>
                                <flux:button variant="ghost" size="sm" icon="arrow-down-tray" href="{{ storage_url($applicant->resume_path) }}" target="_blank">
                                    {{ __('Download Resume') }}
                                </flux:button>
                            </div>
                        @endif
                        @if ($applicant->cover_letter)
                            <div>
                                <flux:heading size="sm" class="mb-1">{{ __('Cover Letter') }}</flux:heading>
                                <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-sm max-h-40 overflow-y-auto">
                                    <flux:text>{!! nl2br(e($applicant->cover_letter)) !!}</flux:text>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Interview Date --}}
                @if ($applicant->interview_date)
                    <div class="mb-6">
                        <flux:heading size="sm" class="mb-1">{{ __('Interview Scheduled') }}</flux:heading>
                        <flux:text>{{ $applicant->interview_date->format('M d, Y g:i A') }}</flux:text>
                    </div>
                @endif

                {{-- Offer Info --}}
                @if ($applicant->offer_amount)
                    <div class="mb-6">
                        <flux:heading size="sm" class="mb-1">{{ __('Offer') }}</flux:heading>
                        <flux:text>${{ number_format((float) $applicant->offer_amount, 2) }} {{ $applicant->offer_type === 'salary' ? __('/ year') : __('/ hour') }}</flux:text>
                    </div>
                @endif

                {{-- Notes --}}
                <div class="mb-6">
                    <flux:heading size="sm" class="mb-2">{{ __('Notes') }}</flux:heading>
                    @if ($applicant->notes)
                        <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-3 text-sm max-h-40 overflow-y-auto mb-3">
                            <flux:text>{!! nl2br(e($applicant->notes)) !!}</flux:text>
                        </div>
                    @endif
                    @can('hiring.manage')
                        <div class="flex gap-2">
                            <flux:input wire:model="newNote" placeholder="{{ __('Add a note...') }}" class="flex-1" />
                            <flux:button variant="ghost" size="sm" wire:click="addNote" icon="plus">{{ __('Add') }}</flux:button>
                        </div>
                        <flux:error name="newNote" />
                    @endcan
                </div>

                <flux:separator class="my-4" />

                {{-- Action Buttons --}}
                @can('hiring.manage')
                    @if ($applicant->stage !== 'hired' && $applicant->stage !== 'rejected')
                        <div class="space-y-3">
                            <div class="flex flex-wrap gap-2">
                                <flux:button variant="primary" size="sm" wire:click="advanceStage" icon="arrow-right">
                                    {{ __('Advance Stage') }}
                                </flux:button>

                                @if (! $showInterviewForm)
                                    <flux:button variant="ghost" size="sm" wire:click="$set('showInterviewForm', true)" icon="calendar">
                                        {{ __('Schedule Interview') }}
                                    </flux:button>
                                @endif

                                @if (! $showOfferForm)
                                    <flux:button variant="ghost" size="sm" wire:click="$set('showOfferForm', true)" icon="currency-dollar">
                                        {{ __('Make Offer') }}
                                    </flux:button>
                                @endif

                                @if ($applicant->stage === 'offer')
                                    <flux:button variant="primary" size="sm" wire:click="openHireModal" icon="check-circle" color="green">
                                        {{ __('Hire') }}
                                    </flux:button>
                                @endif

                                @if (! $showRejectForm)
                                    <flux:button variant="ghost" size="sm" wire:click="$set('showRejectForm', true)" icon="x-circle" class="text-red-600 dark:text-red-400">
                                        {{ __('Reject') }}
                                    </flux:button>
                                @endif
                            </div>

                            {{-- Interview Form --}}
                            @if ($showInterviewForm)
                                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 space-y-3">
                                    <flux:heading size="sm">{{ __('Schedule Interview') }}</flux:heading>
                                    <flux:input wire:model="interviewDate" type="datetime-local" />
                                    <flux:error name="interviewDate" />
                                    <div class="flex gap-2">
                                        <flux:button variant="primary" size="sm" wire:click="scheduleInterview">{{ __('Save') }}</flux:button>
                                        <flux:button variant="ghost" size="sm" wire:click="$set('showInterviewForm', false)">{{ __('Cancel') }}</flux:button>
                                    </div>
                                </div>
                            @endif

                            {{-- Offer Form --}}
                            @if ($showOfferForm)
                                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 space-y-3">
                                    <flux:heading size="sm">{{ __('Make Offer') }}</flux:heading>
                                    <div class="grid grid-cols-2 gap-3">
                                        <flux:field>
                                            <flux:label>{{ __('Amount') }}</flux:label>
                                            <flux:input wire:model="offerAmount" type="number" step="0.01" min="0" icon="currency-dollar" />
                                            <flux:error name="offerAmount" />
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('Type') }}</flux:label>
                                            <flux:select wire:model="offerType">
                                                <flux:select.option value="hourly">{{ __('Hourly') }}</flux:select.option>
                                                <flux:select.option value="salary">{{ __('Salary') }}</flux:select.option>
                                            </flux:select>
                                        </flux:field>
                                    </div>
                                    <div class="flex gap-2">
                                        <flux:button variant="primary" size="sm" wire:click="makeOffer">{{ __('Send Offer') }}</flux:button>
                                        <flux:button variant="ghost" size="sm" wire:click="$set('showOfferForm', false)">{{ __('Cancel') }}</flux:button>
                                    </div>
                                </div>
                            @endif

                            {{-- Reject Form --}}
                            @if ($showRejectForm)
                                <div class="border border-red-200 dark:border-red-800 rounded-lg p-3 space-y-3">
                                    <flux:heading size="sm">{{ __('Reject Applicant') }}</flux:heading>
                                    <flux:textarea wire:model="rejectionReason" rows="2" placeholder="{{ __('Reason for rejection (optional)...') }}" />
                                    <div class="flex gap-2">
                                        <flux:button variant="danger" size="sm" wire:click="reject">{{ __('Confirm Reject') }}</flux:button>
                                        <flux:button variant="ghost" size="sm" wire:click="$set('showRejectForm', false)">{{ __('Cancel') }}</flux:button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                @endcan
            </div>
        @endif
    </flux:modal>
</div>
