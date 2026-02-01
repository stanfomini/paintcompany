<?php

namespace App\Livewire\Activities;

use App\Models\Activity;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class QuickAdd extends Component
{
    public string $subjectType;

    public int $subjectId;

    public string $title = '';

    public string $type = 'task';

    public ?string $dueAt = null;

    public string $description = '';

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

    public function save(): void
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
        session()->flash('quick-add-success', 'Activity created successfully.');
    }

    protected function resetForm(): void
    {
        $this->reset(['title', 'type', 'dueAt', 'description']);
        $this->type = 'task';
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.activities.quick-add', [
            'types' => Activity::TYPES,
        ]);
    }
}
