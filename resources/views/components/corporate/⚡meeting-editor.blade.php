<?php

use App\Jobs\GenerateMeetingSummaryJob;
use App\Models\MeetingMinutes;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $minutesId;

    public string $title = '';
    public string $meeting_date = '';
    public string $meeting_type = 'annual';
    public string $transcript = '';
    public string $executive_summary = '';
    public array $attendees = [];
    public string $newAttendee = '';
    public string $notes = '';

    public function boot(): void
    {
        if (! Auth::user()?->can('business_plan.view')) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $min = MeetingMinutes::findOrFail($this->minutesId);
        $this->title = $min->title;
        $this->meeting_date = $min->meeting_date->format('Y-m-d');
        $this->meeting_type = $min->meeting_type;
        $this->transcript = $min->transcript ?? '';
        $this->executive_summary = $min->executive_summary ?? '';
        $this->attendees = $min->attendees ?? [];
        $this->notes = $min->notes ?? '';
    }

    public function rendering(): void
    {
        $min = $this->minutes;
        if ($min->summary_status === 'completed' && empty($this->executive_summary) && $min->executive_summary) {
            $this->executive_summary = $min->executive_summary;
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('business_plan.manage') ?? false;
    }

    #[Computed]
    public function minutes(): MeetingMinutes
    {
        return MeetingMinutes::findOrFail($this->minutesId);
    }

    #[Computed]
    public function audioUrl(): ?string
    {
        $min = $this->minutes;
        if (! $min->audio_path) {
            return null;
        }

        return route('corporate.minutes.audio', $min);
    }

    public function addAttendee(): void
    {
        $name = trim($this->newAttendee);
        if ($name && ! in_array($name, $this->attendees)) {
            $this->attendees[] = $name;
        }
        $this->newAttendee = '';
    }

    public function removeAttendee(int $index): void
    {
        unset($this->attendees[$index]);
        $this->attendees = array_values($this->attendees);
    }

    public function save(): void
    {
        if (! $this->canManage) {
            abort(403);
        }

        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'meeting_date' => ['required', 'date'],
            'meeting_type' => ['required', 'in:annual,special,board,emergency'],
            'transcript' => ['nullable', 'string'],
            'executive_summary' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->minutes->update([
            'title' => $this->title,
            'meeting_date' => $this->meeting_date,
            'meeting_type' => $this->meeting_type,
            'transcript' => $this->transcript ?: null,
            'executive_summary' => $this->executive_summary ?: null,
            'attendees' => $this->attendees ?: null,
            'notes' => $this->notes ?: null,
        ]);

        unset($this->minutes);
    }

    public function regenerateSummary(): void
    {
        if (! $this->canManage) {
            abort(403);
        }

        $min = $this->minutes;
        if (! $min->transcript) {
            return;
        }

        $min->update(['summary_status' => 'pending', 'executive_summary' => null]);
        GenerateMeetingSummaryJob::dispatch($min);

        $this->executive_summary = '';
        unset($this->minutes);
    }
};
?>

