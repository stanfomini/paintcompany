<?php

use App\Models\TrainingPath;
use App\Models\TrainingProgress;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => 'Training Dashboard'])]
class extends Component {
    #[Url]
    public string $pathFilter = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('media.manage_training'), 403);
    }

    #[Computed]
    public function paths()
    {
        return TrainingPath::query()
            ->active()
            ->withCount('steps')
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function selectedPath(): ?TrainingPath
    {
        if (! $this->pathFilter) {
            return $this->paths->first();
        }

        return TrainingPath::find($this->pathFilter);
    }

    #[Computed]
    public function employees()
    {
        if (! $this->selectedPath) {
            return collect();
        }

        return User::query()
            ->where('type', 'internal')
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                $user->completion = $this->selectedPath->getCompletionPercentageForUser($user);

                return $user;
            });
    }

    #[Computed]
    public function steps()
    {
        if (! $this->selectedPath) {
            return collect();
        }

        return $this->selectedPath->steps()
            ->with('video')
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function progressMap(): array
    {
        if (! $this->selectedPath) {
            return [];
        }

        $stepIds = $this->steps->pluck('id');

        return TrainingProgress::query()
            ->whereIn('training_path_step_id', $stepIds)
            ->get()
            ->groupBy(fn ($p) => $p->user_id.'-'.$p->training_path_step_id)
            ->map(fn ($group) => $group->first())
            ->all();
    }

    #[Computed]
    public function summaryStats(): array
    {
        $totalPaths = $this->paths->count();
        $totalEmployees = $this->employees->count();
        $avgCompletion = $totalEmployees > 0
            ? round($this->employees->avg('completion'), 1)
            : 0;

        return [
            'total_paths' => $totalPaths,
            'total_employees' => $totalEmployees,
            'avg_completion' => $avgCompletion,
        ];
    }

    public function getProgressForCell(int $userId, int $stepId): ?TrainingProgress
    {
        return $this->progressMap[$userId.'-'.$stepId] ?? null;
    }
};
?>

<div class="py-6 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="flex items-center gap-2 mb-6">
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('media.index', ['tab' => 'training'])" wire:navigate />
        <flux:heading size="xl">{{ __('Training Dashboard') }}</flux:heading>
    </div>

    @if($this->paths->count() > 0)
        {{-- Summary Stats --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6" data-summary-stats>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 p-4">
                <flux:text class="text-sm text-zinc-500">{{ __('Active Paths') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->summaryStats['total_paths'] }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 p-4">
                <flux:text class="text-sm text-zinc-500">{{ __('Employees') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->summaryStats['total_employees'] }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 p-4">
                <flux:text class="text-sm text-zinc-500">{{ __('Avg. Completion') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->summaryStats['avg_completion'] }}%</flux:heading>
            </div>
        </div>

        {{-- Path Filter --}}
        <div class="mb-6">
            <flux:select wire:model.live="pathFilter" class="max-w-xs">
                @foreach($this->paths as $path)
                    <flux:select.option value="{{ $path->id }}">{{ $path->name }} ({{ $path->steps_count }} {{ trans_choice('step|steps', $path->steps_count) }})</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if($this->selectedPath && $this->steps->count() > 0)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider sticky left-0 bg-white dark:bg-zinc-900">
                                {{ __('Employee') }}
                            </th>
                            @foreach($this->steps as $step)
                                <th class="px-3 py-3 text-center text-xs font-medium text-zinc-500 uppercase tracking-wider" title="{{ $step->title ?? $step->video?->title }}">
                                    {{ Str::limit($step->title ?? $step->video?->title ?? __('Step'), 15) }}
                                </th>
                            @endforeach
                            <th class="px-4 py-3 text-center text-xs font-medium text-zinc-500 uppercase tracking-wider">
                                {{ __('Overall') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach($this->employees as $employee)
                            <tr wire:key="emp-{{ $employee->id }}">
                                <td class="px-4 py-3 text-sm font-medium text-zinc-900 dark:text-zinc-100 sticky left-0 bg-white dark:bg-zinc-900 whitespace-nowrap">
                                    {{ $employee->name }}
                                </td>
                                @foreach($this->steps as $step)
                                    @php $progress = $this->getProgressForCell($employee->id, $step->id); @endphp
                                    <td class="px-3 py-3 text-center">
                                        @if($progress && $progress->completed_at)
                                            <flux:icon name="check-circle" class="h-5 w-5 text-green-500 mx-auto" />
                                        @elseif($progress && $progress->watch_percentage > 0)
                                            <span class="text-xs text-amber-600 dark:text-amber-400 font-medium">{{ $progress->watch_percentage }}%</span>
                                        @else
                                            <span class="text-xs text-zinc-300 dark:text-zinc-600">&mdash;</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-4 py-3 text-center">
                                    @php $completion = $employee->completion; @endphp
                                    <div class="flex items-center justify-center gap-2">
                                        <div class="w-16 h-1.5 bg-zinc-200 dark:bg-zinc-700 rounded-full overflow-hidden">
                                            <div class="h-full rounded-full {{ $completion >= 100 ? 'bg-green-500' : 'bg-blue-500' }}" style="width: {{ min($completion, 100) }}%"></div>
                                        </div>
                                        <span class="text-xs font-medium {{ $completion >= 100 ? 'text-green-600 dark:text-green-400' : 'text-zinc-600 dark:text-zinc-400' }}">
                                            {{ number_format($completion, 0) }}%
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-center py-12 border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                <flux:icon name="film" class="mx-auto h-10 w-10 text-zinc-400" />
                <flux:text class="mt-2 text-zinc-500">{{ __('This training path has no steps yet.') }}</flux:text>
            </div>
        @endif
    @else
        <div class="text-center py-12 border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
            <flux:icon name="academic-cap" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="sm" class="mt-3">{{ __('No active training paths') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500">{{ __('Create a training path first to see completion data.') }}</flux:text>
        </div>
    @endif
</div>
