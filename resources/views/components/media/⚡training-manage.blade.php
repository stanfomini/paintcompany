<?php

use App\Models\TrainingPath;
use App\Models\TrainingPathStep;
use App\Models\VideoRecording;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Manage Training Path'])]
class extends Component {
    public TrainingPath $trainingPath;

    public string $name = '';

    public string $description = '';

    public string $visibility = 'all_internal';

    public bool $isRequired = false;

    public bool $showAddStepModal = false;

    public string $stepSearch = '';

    public function mount(TrainingPath $trainingPath): void
    {
        abort_unless(auth()->user()?->can('media.manage_training'), 403);

        $this->trainingPath = $trainingPath;
        $this->name = $trainingPath->name;
        $this->description = $trainingPath->description ?? '';
        $this->visibility = $trainingPath->visibility;
        $this->isRequired = $trainingPath->is_required;
    }

    #[Computed]
    public function steps()
    {
        return $this->trainingPath->steps()->with('video')->orderBy('sort_order')->get();
    }

    #[Computed]
    public function availableVideos()
    {
        $existingVideoIds = $this->trainingPath->steps()->pluck('video_recording_id');

        return VideoRecording::query()
            ->where('status', 'ready')
            ->whereNull('recordable_type')
            ->whereNotIn('id', $existingVideoIds)
            ->when($this->stepSearch, fn ($q) => $q->where('title', 'like', '%'.$this->stepSearch.'%'))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    public function saveDetails(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['required', 'in:employees,crews,all_internal'],
            'isRequired' => ['boolean'],
        ]);

        $this->trainingPath->update([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'visibility' => $this->visibility,
            'is_required' => $this->isRequired,
        ]);

        session()->flash('message', __('Training path updated.'));
    }

    public function addStep(int $videoId): void
    {
        $video = VideoRecording::where('status', 'ready')->findOrFail($videoId);

        $maxOrder = $this->trainingPath->steps()->max('sort_order') ?? -1;

        TrainingPathStep::create([
            'training_path_id' => $this->trainingPath->id,
            'video_recording_id' => $video->id,
            'title' => $video->title,
            'sort_order' => $maxOrder + 1,
            'is_required' => true,
        ]);

        $this->showAddStepModal = false;
        $this->stepSearch = '';
        unset($this->steps, $this->availableVideos);
    }

    public function updateStepTitle(int $stepId, string $title): void
    {
        $step = $this->trainingPath->steps()->findOrFail($stepId);
        $step->update(['title' => $title ?: null]);
        unset($this->steps);
    }

    public function toggleStepRequired(int $stepId): void
    {
        $step = $this->trainingPath->steps()->findOrFail($stepId);
        $step->update(['is_required' => ! $step->is_required]);
        unset($this->steps);
    }

    public function moveStepUp(int $stepId): void
    {
        $steps = $this->trainingPath->steps()->orderBy('sort_order')->get();
        $index = $steps->search(fn ($s) => $s->id === $stepId);

        if ($index > 0) {
            $steps[$index]->update(['sort_order' => $steps[$index - 1]->sort_order]);
            $steps[$index - 1]->update(['sort_order' => $steps[$index - 1]->sort_order + 1]);
        }

        unset($this->steps);
    }

    public function moveStepDown(int $stepId): void
    {
        $steps = $this->trainingPath->steps()->orderBy('sort_order')->get();
        $index = $steps->search(fn ($s) => $s->id === $stepId);

        if ($index !== false && $index < $steps->count() - 1) {
            $steps[$index]->update(['sort_order' => $steps[$index + 1]->sort_order]);
            $steps[$index + 1]->update(['sort_order' => $steps[$index + 1]->sort_order - 1]);
        }

        unset($this->steps);
    }

    public function removeStep(int $stepId): void
    {
        $step = $this->trainingPath->steps()->findOrFail($stepId);
        $step->delete();
        unset($this->steps, $this->availableVideos);
    }
};
?>