<div>
    <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="mb-4 flex items-center justify-between">
            <flux:heading size="lg">{{ __('Edit Meeting Minutes') }}</flux:heading>
            <div class="flex gap-2">
                <flux:button size="sm" variant="ghost" icon="arrow-down-tray" :href="route('corporate.minutes.pdf', $this->minutes)">
                    {{ __('Export PDF') }}
                </flux:button>
                @if ($this->canManage)
                    <flux:button size="sm" variant="primary" wire:click="save">
                        {{ __('Save') }}
                    </flux:button>
                @endif
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <flux:field>
                    <flux:label>{{ __('Title') }}</flux:label>
                    <flux:input wire:model="title" />
                    <flux:error name="title" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Meeting Date') }}</flux:label>
                <flux:input wire:model="meeting_date" type="date" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Meeting Type') }}</flux:label>
                <flux:select wire:model="meeting_type">
                    @foreach (MeetingMinutes::MEETING_TYPES as $key => $label)
                        <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            {{-- Audio Player --}}
            @if ($this->audioUrl)
                <div class="sm:col-span-2">
                    <flux:label class="mb-1">{{ __('Audio Recording') }}</flux:label>
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800">
                        <audio src="{{ $this->audioUrl }}" controls class="w-full"></audio>
                        @if ($this->minutes->audio_duration)
                            <flux:text class="mt-1 text-xs text-zinc-500">{{ __('Duration:') }} {{ gmdate('i:s', (int) $this->minutes->audio_duration) }}</flux:text>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Attendees --}}
            <div class="sm:col-span-2">
                <flux:label class="mb-1">{{ __('Attendees') }}</flux:label>
                <div class="flex gap-2">
                    <flux:input wire:model="newAttendee" wire:keydown.enter.prevent="addAttendee" placeholder="Add attendee" size="sm" class="flex-1" />
                    <flux:button size="sm" variant="ghost" wire:click="addAttendee" icon="plus">{{ __('Add') }}</flux:button>
                </div>
                @if (count($attendees) > 0)
                    <div class="mt-2 flex flex-wrap gap-1">
                        @foreach ($attendees as $i => $attendee)
                            <flux:badge size="sm" variant="outline" class="gap-1">
                                {{ $attendee }}
                                @if ($this->canManage)
                                    <button wire:click="removeAttendee({{ $i }})" class="ml-1 text-zinc-400 hover:text-zinc-600">
                                        <flux:icon name="x-mark" variant="mini" class="size-3" />
                                    </button>
                                @endif
                            </flux:badge>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Executive Summary --}}
            <div class="sm:col-span-2"
                @if (in_array($this->minutes->summary_status, ['pending', 'processing']))
                    wire:poll.3s="$refresh"
                @endif
            >
                <div class="mb-1 flex items-center justify-between">
                    <flux:label>{{ __('Executive Summary') }}</flux:label>
                    @if ($this->canManage && $this->minutes->transcript)
                        <flux:button size="xs" variant="ghost" wire:click="regenerateSummary" icon="arrow-path"
                            :disabled="in_array($this->minutes->summary_status, ['pending', 'processing'])">
                            {{ __('Regenerate') }}
                        </flux:button>
                    @endif
                </div>
                @if (in_array($this->minutes->summary_status, ['pending', 'processing']))
                    <div class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                        <span class="size-4 animate-spin rounded-full border-2 border-amber-500 border-t-transparent"></span>
                        <flux:text class="text-sm text-amber-700 dark:text-amber-400">{{ __('Generating executive summary...') }}</flux:text>
                    </div>
                @else
                    <flux:textarea wire:model="executive_summary" rows="6" placeholder="Executive summary will be auto-generated after recording..." />
                @endif
            </div>

            {{-- Timestamped Transcript --}}
            <div class="sm:col-span-2">
                <flux:label class="mb-2">{{ __('Transcript') }}</flux:label>
                @if ($this->minutes->transcript_segments)
                    @php
                        $segments = $this->minutes->transcript_segments;
                    @endphp
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                        {{-- Horizontal scrollable timeline --}}
                        <div class="flex gap-0 overflow-x-auto p-3">
                            @foreach ($segments as $si => $segment)
                                @php
                                    $gap = $si > 0 ? (($segment['start'] ?? 0) - ($segments[$si - 1]['end'] ?? 0)) : 0;
                                @endphp
                                @if ($gap > 1.5 && $si > 0)
                                    <div class="flex shrink-0 flex-col items-center justify-center px-2">
                                        <div class="h-px w-6 bg-zinc-300 dark:bg-zinc-600"></div>
                                        <span class="mt-0.5 text-[10px] text-zinc-400">
                                            @if ($gap >= 60)
                                                {{ floor($gap / 60) }}m{{ (int)$gap % 60 }}s
                                            @else
                                                {{ number_format($gap, 0) }}s
                                            @endif
                                        </span>
                                    </div>
                                @endif
                                <div wire:key="seg-{{ $si }}" class="group shrink-0 rounded-lg border border-transparent px-3 py-2 transition hover:border-blue-200 hover:bg-blue-50 dark:hover:border-blue-800 dark:hover:bg-blue-900/20" style="max-width: 280px;">
                                    <div class="mb-1 flex items-center gap-2">
                                        <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 font-mono text-[11px] font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-400">
                                            {{ gmdate('i:s', (int) ($segment['start'] ?? 0)) }}
                                        </span>
                                        @if (!empty($segment['speaker']))
                                            <span class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400">{{ $segment['speaker'] }}</span>
                                        @endif
                                    </div>
                                    <p class="text-sm leading-snug text-zinc-700 dark:text-zinc-300">{{ $segment['text'] ?? '' }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <flux:textarea wire:model="transcript" rows="10" placeholder="Transcript will appear after audio processing..." />
                @endif
            </div>

            {{-- Editable Transcript --}}
            <div class="sm:col-span-2">
                <flux:field>
                    <flux:label>{{ __('Full Transcript') }} @if($this->minutes->transcript_segments) <span class="text-xs font-normal text-zinc-400">({{ __('editable') }})</span> @endif</flux:label>
                    <flux:textarea wire:model="transcript" rows="6" placeholder="Transcript will appear after audio processing..." />
                </flux:field>
            </div>

            {{-- Notes --}}
            <div class="sm:col-span-2">
                <flux:field>
                    <flux:label>{{ __('Notes') }}</flux:label>
                    <flux:textarea wire:model="notes" rows="3" />
                </flux:field>
            </div>
        </div>
    </div>
</div>
