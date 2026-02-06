<?php

use App\Models\RoadmapItem;
use App\Models\RoadmapPhase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public array $expandedPhases = [];

    public function mount(): void
    {
        // Expand the first active phase by default
        $firstPhase = RoadmapPhase::active()->ordered()->first();
        if ($firstPhase) {
            $this->expandedPhases[$firstPhase->id] = true;
        }
    }

    public function boot(): void
    {
        $user = Auth::user();

        if (! $user || ! $user->canViewRoadmap()) {
            abort(403, 'You do not have permission to view the roadmap.');
        }
    }

    #[Computed]
    public function canEdit(): bool
    {
        return Auth::user()?->canEditRoadmap() ?? false;
    }

    /**
     * @return Collection<int, RoadmapPhase>
     */
    #[Computed]
    public function phases(): Collection
    {
        return RoadmapPhase::query()
            ->with(['categories.items.assignee'])
            ->ordered()
            ->get();
    }

    #[Computed]
    public function overallProgress(): float
    {
        $total = RoadmapItem::count();
        if ($total === 0) {
            return 0;
        }

        $done = RoadmapItem::where('status', RoadmapItem::STATUS_DONE)->count();

        return round(($done / $total) * 100, 1);
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => RoadmapItem::count(),
            'done' => RoadmapItem::where('status', RoadmapItem::STATUS_DONE)->count(),
            'in_progress' => RoadmapItem::where('status', RoadmapItem::STATUS_IN_PROGRESS)->count(),
            'not_started' => RoadmapItem::where('status', RoadmapItem::STATUS_NOT_STARTED)->count(),
        ];
    }

    public function togglePhase(int $phaseId): void
    {
        if (isset($this->expandedPhases[$phaseId])) {
            unset($this->expandedPhases[$phaseId]);
        } else {
            $this->expandedPhases[$phaseId] = true;
        }
    }

    public function cycleStatus(int $itemId): void
    {
        if (! $this->canEdit) {
            return;
        }

        $item = RoadmapItem::findOrFail($itemId);

        // Cycle: not_started -> in_progress -> done -> not_started
        $newStatus = match ($item->status) {
            RoadmapItem::STATUS_NOT_STARTED => RoadmapItem::STATUS_IN_PROGRESS,
            RoadmapItem::STATUS_IN_PROGRESS => RoadmapItem::STATUS_DONE,
            RoadmapItem::STATUS_DONE => RoadmapItem::STATUS_NOT_STARTED,
            default => RoadmapItem::STATUS_NOT_STARTED,
        };

        if ($newStatus === RoadmapItem::STATUS_DONE) {
            $item->markAsDone();
        } elseif ($newStatus === RoadmapItem::STATUS_IN_PROGRESS) {
            $item->markAsInProgress();
        } else {
            $item->markAsNotStarted();
        }

        // Update parent phase status
        $item->category->phase->updateStatusFromItems();

        // Clear computed property cache
        unset($this->phases);
        unset($this->overallProgress);
        unset($this->stats);
    }

    #[Computed]
    public function phaseColors(): array
    {
        return [
            'not_started' => 'bg-zinc-100 dark:bg-zinc-800 border-zinc-300 dark:border-zinc-600',
            'in_progress' => 'bg-blue-50 dark:bg-blue-900/20 border-blue-300 dark:border-blue-700',
            'completed' => 'bg-green-50 dark:bg-green-900/20 border-green-300 dark:border-green-700',
        ];
    }

    #[Computed]
    public function phaseHeaderColors(): array
    {
        return [
            'not_started' => 'bg-zinc-500',
            'in_progress' => 'bg-blue-500',
            'completed' => 'bg-green-500',
        ];
    }
}; ?>

