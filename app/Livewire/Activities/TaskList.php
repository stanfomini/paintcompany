<?php

namespace App\Livewire\Activities;

use App\Models\Activity;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class TaskList extends Component
{
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
            ->forUser(auth()->user())
            ->findOrFail($activityId);

        if ($activity->is_completed) {
            $activity->markAsIncomplete();
        } else {
            $activity->markAsCompleted();
        }
    }

    public function render(): View
    {
        $activities = Activity::query()
            ->forUser(auth()->user())
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

        return view('livewire.activities.task-list', [
            'activities' => $activities,
            'types' => Activity::TYPES,
        ])->layout('layouts.app', ['title' => 'Tasks']);
    }
}
