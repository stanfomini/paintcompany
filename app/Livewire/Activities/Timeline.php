<?php

namespace App\Livewire\Activities;

use App\Models\Activity;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class Timeline extends Component
{
    public string $subjectType;

    public int $subjectId;

    // Quick add form fields
    public string $title = '';

    public string $type = 'note';

    public ?string $dueAt = null;

    public string $description = '';

    public bool $showForm = false;

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:task,note,call,email,meeting,other'],
            'dueAt' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function mount(string $subjectType, int $subjectId): void
    {
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
    }

    public function toggleComplete(int $activityId): void
    {
        $activity = Activity::query()
            ->where('subject_type', $this->subjectType)
            ->where('subject_id', $this->subjectId)
            ->findOrFail($activityId);

        if ($activity->is_completed) {
            $activity->markAsIncomplete();
        } else {
            $activity->markAsCompleted();
        }
    }

    public function createActivity(): void
    {
        $this->validate();

        Activity::create([
            'user_id' => auth()->id(),
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description ?: null,
            'due_at' => $this->dueAt ?: null,
            'is_completed' => false,
        ]);

        $this->resetForm();

        $this->dispatch('activity-created');
        session()->flash('timeline-success', 'Activity created successfully.');
    }

    public function cancelForm(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['title', 'type', 'dueAt', 'description', 'showForm']);
        $this->type = 'note';
        $this->resetValidation();
    }

    #[On('activity-created')]
    public function refreshTimeline(): void
    {
        // This will re-render the component
    }

    public function render(): View
    {
        $activities = Activity::query()
            ->where('subject_type', $this->subjectType)
            ->where('subject_id', $this->subjectId)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('livewire.activities.timeline', [
            'activities' => $activities,
            'types' => Activity::TYPES,
        ]);
    }
}
