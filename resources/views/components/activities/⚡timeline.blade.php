<?php

use App\Models\Activity;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public array $types = [];

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
        $this->types = Activity::TYPES;
    }

    #[Computed]
    public function activities()
    {
        return Activity::query()
            ->where('subject_type', $this->subjectType)
            ->where('subject_id', $this->subjectId)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
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
}; ?>

<div>
    @if (session('timeline-success'))
        <flux:callout variant="success" class="mb-4">
            {{ session('timeline-success') }}
        </flux:callout>
    @endif

    <!-- Add Activity Button / Form -->
    <div class="mb-6">
        @if ($showForm)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 bg-white dark:bg-zinc-900">
                <flux:heading size="sm" class="mb-4">Add Activity</flux:heading>
                <form wire:submit="createActivity">
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
                    <div class="mt-4 flex justify-end gap-2">
                        <flux:button variant="ghost" wire:click="cancelForm" type="button">
                            Cancel
                        </flux:button>
                        <flux:button type="submit" variant="primary" size="sm">
                            Add Activity
                        </flux:button>
                    </div>
                </form>
            </div>
        @else
            <flux:button variant="primary" size="sm" wire:click="$set('showForm', true)" icon="plus">
                Add Activity
            </flux:button>
        @endif
    </div>

    <!-- Activity Timeline -->
    @if ($this->activities->isEmpty())
        <flux:text class="text-zinc-500">No activities yet.</flux:text>
    @else
        <div class="space-y-4">
            @foreach ($this->activities as $activity)
                <div wire:key="timeline-{{ $activity->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 bg-white dark:bg-zinc-900">
                    <div class="flex items-start gap-3">
                        <!-- Type Icon / Checkbox for tasks -->
                        <div class="flex-shrink-0 mt-0.5">
                            @if ($activity->type === 'task')
                                <flux:checkbox
                                    wire:click="toggleComplete({{ $activity->id }})"
                                    :checked="$activity->is_completed"
                                />
                            @else
                                @php
                                    $typeIcons = [
                                        'note' => 'document-text',
                                        'call' => 'phone',
                                        'email' => 'envelope',
                                        'meeting' => 'calendar',
                                        'other' => 'ellipsis-horizontal-circle',
                                    ];
                                    $typeColors = [
                                        'note' => 'text-zinc-500',
                                        'call' => 'text-green-500',
                                        'email' => 'text-purple-500',
                                        'meeting' => 'text-yellow-500',
                                        'other' => 'text-zinc-500',
                                    ];
                                @endphp
                                <flux:icon name="{{ $typeIcons[$activity->type] ?? 'document' }}" class="h-5 w-5 {{ $typeColors[$activity->type] ?? 'text-zinc-500' }}" />
                            @endif
                        </div>

                        <!-- Content -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <flux:text class="font-medium {{ $activity->is_completed ? 'line-through opacity-60' : '' }}">
                                    {{ $activity->title }}
                                </flux:text>
                                @php
                                    $badgeColors = [
                                        'task' => 'blue',
                                        'note' => 'zinc',
                                        'call' => 'green',
                                        'email' => 'purple',
                                        'meeting' => 'yellow',
                                        'other' => 'zinc',
                                    ];
                                @endphp
                                <flux:badge size="sm" color="{{ $badgeColors[$activity->type] ?? 'zinc' }}">
                                    {{ $activity->type_label }}
                                </flux:badge>
                                @if ($activity->is_completed)
                                    <flux:badge size="sm" color="green">Completed</flux:badge>
                                @elseif ($activity->isOverdue())
                                    <flux:badge size="sm" color="red">Overdue</flux:badge>
                                @endif
                            </div>

                            @if ($activity->description)
                                <div class="mt-2">
                                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400 whitespace-pre-wrap">
                                        {{ $activity->description }}
                                    </flux:text>
                                </div>
                            @endif

                            <div class="mt-2 flex items-center gap-3 text-sm text-zinc-500">
                                <span>{{ $activity->user->name }}</span>
                                <span>{{ $activity->created_at->format('M j, Y g:i A') }}</span>
                                @if ($activity->due_at)
                                    <span class="{{ $activity->isOverdue() ? 'text-red-500 font-medium' : '' }}">
                                        Due: {{ $activity->due_at->format('M j, Y') }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
