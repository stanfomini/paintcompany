<?php

use App\Models\TrainingPath;
use App\Models\TrainingPathStep;
use App\Models\TrainingProgress;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Training'])]
class extends Component {
    public TrainingPath $trainingPath;

    public TrainingPathStep $step;

    public int $watchPercentage = 0;

    public bool $isCompleted = false;

    public function mount(TrainingPath $trainingPath, TrainingPathStep $step): void
    {
        abort_unless($step->training_path_id === $trainingPath->id, 404);

        $this->trainingPath = $trainingPath;
        $this->step = $step->load('video');

        $progress = $this->getOrCreateProgress();
        $this->watchPercentage = $progress->watch_percentage;
        $this->isCompleted = (bool) $progress->completed_at;
    }

    #[Computed]
    public function allSteps()
    {
        return $this->trainingPath->steps()->orderBy('sort_order')->get();
    }

    #[Computed]
    public function previousStep(): ?TrainingPathStep
    {
        return $this->allSteps
            ->filter(fn ($s) => $s->sort_order < $this->step->sort_order)
            ->last();
    }

    #[Computed]
    public function nextStep(): ?TrainingPathStep
    {
        return $this->allSteps
            ->filter(fn ($s) => $s->sort_order > $this->step->sort_order)
            ->first();
    }

    public function updateProgress(int $percentage): void
    {
        $percentage = max(0, min(100, $percentage));
        $progress = $this->getOrCreateProgress();

        if ($percentage > $progress->watch_percentage) {
            $progress->update(['watch_percentage' => $percentage]);
        }

        if ($percentage >= 90 && ! $progress->completed_at) {
            $progress->markCompleted();
            $this->isCompleted = true;
        }

        $this->watchPercentage = max($this->watchPercentage, $percentage);
    }

    private function getOrCreateProgress(): TrainingProgress
    {
        return TrainingProgress::firstOrCreate(
            [
                'user_id' => auth()->id(),
                'training_path_step_id' => $this->step->id,
            ],
            [
                'watch_percentage' => 0,
            ]
        );
    }
};
?>

<div class="py-6 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    {{-- Breadcrumb --}}
    <div class="flex items-center gap-2 mb-4">
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('my.training')" wire:navigate />
        <flux:text class="text-sm text-zinc-500">{{ $trainingPath->name }}</flux:text>
    </div>

    {{-- Video Player --}}
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-black overflow-hidden mb-6" style="max-height: 80vh;">
        @if($step->video?->getVideoUrl())
            <video
                class="w-full h-full object-contain"
                controls
                preload="metadata"
                @if($step->video->getThumbnailUrl()) poster="{{ $step->video->getThumbnailUrl() }}" @endif
                x-data="{
                    lastReported: 0,
                    handleTimeUpdate(e) {
                        const video = e.target;
                        if (video.duration <= 0) return;
                        const pct = Math.round((video.currentTime / video.duration) * 100);
                        if (pct >= this.lastReported + 10) {
                            this.lastReported = pct;
                            $wire.updateProgress(pct);
                        }
                    }
                }"
                x-on:timeupdate="handleTimeUpdate($event)"
                x-on:ended="$wire.updateProgress(100)"
            >
                <source src="{{ $step->video->getVideoUrl() }}" type="{{ $step->video->mime_type ?? 'video/mp4' }}" />
            </video>
        @else
            <div class="aspect-video flex items-center justify-center">
                <flux:text class="text-zinc-400">{{ __('Video not available') }}</flux:text>
            </div>
        @endif
    </div>

    {{-- Step Info --}}
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 p-6 mb-6">
        <div class="flex items-center justify-between mb-2">
            <flux:heading size="lg">{{ $step->title ?? $step->video?->title ?? __('Untitled Step') }}</flux:heading>
            <div class="flex items-center gap-2">
                @if($isCompleted)
                    <flux:badge size="sm" color="green">{{ __('Completed') }}</flux:badge>
                @elseif($watchPercentage > 0)
                    <flux:badge size="sm" color="blue">{{ $watchPercentage }}% {{ __('watched') }}</flux:badge>
                @endif
                @if($step->is_required)
                    <flux:badge size="sm" color="zinc">{{ __('Required') }}</flux:badge>
                @endif
            </div>
        </div>

        @if($step->description)
            <flux:text class="text-zinc-500">{{ $step->description }}</flux:text>
        @endif

        {{-- Progress bar --}}
        <div class="mt-4">
            <div class="w-full h-1.5 bg-zinc-200 dark:bg-zinc-700 rounded-full overflow-hidden">
                <div class="h-full rounded-full transition-all {{ $isCompleted ? 'bg-green-500' : 'bg-blue-500' }}" style="width: {{ min($watchPercentage, 100) }}%"></div>
            </div>
        </div>
    </div>

    {{-- Navigation --}}
    <div class="flex items-center justify-between">
        <div>
            @if($this->previousStep)
                <flux:button variant="ghost" icon="arrow-left" :href="route('my.training.play', [$trainingPath, $this->previousStep])" wire:navigate>
                    {{ __('Previous') }}
                </flux:button>
            @endif
        </div>
        <div>
            @if($this->nextStep)
                <flux:button variant="primary" icon-trailing="arrow-right" :href="route('my.training.play', [$trainingPath, $this->nextStep])" wire:navigate>
                    {{ __('Next Step') }}
                </flux:button>
            @else
                <flux:button variant="primary" icon="check" :href="route('my.training')" wire:navigate>
                    {{ __('Back to Training') }}
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Step list sidebar --}}
    <div class="mt-8 border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 p-4">
        <flux:heading size="sm" class="mb-3">{{ __('All Steps') }}</flux:heading>
        <div class="space-y-1">
            @foreach($this->allSteps as $s)
                @php
                    $sp = \App\Models\TrainingProgress::where('user_id', auth()->id())
                        ->where('training_path_step_id', $s->id)
                        ->first();
                    $sCom = $sp && $sp->completed_at;
                    $isCurrent = $s->id === $step->id;
                @endphp
                <a
                    href="{{ route('my.training.play', [$trainingPath, $s]) }}"
                    wire:navigate
                    wire:key="nav-step-{{ $s->id }}"
                    class="flex items-center gap-2 py-1.5 px-2 rounded text-sm {{ $isCurrent ? 'bg-blue-50 dark:bg-blue-900/20 font-medium' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800' }} transition"
                >
                    @if($sCom)
                        <flux:icon name="check-circle" class="h-4 w-4 text-green-500 shrink-0" />
                    @elseif($isCurrent)
                        <flux:icon name="play" class="h-4 w-4 text-blue-500 shrink-0" />
                    @else
                        <div class="h-4 w-4 rounded-full border border-zinc-300 dark:border-zinc-600 shrink-0"></div>
                    @endif
                    <span class="truncate {{ $sCom ? 'text-zinc-400' : '' }}">{{ $s->title ?? $s->video?->title ?? __('Step') }}</span>
                </a>
            @endforeach
        </div>
    </div>
</div>
