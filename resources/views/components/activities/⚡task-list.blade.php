<?php

use App\Models\Activity;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $typeFilter = 'all';

    public string $statusFilter = 'all';

    public string $dueDateFilter = 'all';

    public int $perPage = 15;

    #[On('activity-created')]
    #[On('activity-updated')]
    public function refreshList(): void
    {
        // Re-render is triggered automatically
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDueDateFilter(): void
    {
        $this->resetPage();
    }

    public function toggleComplete(int $activityId): void
    {
        $activity = Activity::query()
            ->forUserOrAssignedTo(auth()->user())
            ->findOrFail($activityId);

        if ($activity->is_completed) {
            $activity->markAsIncomplete();
        } else {
            $activity->markAsCompleted();
        }
    }

    #[Computed]
    public function activities()
    {
        return Activity::query()
            ->forUserOrAssignedTo(auth()->user())
            ->with('subject')
            ->when($this->typeFilter !== 'all', function ($query) {
                return $query->ofType($this->typeFilter);
            })
            ->when($this->statusFilter === 'incomplete', function ($query) {
                return $query->incomplete();
            })
            ->when($this->statusFilter === 'completed', function ($query) {
                return $query->completed();
            })
            ->when($this->statusFilter === 'overdue', function ($query) {
                return $query->overdue();
            })
            ->when($this->dueDateFilter === 'today', function ($query) {
                return $query->dueToday();
            })
            ->when($this->dueDateFilter === 'this_week', function ($query) {
                return $query->dueThisWeek();
            })
            ->when($this->dueDateFilter === 'overdue', function ($query) {
                return $query->overdue();
            })
            ->orderByRaw('is_completed ASC')
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderBy('created_at', 'desc')
            ->paginate($this->perPage);
    }

    #[Computed]
    public function types()
    {
        return Activity::TYPES;
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Tasks') }}</flux:heading>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        <!-- Filters -->
        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="sm:w-48">
                <flux:select wire:model.live="typeFilter">
                    <option value="all">{{ __('All Types') }}</option>
                    @foreach ($this->types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="statusFilter">
                    <option value="all">{{ __('All Statuses') }}</option>
                    <option value="incomplete">{{ __('Incomplete') }}</option>
                    <option value="completed">{{ __('Completed') }}</option>
                    <option value="overdue">{{ __('Overdue') }}</option>
                </flux:select>
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="dueDateFilter">
                    <option value="all">{{ __('All Dates') }}</option>
                    <option value="today">{{ __('Due Today') }}</option>
                    <option value="this_week">{{ __('Due This Week') }}</option>
                    <option value="overdue">{{ __('Overdue') }}</option>
                </flux:select>
            </div>
        </div>

        @if ($this->activities->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider w-12">
                                {{ __('Done') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Title') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Type') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Subject') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Due Date') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->activities as $activity)
                            <tr wire:key="activity-{{ $activity->id }}" class="{{ $activity->is_completed ? 'opacity-60' : '' }}">
                                <td class="px-4 py-4 whitespace-nowrap">
                                    <flux:checkbox
                                        wire:click="toggleComplete({{ $activity->id }})"
                                        :checked="$activity->is_completed"
                                    />
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="font-medium {{ $activity->is_completed ? 'line-through' : '' }}">
                                        {{ $activity->title }}
                                    </flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                        $typeColors = [
                                            'task' => 'blue',
                                            'note' => 'zinc',
                                            'call' => 'green',
                                            'email' => 'purple',
                                            'meeting' => 'yellow',
                                            'other' => 'zinc',
                                        ];
                                    @endphp
                                    <flux:badge size="sm" color="{{ $typeColors[$activity->type] ?? 'zinc' }}">
                                        {{ $activity->type_label }}
                                    </flux:badge>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($activity->subject)
                                        @if ($activity->subject_type === \App\Models\Deal::class)
                                            <a href="{{ route('deals.show', $activity->subject_id) }}" wire:navigate class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                                {{ $activity->subject->title ?? 'Deal #' . $activity->subject_id }}
                                            </a>
                                        @elseif ($activity->subject_type === \App\Models\Customer::class)
                                            <a href="{{ route('customers.show', $activity->subject_id) }}" wire:navigate class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                                {{ $activity->subject->full_name ?? 'Customer #' . $activity->subject_id }}
                                            </a>
                                        @else
                                            <flux:text>{{ class_basename($activity->subject_type) }}</flux:text>
                                        @endif
                                    @else
                                        <flux:text class="text-zinc-400">-</flux:text>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($activity->due_at)
                                        @if ($activity->isOverdue())
                                            <flux:badge size="sm" color="red">
                                                {{ $activity->due_at->format('M j, Y') }}
                                            </flux:badge>
                                        @elseif ($activity->due_at->isToday())
                                            <flux:badge size="sm" color="yellow">
                                                {{ __('Today') }}
                                            </flux:badge>
                                        @else
                                            <flux:text>{{ $activity->due_at->format('M j, Y') }}</flux:text>
                                        @endif
                                    @else
                                        <flux:text class="text-zinc-400">-</flux:text>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->activities->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="check-circle" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">{{ __('No activities found') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($typeFilter !== 'all' || $statusFilter !== 'all' || $dueDateFilter !== 'all')
                        {{ __('Try adjusting your filter criteria.') }}
                    @else
                        {{ __('Get started by adding your first activity.') }}
                    @endif
                </flux:text>
            </div>
        @endif
    </div>
</div>