<div class="py-6 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="flex items-center gap-2 mb-6">
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('media.index', ['tab' => 'training'])" wire:navigate />
        <flux:heading size="xl">{{ $trainingPath->name }}</flux:heading>
    </div>

    @if(session('message'))
        <flux:callout variant="success" class="mb-4">{{ session('message') }}</flux:callout>
    @endif

    {{-- Path Details Form --}}
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 p-6 mb-6">
        <flux:heading size="lg" class="mb-4">{{ __('Path Details') }}</flux:heading>
        <form wire:submit="saveDetails" class="space-y-4">
            <flux:field>
                <flux:label>{{ __('Name') }}</flux:label>
                <flux:input wire:model="name" />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Description') }}</flux:label>
                <flux:textarea wire:model="description" rows="3" />
                <flux:error name="description" />
            </flux:field>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Visibility') }}</flux:label>
                    <flux:select wire:model="visibility">
                        <flux:select.option value="all_internal">{{ __('All Internal') }}</flux:select.option>
                        <flux:select.option value="employees">{{ __('Employees Only') }}</flux:select.option>
                        <flux:select.option value="crews">{{ __('Crews Only') }}</flux:select.option>
                    </flux:select>
                </flux:field>

                <flux:field class="flex items-end pb-2">
                    <flux:checkbox wire:model="isRequired" label="{{ __('Required for assigned users') }}" />
                </flux:field>
            </div>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">{{ __('Save Details') }}</flux:button>
            </div>
        </form>
    </div>

    {{-- Steps List --}}
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 p-6">
        <div class="flex items-center justify-between mb-4">
            <flux:heading size="lg">{{ __('Steps') }} ({{ $this->steps->count() }})</flux:heading>
            <flux:button size="sm" variant="primary" icon="plus" wire:click="$set('showAddStepModal', true)">
                {{ __('Add Step') }}
            </flux:button>
        </div>

        @if($this->steps->count() > 0)
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach($this->steps as $index => $step)
                    <div wire:key="step-{{ $step->id }}" class="py-3 flex items-center gap-4" data-training-step="{{ $step->title ?? $step->video?->title }}">
                        {{-- Sort controls --}}
                        <div class="flex flex-col gap-0.5 shrink-0">
                            <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="moveStepUp({{ $step->id }})" :disabled="$index === 0" />
                            <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="moveStepDown({{ $step->id }})" :disabled="$index === $this->steps->count() - 1" />
                        </div>

                        {{-- Step number --}}
                        <span class="text-sm font-medium text-zinc-400 w-6 text-center shrink-0">{{ $index + 1 }}</span>

                        {{-- Thumbnail --}}
                        <div class="w-16 h-10 bg-zinc-100 dark:bg-zinc-800 rounded overflow-hidden shrink-0">
                            @if($step->video?->getThumbnailUrl())
                                <img src="{{ $step->video->getThumbnailUrl() }}" class="w-full h-full object-cover" />
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <flux:icon name="video-camera" class="h-4 w-4 text-zinc-400" />
                                </div>
                            @endif
                        </div>

                        {{-- Title --}}
                        <div class="flex-1 min-w-0">
                            <input
                                type="text"
                                value="{{ $step->title ?? $step->video?->title }}"
                                class="text-sm font-medium bg-transparent border-0 p-0 w-full focus:ring-0 text-zinc-900 dark:text-zinc-100 placeholder-zinc-400"
                                placeholder="{{ __('Step title') }}"
                                wire:blur="updateStepTitle({{ $step->id }}, $event.target.value)"
                            />
                            @if($step->video?->formatted_duration)
                                <flux:text class="text-xs text-zinc-500">{{ $step->video->formatted_duration }}</flux:text>
                            @endif
                        </div>

                        {{-- Badges and actions --}}
                        <div class="flex items-center gap-2 shrink-0">
                            @if($step->is_required)
                                <flux:badge size="sm" color="red">{{ __('Required') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('Optional') }}</flux:badge>
                            @endif
                            <flux:button size="xs" variant="ghost" wire:click="toggleStepRequired({{ $step->id }})">
                                {{ $step->is_required ? __('Make Optional') : __('Make Required') }}
                            </flux:button>
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeStep({{ $step->id }})" wire:confirm="{{ __('Remove this step from the training path?') }}" />
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-8">
                <flux:icon name="film" class="mx-auto h-10 w-10 text-zinc-400" />
                <flux:text class="mt-2 text-zinc-500">{{ __('No steps yet. Add videos to create the training sequence.') }}</flux:text>
            </div>
        @endif
    </div>

    {{-- Add Step Modal --}}
    <flux:modal wire:model="showAddStepModal" class="max-w-lg">
        <flux:heading size="lg" class="mb-4">{{ __('Add Video Step') }}</flux:heading>
        <flux:input wire:model.live.debounce.300ms="stepSearch" placeholder="{{ __('Search videos...') }}" icon="magnifying-glass" class="mb-4" />

        @if($this->availableVideos->count() > 0)
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700 max-h-80 overflow-y-auto">
                @foreach($this->availableVideos as $video)
                    <button wire:key="available-{{ $video->id }}" wire:click="addStep({{ $video->id }})" class="w-full flex items-center gap-3 py-3 px-2 hover:bg-zinc-50 dark:hover:bg-zinc-800 rounded text-left transition">
                        <div class="w-16 h-10 bg-zinc-100 dark:bg-zinc-800 rounded overflow-hidden shrink-0">
                            @if($video->getThumbnailUrl())
                                <img src="{{ $video->getThumbnailUrl() }}" class="w-full h-full object-cover" />
                            @else
                                <div class="w-full h-full flex items-center justify-center">
                                    <flux:icon name="video-camera" class="h-4 w-4 text-zinc-400" />
                                </div>
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <flux:text class="font-medium truncate">{{ $video->title ?? __('Untitled Video') }}</flux:text>
                            @if($video->formatted_duration)
                                <flux:text class="text-xs text-zinc-500">{{ $video->formatted_duration }}</flux:text>
                            @endif
                        </div>
                    </button>
                @endforeach
            </div>
        @else
            <flux:text class="text-center py-6 text-zinc-500">{{ __('No available videos found.') }}</flux:text>
        @endif
    </flux:modal>
</div>
