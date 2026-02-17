<?php

use App\Models\TrainingPath;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'My Training'])]
class extends Component {
    #[Computed]
    public function paths()
    {
        $user = auth()->user();

        return TrainingPath::query()
            ->active()
            ->where(function ($q) use ($user) {
                $q->where('visibility', 'all_internal');
                if ($user->type === 'internal') {
                    $q->orWhere('visibility', 'employees');
                }
                if ($user->type === 'crew') {
                    $q->orWhere('visibility', 'crews');
                }
            })
            ->with(['steps' => fn ($q) => $q->orderBy('sort_order')])
            ->with(['steps.video'])
            ->orderBy('sort_order')
            ->get()
            ->map(function ($path) use ($user) {
                $path->completion = $path->getCompletionPercentageForUser($user);

                return $path;
            });
    }
};
?>

<div class="py-6 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <flux:heading size="xl" class="mb-6">{{ __('My Training') }}</flux:heading>

    @if($this->paths->count() > 0)
        <div class="space-y-4">
            @foreach($this->paths as $path)
                <div wire:key="path-{{ $path->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 p-6" data-training-path="{{ $path->name }}">
                    <div class="flex items-center justify-between mb-3">
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">{{ $path->name }}</flux:heading>
                            @if($path->is_required)
                                <flux:badge size="sm" color="red">{{ __('Required') }}</flux:badge>
                            @endif
                            @if($path->completion >= 100)
                                <flux:badge size="sm" color="green">{{ __('Complete') }}</flux:badge>
                            @endif
                        </div>
                        <span class="text-sm font-medium {{ $path->completion >= 100 ? 'text-green-600 dark:text-green-400' : 'text-zinc-600 dark:text-zinc-400' }}">
                            {{ number_format($path->completion, 0) }}%
                        </span>
                    </div>

                    @if($path->description)
                        <flux:text class="text-sm text-zinc-500 mb-3">{{ $path->description }}</flux:text>
                    @endif

                    {{-- Progress bar --}}
                    <div class="w-full h-2 bg-zinc-200 dark:bg-zinc-700 rounded-full overflow-hidden mb-4">
                        <div class="h-full rounded-full transition-all {{ $path->completion >= 100 ? 'bg-green-500' : 'bg-blue-500' }}" style="width: {{ min($path->completion, 100) }}%"></div>
                    </div>

                    {{-- Steps list --}}
                    <div class="space-y-2">
                        @foreach($path->steps as $step)
                            @php
                                $progress = \App\Models\TrainingProgress::where('user_id', auth()->id())
                                    ->where('training_path_step_id', $step->id)
                                    ->first();
                                $isCompleted = $progress && $progress->completed_at;
                                $watchPct = $progress?->watch_percentage ?? 0;
                            @endphp
                            <a
                                href="{{ route('my.training.play', [$path, $step]) }}"
                                wire:navigate
                                class="flex items-center gap-3 py-2 px-3 rounded-lg hover:bg-zinc-50 dark:hover:bg-zinc-800 transition"
                            >
                                <div class="shrink-0">
                                    @if($isCompleted)
                                        <flux:icon name="check-circle" class="h-5 w-5 text-green-500" />
                                    @elseif($watchPct > 0)
                                        <div class="h-5 w-5 rounded-full border-2 border-blue-500 flex items-center justify-center">
                                            <span class="text-[8px] font-bold text-blue-500">{{ $watchPct }}</span>
                                        </div>
                                    @else
                                        <div class="h-5 w-5 rounded-full border-2 border-zinc-300 dark:border-zinc-600"></div>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <flux:text class="text-sm font-medium {{ $isCompleted ? 'line-through text-zinc-400' : '' }}">
                                        {{ $step->title ?? $step->video?->title ?? __('Untitled Step') }}
                                    </flux:text>
                                </div>
                                @if($step->video?->formatted_duration)
                                    <flux:text class="text-xs text-zinc-400 shrink-0">{{ $step->video->formatted_duration }}</flux:text>
                                @endif
                                @if($step->is_required)
                                    <flux:badge size="sm" color="zinc">{{ __('Required') }}</flux:badge>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-12 border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
            <flux:icon name="academic-cap" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="sm" class="mt-3">{{ __('No training assigned') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('You have no training paths assigned at this time.') }}</flux:text>
        </div>
    @endif
</div>