<div>
    <div class="max-w-5xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div>
                <flux:heading size="xl">Business Roadmap</flux:heading>
                <flux:text class="text-zinc-500 dark:text-zinc-400 mt-1">
                    Track your progress through the Master Business Plan
                </flux:text>
            </div>
        </div>

        {{-- Overall Progress --}}
        <div class="bg-white dark:bg-zinc-800 rounded-lg border border-zinc-200 dark:border-zinc-700 p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="lg">Overall Progress</flux:heading>
                <flux:badge size="lg" color="{{ $this->overallProgress >= 100 ? 'green' : ($this->overallProgress > 0 ? 'blue' : 'zinc') }}">
                    {{ $this->overallProgress }}%
                </flux:badge>
            </div>

            <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-4 mb-4">
                <div
                    class="h-4 rounded-full transition-all duration-500 {{ $this->overallProgress >= 100 ? 'bg-green-500' : 'bg-blue-500' }}"
                    style="width: {{ $this->overallProgress }}%"
                ></div>
            </div>

            <div class="grid grid-cols-3 gap-4 text-center">
                <div>
                    <flux:text class="text-2xl font-bold text-green-600 dark:text-green-400">
                        {{ $this->stats['done'] }}
                    </flux:text>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Completed</flux:text>
                </div>
                <div>
                    <flux:text class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                        {{ $this->stats['in_progress'] }}
                    </flux:text>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">In Progress</flux:text>
                </div>
                <div>
                    <flux:text class="text-2xl font-bold text-zinc-600 dark:text-zinc-400">
                        {{ $this->stats['not_started'] }}
                    </flux:text>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Not Started</flux:text>
                </div>
            </div>
        </div>

        {{-- Phases --}}
        <div class="space-y-4">
            @foreach ($this->phases as $phase)
                <div
                    wire:key="phase-{{ $phase->id }}"
                    class="rounded-lg border overflow-hidden {{ $this->phaseColors[$phase->status] ?? $this->phaseColors['not_started'] }}"
                >
                    {{-- Phase Header --}}
                    <button
                        wire:click="togglePhase({{ $phase->id }})"
                        class="w-full flex items-center justify-between p-4 hover:bg-black/5 dark:hover:bg-white/5 transition-colors"
                    >
                        <div class="flex items-center gap-4">
                            <div class="w-3 h-3 rounded-full {{ $this->phaseHeaderColors[$phase->status] ?? $this->phaseHeaderColors['not_started'] }}"></div>
                            <div class="text-left">
                                <flux:heading size="base">{{ $phase->name }}</flux:heading>
                                @if ($phase->description)
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 mt-0.5">
                                        {{ $phase->description }}
                                    </flux:text>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            {{-- Progress --}}
                            <div class="flex items-center gap-2">
                                <div class="w-24 bg-zinc-300 dark:bg-zinc-600 rounded-full h-2">
                                    <div
                                        class="h-2 rounded-full transition-all {{ $phase->progress_percent >= 100 ? 'bg-green-500' : 'bg-blue-500' }}"
                                        style="width: {{ $phase->progress_percent }}%"
                                    ></div>
                                </div>
                                <flux:text class="text-sm font-medium w-12 text-right">
                                    {{ $phase->progress_percent }}%
                                </flux:text>
                            </div>

                            {{-- Expand/Collapse Icon --}}
                            <flux:icon
                                name="chevron-down"
                                class="h-5 w-5 transition-transform {{ isset($this->expandedPhases[$phase->id]) ? 'rotate-180' : '' }}"
                            />
                        </div>
                    </button>

                    {{-- Phase Content (Categories & Items) --}}
                    @if (isset($this->expandedPhases[$phase->id]))
                        <div class="border-t border-zinc-200 dark:border-zinc-600">
                            @foreach ($phase->categories as $category)
                                <div wire:key="category-{{ $category->id }}" class="border-b border-zinc-200 dark:border-zinc-600 last:border-b-0">
                                    {{-- Category Header --}}
                                    <div class="px-6 py-3 bg-zinc-50 dark:bg-zinc-800/50 flex items-center justify-between">
                                        <flux:heading size="sm">{{ $category->name }}</flux:heading>
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                            {{ $category->completed_count }} / {{ $category->total_count }}
                                        </flux:text>
                                    </div>

                                    {{-- Items --}}
                                    <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                        @foreach ($category->items as $item)
                                            <div
                                                wire:key="item-{{ $item->id }}"
                                                class="px-6 py-3 flex items-center justify-between hover:bg-zinc-50 dark:hover:bg-zinc-800/30 transition-colors"
                                            >
                                                <div class="flex items-center gap-3 flex-1">
                                                    @if ($this->canEdit)
                                                        <button
                                                            wire:click="cycleStatus({{ $item->id }})"
                                                            class="shrink-0 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded"
                                                            title="Click to change status"
                                                        >
                                                            @if ($item->status === 'done')
                                                                <div class="w-5 h-5 rounded-full bg-green-500 flex items-center justify-center">
                                                                    <flux:icon name="check" class="h-3 w-3 text-white" />
                                                                </div>
                                                            @elseif ($item->status === 'in_progress')
                                                                <div class="w-5 h-5 rounded-full border-2 border-blue-500 bg-blue-100 dark:bg-blue-900/50"></div>
                                                            @else
                                                                <div class="w-5 h-5 rounded-full border-2 border-zinc-300 dark:border-zinc-600"></div>
                                                            @endif
                                                        </button>
                                                    @else
                                                        @if ($item->status === 'done')
                                                            <div class="w-5 h-5 rounded-full bg-green-500 flex items-center justify-center shrink-0">
                                                                <flux:icon name="check" class="h-3 w-3 text-white" />
                                                            </div>
                                                        @elseif ($item->status === 'in_progress')
                                                            <div class="w-5 h-5 rounded-full border-2 border-blue-500 bg-blue-100 dark:bg-blue-900/50 shrink-0"></div>
                                                        @else
                                                            <div class="w-5 h-5 rounded-full border-2 border-zinc-300 dark:border-zinc-600 shrink-0"></div>
                                                        @endif
                                                    @endif

                                                    <flux:text class="{{ $item->status === 'done' ? 'line-through text-zinc-400 dark:text-zinc-500' : '' }}">
                                                        {{ $item->title }}
                                                    </flux:text>
                                                </div>

                                                <div class="flex items-center gap-2">
                                                    @if ($item->assignee)
                                                        <flux:tooltip content="{{ $item->assignee->name }}">
                                                            <flux:avatar size="xs" name="{{ $item->assignee->name }}" />
                                                        </flux:tooltip>
                                                    @endif

                                                    @if ($item->due_date)
                                                        <flux:badge
                                                            size="sm"
                                                            color="{{ $item->isOverdue() ? 'red' : 'zinc' }}"
                                                        >
                                                            {{ $item->due_date->format('M j') }}
                                                        </flux:badge>
                                                    @endif

                                                    <flux:badge size="sm" color="{{ $item->status_color }}">
                                                        {{ $item->status_label }}
                                                    </flux:badge>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach

                            @if ($phase->categories->isEmpty())
                                <div class="px-6 py-8 text-center text-zinc-400 dark:text-zinc-500">
                                    @if ($phase->is_active)
                                        No categories in this phase yet.
                                    @else
                                        Coming Soon
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
