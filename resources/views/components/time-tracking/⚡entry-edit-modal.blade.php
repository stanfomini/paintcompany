<?php

use App\Models\TimeEntry;
use App\Services\TimeTrackingService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public ?int $entryId = null;

    public bool $showModal = false;

    public string $clockInDate = '';

    public string $clockInTime = '';

    public string $clockOutDate = '';

    public string $clockOutTime = '';

    public int $breakMinutes = 0;

    public string $notes = '';

    #[\Livewire\Attributes\On('open-edit-time-entry')]
    public function openEntry(int $entryId): void
    {
        $this->entryId = $entryId;
        $this->loadEntry();
        $this->showModal = true;
    }

    protected function loadEntry(): void
    {
        $entry = TimeEntry::findOrFail($this->entryId);

        $this->clockInDate = $entry->clock_in->toDateString();
        $this->clockInTime = $entry->clock_in->format('H:i');
        $this->clockOutDate = $entry->clock_out?->toDateString() ?? '';
        $this->clockOutTime = $entry->clock_out?->format('H:i') ?? '';
        $this->breakMinutes = $entry->break_minutes ?? 0;
        $this->notes = $entry->notes ?? '';
    }

    #[Computed]
    public function entry(): ?TimeEntry
    {
        if (! $this->entryId) {
            return null;
        }

        return TimeEntry::with('employeeProfile.user', 'job')->findOrFail($this->entryId);
    }

    public function save(): void
    {
        if (! auth()->user()?->can('time_tracking.manage')) {
            abort(403);
        }

        $this->validate([
            'clockInDate' => 'required|date',
            'clockInTime' => 'required|date_format:H:i',
            'clockOutDate' => 'nullable|date',
            'clockOutTime' => 'nullable|date_format:H:i',
            'breakMinutes' => 'integer|min:0|max:480',
            'notes' => 'nullable|string|max:1000',
        ]);

        $entry = TimeEntry::findOrFail($this->entryId);
        $service = app(TimeTrackingService::class);

        $changes = [
            'clock_in' => $this->clockInDate.' '.$this->clockInTime.':00',
            'break_minutes' => $this->breakMinutes,
            'notes' => $this->notes ?: null,
        ];

        if ($this->clockOutDate && $this->clockOutTime) {
            $changes['clock_out'] = $this->clockOutDate.' '.$this->clockOutTime.':00';
        }

        $service->editEntry($entry, auth()->user(), $changes);

        $this->showModal = false;
        $this->dispatch('time-entry-updated');
    }

    public function approve(): void
    {
        if (! auth()->user()?->can('time_tracking.manage')) {
            abort(403);
        }

        $entry = TimeEntry::findOrFail($this->entryId);
        $service = app(TimeTrackingService::class);
        $service->approveEntry($entry, auth()->user());

        $this->showModal = false;
        $this->dispatch('time-entry-updated');
    }

    public function disputeEntry(): void
    {
        if (! auth()->user()?->can('time_tracking.manage')) {
            abort(403);
        }

        $entry = TimeEntry::findOrFail($this->entryId);
        $entry->update([
            'status' => 'disputed',
            'dispute_reason' => $this->notes ?: 'Disputed by manager',
        ]);

        $this->showModal = false;
        $this->dispatch('time-entry-updated');
    }

    public function close(): void
    {
        $this->showModal = false;
    }
};
?>

<div>
    <flux:modal wire:model="showModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">Edit Time Entry</flux:heading>

            @if($this->entry)
            <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-700/50">
                <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                    {{ $this->entry->employeeProfile->user->name ?? 'Unknown' }}
                </p>
                @if($this->entry->job)
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $this->entry->job->job_number }} - {{ $this->entry->job->title }}</p>
                @endif
                <div class="mt-1 flex gap-2">
                    @php
                        $statusColor = match($this->entry->status) {
                            'active' => 'blue',
                            'pending' => 'yellow',
                            'approved' => 'green',
                            'disputed' => 'red',
                            'edited' => 'purple',
                            default => 'zinc',
                        };
                    @endphp
                    <flux:badge color="{{ $statusColor }}" size="sm">{{ ucfirst($this->entry->status) }}</flux:badge>
                    @if($this->entry->original_clock_in)
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">
                            Originally: {{ $this->entry->original_clock_in->format('g:i A') }}
                            @if($this->entry->original_clock_out)
                                - {{ $this->entry->original_clock_out->format('g:i A') }}
                            @endif
                        </span>
                    @endif
                </div>
            </div>

            @endif

            <div class="grid grid-cols-2 gap-3">
                <flux:input type="date" wire:model="clockInDate" label="Clock In Date" />
                <flux:input type="time" wire:model="clockInTime" label="Clock In Time" />
            </div>

            <div class="grid grid-cols-2 gap-3">
                <flux:input type="date" wire:model="clockOutDate" label="Clock Out Date" />
                <flux:input type="time" wire:model="clockOutTime" label="Clock Out Time" />
            </div>

            <flux:input type="number" wire:model="breakMinutes" label="Break Minutes" min="0" max="480" />

            <flux:textarea wire:model="notes" label="Notes" rows="2" />

            @if($this->entry)
                {{-- GPS Info (read-only) --}}
                @if($this->entry->clock_in_address || $this->entry->clock_out_address)
                    <div class="space-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                        @if($this->entry->clock_in_address)
                            <p>Clock In: {{ Str::limit($this->entry->clock_in_address, 60) }}</p>
                        @endif
                        @if($this->entry->clock_out_address)
                            <p>Clock Out: {{ Str::limit($this->entry->clock_out_address, 60) }}</p>
                        @endif
                    </div>
                @endif

                <div class="flex items-center justify-between pt-2">
                    <div class="flex gap-2">
                        @if($this->entry->status !== 'approved')
                            <flux:button size="sm" variant="primary" wire:click="approve">Approve</flux:button>
                        @endif
                        @if($this->entry->status !== 'disputed')
                            <flux:button size="sm" variant="danger" wire:click="disputeEntry">Dispute</flux:button>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <flux:button size="sm" variant="ghost" wire:click="close">Cancel</flux:button>
                        <flux:button size="sm" variant="primary" wire:click="save">Save Changes</flux:button>
                    </div>
                </div>
            @endif
        </div>
    </flux:modal>
</div>
