<?php

use App\Models\Job;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('jobs.view')) {
            abort(403);
        }
    }

    public string $search = '';

    public string $assignedCrewFilter = '';

    public function getListeners(): array
    {
        return [
            'job-updated' => 'refreshBoard',
            'job-created' => 'refreshBoard',
            "echo-private:internal-jobs,.job.stage.changed" => 'refreshBoard',
            "echo-private:internal-jobs,.job.created" => 'refreshBoard',
        ];
    }

    public function refreshBoard(): void
    {
        // Re-render is triggered automatically
    }

    /**
     * Get the stage colors for display.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function stageColors(): array
    {
        return [
            'estimate_accepted' => 'bg-blue-100 dark:bg-blue-900/50 border-blue-300 dark:border-blue-700',
            'scheduling_colors' => 'bg-purple-100 dark:bg-purple-900/50 border-purple-300 dark:border-purple-700',
            'job_prep' => 'bg-yellow-100 dark:bg-yellow-900/50 border-yellow-300 dark:border-yellow-700',
            'in_progress' => 'bg-orange-100 dark:bg-orange-900/50 border-orange-300 dark:border-orange-700',
            'completed' => 'bg-green-100 dark:bg-green-900/50 border-green-300 dark:border-green-700',
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function stageHeaderColors(): array
    {
        return [
            'estimate_accepted' => 'bg-blue-500',
            'scheduling_colors' => 'bg-purple-500',
            'job_prep' => 'bg-yellow-500',
            'in_progress' => 'bg-orange-500',
            'completed' => 'bg-green-500',
        ];
    }

    /**
     * Move a job to a new stage and position.
     */
    public function moveToStage(int $jobId, string $newStage, int $newPosition): void
    {
        abort_unless(auth()->user()->can('jobs.edit'), 403);

        if (! array_key_exists($newStage, Job::STAGES)) {
            return;
        }

        Job::findOrFail($jobId);

        $jobsInStage = Job::where('stage', $newStage)
            ->where('id', '!=', $jobId)
            ->orderBy('sort_order')
            ->pluck('id')
            ->toArray();

        array_splice($jobsInStage, $newPosition, 0, [$jobId]);

        foreach ($jobsInStage as $index => $id) {
            $updateData = ['sort_order' => $index];
            if ($id === $jobId) {
                $updateData['stage'] = $newStage;
            }
            Job::where('id', $id)->update($updateData);
        }
    }

    /**
     * @return array<string, Collection<int, Job>>
     */
    #[Computed]
    public function jobsByStage(): array
    {
        $query = Job::query()
            ->with(['deal.customer', 'estimate', 'acceptedCrew', 'salesPerson', 'productionManager'])
            ->when($this->search, function ($query) {
                return $query->where(function ($q) {
                    $q->where('title', 'like', '%' . $this->search . '%')
                        ->orWhere('job_number', 'like', '%' . $this->search . '%')
                        ->orWhereHas('deal.customer', function ($cq) {
                            $cq->where('first_name', 'like', '%' . $this->search . '%')
                                ->orWhere('last_name', 'like', '%' . $this->search . '%');
                        });
                });
            })
            ->when($this->assignedCrewFilter, function ($query) {
                return $query->whereHas('crews', function ($q) {
                    $q->where('crew_user_id', $this->assignedCrewFilter)
                        ->where('status', 'accepted');
                });
            })
            ->orderBy('sort_order')
            ->orderBy('created_at', 'desc');

        $jobs = $query->get();

        $jobsByStage = [];
        foreach (array_keys(Job::STAGES) as $stage) {
            $jobsByStage[$stage] = $jobs->where('stage', $stage)->values();
        }

        return $jobsByStage;
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function crewUsers(): Collection
    {
        return User::query()
            ->where('type', 'crew')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function stages(): array
    {
        return Job::STAGES;
    }
}; ?>

<div>
    <div class="max-w-full mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Job Progress') }}</flux:heading>
            @can('invoices.view')
                <flux:button :href="route('invoices.index')" variant="ghost" icon="document-currency-dollar" size="sm" wire:navigate>
                    {{ __('Invoices') }}
                </flux:button>
            @endcan
        </div>

        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    :placeholder="__('Search jobs...')"
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="assignedCrewFilter">
                    <option value="">{{ __('All Crews') }}</option>
                    @foreach ($this->crewUsers as $crew)
                        <option value="{{ $crew->id }}">{{ $crew->name }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        <div class="overflow-x-auto pb-4">
            <div class="flex gap-4 min-w-max" id="job-kanban-board">
                @foreach ($this->stages as $stageKey => $stageLabel)
                    <div class="w-72 flex-shrink-0">
                        <div class="rounded-lg border {{ $this->stageColors[$stageKey] }} overflow-hidden">
                            <div class="px-4 py-3 {{ $this->stageHeaderColors[$stageKey] }}">
                                <div class="flex items-center justify-between">
                                    <flux:heading size="sm" class="text-white font-semibold">
                                        {{ $stageLabel }}
                                    </flux:heading>
                                    <flux:badge size="sm" class="bg-white/20 text-white">
                                        {{ $this->jobsByStage[$stageKey]->count() }}
                                    </flux:badge>
                                </div>
                            </div>

                            <div
                                class="job-kanban-column p-2 min-h-[200px] space-y-2"
                                data-stage="{{ $stageKey }}"
                            >
                                @forelse ($this->jobsByStage[$stageKey] as $job)
                                    <div
                                        data-job-id="{{ $job->id }}"
                                        wire:key="job-{{ $job->id }}"
                                        class="bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700 shadow-sm hover:shadow-md transition-shadow"
                                    >
                                        <div class="flex items-start gap-2 p-3">
                                            <div class="job-kanban-handle cursor-grab active:cursor-grabbing shrink-0 pt-0.5 text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-6 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm6 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></svg>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-start justify-between mb-1">
                                                    <flux:text class="font-medium text-sm truncate flex-1">
                                                        {{ $job->title }}
                                                    </flux:text>
                                                    <div class="job-kanban-no-drag ml-1">
                                                        <flux:dropdown>
                                                            <flux:button variant="ghost" size="xs" icon="ellipsis-vertical" />
                                                            <flux:menu>
                                                                <flux:menu.item icon="eye" href="{{ route('jobs.show', $job) }}" wire:navigate>
                                                                    {{ __('View') }}
                                                                </flux:menu.item>
                                                            </flux:menu>
                                                        </flux:dropdown>
                                                    </div>
                                                </div>

                                                <flux:text class="text-xs text-zinc-400 dark:text-zinc-500 mb-2">
                                                    {{ $job->job_number }}
                                                </flux:text>

                                                @if ($job->deal?->customer)
                                                    <div class="flex items-center text-xs text-zinc-500 dark:text-zinc-400 mb-1">
                                                        <flux:icon name="user" class="h-3 w-3 mr-1" />
                                                        {{ $job->deal->customer->full_name }}
                                                    </div>
                                                @endif

                                                @if ($job->salesPerson || $job->productionManager)
                                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-zinc-400 dark:text-zinc-500 mb-2">
                                                        @if ($job->salesPerson)
                                                            <span class="flex items-center" title="{{ __('Sales') }}: {{ $job->salesPerson->name }}">
                                                                <flux:icon name="currency-dollar" class="h-3 w-3 mr-0.5" />
                                                                {{ Str::before($job->salesPerson->name, ' ') }}
                                                            </span>
                                                        @endif
                                                        @if ($job->productionManager)
                                                            <span class="flex items-center" title="{{ __('PM') }}: {{ $job->productionManager->name }}">
                                                                <flux:icon name="wrench-screwdriver" class="h-3 w-3 mr-0.5" />
                                                                {{ Str::before($job->productionManager->name, ' ') }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                @endif

                                                <div class="flex items-center justify-between">
                                                    @if ($job->scheduled_date)
                                                        <flux:badge size="sm" color="blue">
                                                            {{ $job->scheduled_date->format('M j') }}
                                                        </flux:badge>
                                                    @else
                                                        <span></span>
                                                    @endif

                                                    @if ($job->acceptedCrew->count() > 0)
                                                        <div class="flex -space-x-1">
                                                            @foreach ($job->acceptedCrew->take(3) as $crew)
                                                                <flux:tooltip content="{{ $crew->name }}">
                                                                    <flux:avatar size="xs" name="{{ $crew->name }}" />
                                                                </flux:tooltip>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>

                                                @if ($job->isReadyForDispatch() && $job->stage === 'scheduling_colors')
                                                    <div class="mt-2">
                                                        <flux:badge size="sm" color="green">{{ __('Ready for Dispatch') }}</flux:badge>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="text-center py-8 text-zinc-400 dark:text-zinc-500 text-sm">
                                        {{ __('No jobs') }}
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

@script
<script>
    let sortableInstances = [];

    function initJobSortable() {
        sortableInstances.forEach(s => s.destroy());
        sortableInstances = [];

        const columns = $wire.$el.querySelectorAll('.job-kanban-column');
        columns.forEach(column => {
            const sortable = new Sortable(column, {
                group: 'job-kanban',
                handle: '.job-kanban-handle',
                filter: '.job-kanban-no-drag',
                preventOnFilter: false,
                animation: 200,
                ghostClass: 'opacity-30',
                dragClass: 'shadow-lg',
                scroll: true,
                scrollSensitivity: 80,
                onEnd: (evt) => {
                    const jobId = parseInt(evt.item.dataset.jobId);
                    const newStage = evt.to.dataset.stage;
                    const newPosition = evt.newIndex;

                    if (!jobId || !newStage) return;

                    $wire.moveToStage(jobId, newStage, newPosition);
                }
            });
            sortableInstances.push(sortable);
        });
    }

    initJobSortable();

    $wire.$hook('morph.updated', ({ el }) => {
        if (el === $wire.$el) {
            requestAnimationFrame(() => initJobSortable());
        }
    });
</script>
@endscript
