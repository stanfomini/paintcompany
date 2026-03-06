<?php

use App\Models\MeetingMinutes;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $corporationId;
    public string $filterType = '';
    public bool $showRecorder = false;
    public bool $showEditor = false;
    public ?int $editingMinutesId = null;

    public function boot(): void
    {
        if (! Auth::user()?->can('business_plan.view')) {
            abort(403);
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('business_plan.manage') ?? false;
    }

    #[Computed]
    public function minutes(): \Illuminate\Database\Eloquent\Collection
    {
        $query = MeetingMinutes::where('corporation_id', $this->corporationId)
            ->orderByDesc('meeting_date');

        if ($this->filterType) {
            $query->where('meeting_type', $this->filterType);
        }

        return $query->get();
    }

    public function openRecorder(): void
    {
        $this->showRecorder = true;
        $this->showEditor = false;
    }

    public function closeRecorder(): void
    {
        $this->showRecorder = false;
        unset($this->minutes);
    }

    public function openEditor(int $id): void
    {
        $this->editingMinutesId = $id;
        $this->showEditor = true;
        $this->showRecorder = false;
    }

    public function closeEditor(): void
    {
        $this->showEditor = false;
        $this->editingMinutesId = null;
        unset($this->minutes);
    }

    public function deleteMinutes(int $id): void
    {
        if (! $this->canManage) {
            abort(403);
        }

        MeetingMinutes::where('id', $id)
            ->where('corporation_id', $this->corporationId)
            ->delete();

        unset($this->minutes);
    }
};
?>

<div>
    @if ($showRecorder)
        <div class="mb-4">
            <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="closeRecorder">
                {{ __('Back to Minutes') }}
            </flux:button>
        </div>
        <livewire:dynamic-component
            :component="'corporate.meeting-recorder'"
            :corporation-id="$corporationId"
            :key="'recorder-' . $corporationId"
        />
    @elseif ($showEditor && $editingMinutesId)
        <div class="mb-4">
            <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="closeEditor">
                {{ __('Back to Minutes') }}
            </flux:button>
        </div>
        <livewire:dynamic-component
            :component="'corporate.meeting-editor'"
            :minutes-id="$editingMinutesId"
            :key="'editor-' . $editingMinutesId"
        />
    @else
        {{-- Header --}}
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading size="lg">{{ __('Meeting Minutes') }}</flux:heading>
            <div class="flex items-center gap-2">
                <flux:select wire:model.live="filterType" size="sm" class="w-40">
                    <flux:select.option value="">{{ __('All Types') }}</flux:select.option>
                    @foreach (MeetingMinutes::MEETING_TYPES as $key => $label)
                        <flux:select.option :value="$key">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($this->canManage)
                    <flux:button variant="primary" size="sm" wire:click="openRecorder" icon="microphone">
                        {{ __('Record Meeting') }}
                    </flux:button>
                @endif
            </div>
        </div>

        {{-- Minutes List --}}
        @if ($this->minutes->isEmpty())
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon name="microphone" class="mx-auto size-12 text-zinc-300 dark:text-zinc-600" />
                <flux:heading size="sm" class="mt-3">{{ __('No meeting minutes yet') }}</flux:heading>
                <flux:text class="mt-1 text-sm text-zinc-500">{{ __('Record your first meeting to get started.') }}</flux:text>
            </div>
        @else
            <div class="space-y-3">
                @foreach ($this->minutes as $min)
                    <div wire:key="min-{{ $min->id }}" class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="flex items-center gap-2">
                                    <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">{{ $min->title }}</flux:text>
                                    <flux:badge size="sm" color="blue">{{ MeetingMinutes::MEETING_TYPES[$min->meeting_type] ?? $min->meeting_type }}</flux:badge>
                                </div>
                                <flux:text class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $min->meeting_date->format('M j, Y') }}
                                    @if ($min->attendees)
                                        &middot; {{ count($min->attendees) }} {{ __('attendees') }}
                                    @endif
                                    @if ($min->audio_duration)
                                        &middot; {{ gmdate('i:s', (int) $min->audio_duration) }}
                                    @endif
                                </flux:text>

                                {{-- Status indicators --}}
                                <div class="mt-2 flex gap-2">
                                    @if ($min->audio_path)
                                        <flux:badge size="sm" color="green" variant="outline">{{ __('Audio') }}</flux:badge>
                                    @endif
                                    @if ($min->transcript)
                                        <flux:badge size="sm" color="green" variant="outline">{{ __('Transcript') }}</flux:badge>
                                    @endif
                                    @if ($min->summary_status === 'completed')
                                        <flux:badge size="sm" color="green" variant="outline">{{ __('Summary') }}</flux:badge>
                                    @elseif ($min->summary_status === 'processing')
                                        <flux:badge size="sm" color="amber">
                                            <span class="flex items-center gap-1">
                                                <span class="size-1.5 animate-pulse rounded-full bg-amber-500"></span>
                                                {{ __('Generating Summary') }}
                                            </span>
                                        </flux:badge>
                                    @elseif ($min->summary_status === 'failed')
                                        <flux:badge size="sm" color="red" variant="outline">{{ __('Summary Failed') }}</flux:badge>
                                    @endif
                                </div>
                            </div>
                            <div class="flex gap-1">
                                <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="openEditor({{ $min->id }})">
                                    {{ __('Edit') }}
                                </flux:button>
                                <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('corporate.minutes.pdf', $min)">
                                    {{ __('PDF') }}
                                </flux:button>
                                @if ($this->canManage)
                                    <flux:button size="xs" variant="ghost" icon="trash" class="text-red-500" wire:click="deleteMinutes({{ $min->id }})" wire:confirm="{{ __('Delete these meeting minutes?') }}" />
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
