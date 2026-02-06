<?php

use App\Models\Activity;
use App\Models\User;
use App\Notifications\TaskAssigned;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public array $types = [];

    public string $subjectType;

    public int $subjectId;

    public string $title = '';

    public string $type = 'task';

    public ?string $dueAt = null;

    public string $description = '';

    public ?int $assignedTo = null;

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:task,note,call,email,meeting,other'],
            'dueAt' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:10000'],
            'assignedTo' => ['nullable', 'exists:users,id'],
        ];
    }

    public function mount(string $subjectType, int $subjectId): void
    {
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->types = Activity::TYPES;
    }

    #[Computed]
    public function teamMembers()
    {
        return User::where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function save(): void
    {
        $this->validate();

        $activity = Activity::create([
            'user_id' => auth()->id(),
            'assigned_to' => $this->assignedTo ?: auth()->id(),
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description ?: null,
            'due_at' => $this->dueAt ?: null,
            'is_completed' => false,
        ]);

        // Send notification if assigned to someone else
        if ($this->assignedTo && $this->assignedTo !== auth()->id()) {
            $assignee = User::find($this->assignedTo);
            $assignee?->notify(new TaskAssigned($activity));
        }

        $this->resetForm();

        $this->dispatch('activity-created');
        session()->flash('quick-add-success', 'Activity created successfully.');
    }

    protected function resetForm(): void
    {
        $this->reset(['title', 'type', 'dueAt', 'description', 'assignedTo']);
        $this->type = 'task';
        $this->resetValidation();
    }
}; ?>

<div>
    @if (session('quick-add-success'))
        <flux:callout variant="success" class="mb-4">
            {{ session('quick-add-success') }}
        </flux:callout>
    @endif

    <form wire:submit="save">
        <div class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Title</flux:label>
                    <flux:input wire:model="title" placeholder="Activity title..." />
                    <flux:error name="title" />
                </flux:field>
                <flux:field>
                    <flux:label>Type</flux:label>
                    <flux:select wire:model="type">
                        @foreach ($types as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="type" />
                </flux:field>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Due Date (optional)</flux:label>
                    <flux:input wire:model="dueAt" type="date" />
                    <flux:error name="dueAt" />
                </flux:field>
            </div>
            <flux:field>
                <flux:label>Description (optional)</flux:label>
                <flux:textarea wire:model="description" rows="2" placeholder="Add details..." />
                <flux:error name="description" />
            </flux:field>
        </div>
        <div class="mt-4 flex justify-end">
            <flux:button type="submit" variant="primary" size="sm">
                Add Activity
            </flux:button>
        </div>
    </form>
</div>
