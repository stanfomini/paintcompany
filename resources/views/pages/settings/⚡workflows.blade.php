<?php

use App\Models\Workflow;
use App\Models\WorkflowEdge;
use App\Models\WorkflowNode;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    // Create workflow modal
    public bool $showCreateModal = false;
    public string $newName = '';
    public string $newDescription = '';

    // Delete confirmation
    public bool $showDeleteModal = false;
    public ?int $deletingWorkflowId = null;
    public string $deletingWorkflowName = '';

    // Run history modal
    public bool $showRunHistory = false;
    public ?int $runHistoryWorkflowId = null;

    public function mount(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function workflows(): \Illuminate\Database\Eloquent\Collection
    {
        return Workflow::query()
            ->withCount(['nodes', 'runs'])
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function runs(): \Illuminate\Support\Collection
    {
        if (! $this->runHistoryWorkflowId) {
            return collect();
        }

        return WorkflowRun::where('workflow_id', $this->runHistoryWorkflowId)
            ->with(['steps.node'])
            ->latest('triggered_at')
            ->limit(20)
            ->get();
    }

    // === List Actions ===

    public function openCreateModal(): void
    {
        $this->newName = '';
        $this->newDescription = '';
        $this->showCreateModal = true;
    }

    public function createWorkflow(): void
    {
        $this->validate([
            'newName' => 'required|string|max:100',
            'newDescription' => 'nullable|string|max:500',
        ]);

        $workflow = Workflow::create([
            'name' => $this->newName,
            'description' => $this->newDescription,
            'is_active' => false,
            'is_system' => false,
            'version' => 1,
            'created_by' => auth()->id(),
        ]);

        $this->showCreateModal = false;
        $this->redirect(route('workflows.builder', $workflow));
    }

    public function editWorkflow(int $id): void
    {
        $workflow = Workflow::find($id);
        if (! $workflow) {
            return;
        }

        $this->redirect(route('workflows.builder', $workflow));
    }

    public function confirmDelete(int $id, string $name): void
    {
        $this->deletingWorkflowId = $id;
        $this->deletingWorkflowName = $name;
        $this->showDeleteModal = true;
    }

    public function deleteWorkflow(): void
    {
        if ($this->deletingWorkflowId) {
            $workflow = Workflow::find($this->deletingWorkflowId);
            if ($workflow && ! $workflow->is_system) {
                $workflow->delete();
            }
        }

        $this->showDeleteModal = false;
        $this->deletingWorkflowId = null;
        unset($this->workflows);
    }

    public function toggleActive(int $id): void
    {
        $workflow = Workflow::find($id);
        if (! $workflow) {
            return;
        }

        $workflow->update([
            'is_active' => ! $workflow->is_active,
            'published_at' => ! $workflow->is_active ? now() : $workflow->published_at,
        ]);

        unset($this->workflows);
    }

    public function duplicateWorkflow(int $id): void
    {
        $original = Workflow::with(['nodes', 'edges'])->find($id);
        if (! $original) {
            return;
        }

        $copy = $original->replicate(['published_at']);
        $copy->name = $original->name . ' (Copy)';
        $copy->is_system = false;
        $copy->is_active = false;
        $copy->published_at = null;
        $copy->created_by = auth()->id();
        $copy->save();

        $nodeMap = [];
        foreach ($original->nodes as $node) {
            $newNode = $node->replicate();
            $newNode->workflow_id = $copy->id;
            $newNode->save();
            $nodeMap[$node->id] = $newNode->id;
        }

        foreach ($original->edges as $edge) {
            $newEdge = $edge->replicate();
            $newEdge->workflow_id = $copy->id;
            $newEdge->from_node_id = $nodeMap[$edge->from_node_id] ?? null;
            $newEdge->to_node_id = $nodeMap[$edge->to_node_id] ?? null;
            if ($newEdge->from_node_id && $newEdge->to_node_id) {
                $newEdge->save();
            }
        }

        unset($this->workflows);
    }

    public function viewRunHistory(int $id): void
    {
        $this->runHistoryWorkflowId = $id;
        $this->showRunHistory = true;
        unset($this->runs);
    }
};
?>

<div>
    <x-pages::settings.layout heading="Workflows" subheading="Automate repetitive tasks with visual workflow sequences." :fullWidth="true">
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div class="text-sm text-zinc-500">
                {{ $this->workflows->count() }} {{ Str::plural('workflow', $this->workflows->count()) }}
            </div>
            <flux:button variant="primary" size="sm" wire:click="openCreateModal" wire:loading.attr="disabled">
                New Workflow
            </flux:button>
        </div>

        <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @forelse ($this->workflows as $workflow)
                <div wire:key="wf-{{ $workflow->id }}" class="flex items-center justify-between gap-4 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <button wire:click="editWorkflow({{ $workflow->id }})" class="truncate font-medium text-zinc-900 hover:text-blue-600 dark:text-zinc-100 dark:hover:text-blue-400">
                                {{ $workflow->name }}
                            </button>
                            @if ($workflow->is_system)
                                <flux:badge size="sm" color="amber">System</flux:badge>
                            @endif
                            @if ($workflow->is_active)
                                <flux:badge size="sm" color="green">Active</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">Inactive</flux:badge>
                            @endif
                        </div>
                        @if ($workflow->description)
                            <p class="mt-0.5 truncate text-sm text-zinc-500">{{ $workflow->description }}</p>
                        @endif
                        <div class="mt-1 flex gap-3 text-xs text-zinc-400">
                            <span>{{ $workflow->nodes_count }} {{ Str::plural('node', $workflow->nodes_count) }}</span>
                            <span>{{ $workflow->runs_count }} {{ Str::plural('run', $workflow->runs_count) }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $workflow->id }})" wire:loading.attr="disabled" wire:target="toggleActive({{ $workflow->id }})">
                            <span wire:loading.remove wire:target="toggleActive({{ $workflow->id }})">{{ $workflow->is_active ? 'Disable' : 'Enable' }}</span>
                            <span wire:loading wire:target="toggleActive({{ $workflow->id }})">
                                <flux:icon.loading class="size-4" />
                            </span>
                        </flux:button>
                        @unless ($workflow->is_system)
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $workflow->id }}, '{{ addslashes($workflow->name) }}')" class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300" title="Delete workflow" />
                        @endunless
                        <flux:dropdown>
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                            <flux:menu>
                                <flux:menu.item wire:click="editWorkflow({{ $workflow->id }})">Edit</flux:menu.item>
                                <flux:menu.item wire:click="duplicateWorkflow({{ $workflow->id }})" wire:loading.attr="disabled" wire:target="duplicateWorkflow({{ $workflow->id }})">Duplicate</flux:menu.item>
                                <flux:menu.item wire:click="viewRunHistory({{ $workflow->id }})">Run History</flux:menu.item>
                                @unless ($workflow->is_system)
                                    <flux:menu.separator />
                                    <flux:menu.item wire:click="confirmDelete({{ $workflow->id }}, '{{ addslashes($workflow->name) }}')" variant="danger">Delete</flux:menu.item>
                                @endunless
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                </div>
            @empty
                <div class="px-4 py-8 text-center text-sm text-zinc-500">
                    No workflows yet. Create your first one to get started.
                </div>
            @endforelse
        </div>
    </div>

    {{-- Create Modal --}}
    <flux:modal wire:model="showCreateModal">
        <div class="space-y-4">
            <flux:heading size="lg">New Workflow</flux:heading>

            <flux:field>
                <flux:label>Name</flux:label>
                <flux:input wire:model="newName" placeholder="e.g., New Lead Follow-Up" />
            </flux:field>

            <flux:field>
                <flux:label>Description</flux:label>
                <flux:textarea wire:model="newDescription" rows="2" placeholder="What does this workflow do?" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showCreateModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="createWorkflow">Create</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Delete Modal --}}
    <flux:modal wire:model="showDeleteModal">
        <div class="space-y-4">
            <flux:heading size="lg">Delete Workflow</flux:heading>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Are you sure you want to delete <strong>{{ $deletingWorkflowName }}</strong>? This action cannot be undone.
            </p>
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button variant="danger" wire:click="deleteWorkflow" wire:loading.attr="disabled" wire:target="deleteWorkflow">
                    <span wire:loading.remove wire:target="deleteWorkflow">Delete</span>
                    <span wire:loading wire:target="deleteWorkflow">Deleting...</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Run History Modal --}}
    <flux:modal wire:model="showRunHistory" class="max-w-2xl">
        <div class="space-y-4">
            <flux:heading size="lg">Run History</flux:heading>

            <div class="max-h-96 space-y-2 overflow-y-auto">
                @forelse ($this->runs as $run)
                    <div wire:key="run-{{ $run->id }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                @php
                                    $statusColor = match($run->status) {
                                        'completed' => 'green',
                                        'running' => 'blue',
                                        'waiting' => 'amber',
                                        'failed' => 'red',
                                        'cancelled' => 'zinc',
                                        default => 'zinc',
                                    };
                                @endphp
                                <flux:badge size="sm" :color="$statusColor">{{ ucfirst($run->status) }}</flux:badge>
                                <span class="text-xs text-zinc-500">
                                    {{ $run->triggered_at?->diffForHumans() }}
                                </span>
                            </div>
                            <span class="text-xs text-zinc-400">
                                {{ $run->steps->count() }} steps
                            </span>
                        </div>
                        @if ($run->error_message)
                            <p class="mt-1 text-xs text-red-600">{{ $run->error_message }}</p>
                        @endif
                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach ($run->steps->sortBy('started_at') as $step)
                                @php
                                    $stepColor = match($step->status) {
                                        'completed' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                                        'waiting' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
                                        'failed' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                                        'skipped' => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-500',
                                        default => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
                                    };
                                @endphp
                                <span class="rounded px-1.5 py-0.5 text-xs {{ $stepColor }}">
                                    {{ $step->node?->label ?? class_basename($step->node?->node_class ?? 'Unknown') }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-center text-sm text-zinc-500">No runs yet.</p>
                @endforelse
            </div>

            <div class="flex justify-end">
                <flux:button wire:click="$set('showRunHistory', false)">Close</flux:button>
            </div>
        </div>
    </flux:modal>

    </x-pages::settings.layout>
</div>
