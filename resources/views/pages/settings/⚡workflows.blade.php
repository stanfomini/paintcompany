<?php

use App\Automation\Registry;
use App\Models\Workflow;
use App\Models\WorkflowEdge;
use App\Models\WorkflowNode;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    // View mode: 'list' or 'builder'
    public string $view = 'list';

    // Builder state
    public ?int $editingWorkflowId = null;
    public string $workflowName = '';
    public string $workflowDescription = '';
    public bool $workflowIsActive = true;

    // Node config modal
    public bool $showNodeConfig = false;
    public ?int $configuringNodeId = null;
    public string $nodeLabel = '';
    public string $nodeClass = '';
    public array $nodeConfig = [];

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

    // Add node panel
    public string $addNodeType = '';

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
    public function registryData(): array
    {
        $registry = app(Registry::class);

        $format = fn ($classes) => $classes->map(fn ($class) => [
            'class' => $class,
            'name' => $class::getName(),
            'category' => $class::getCategory(),
            'description' => $class::getDescription(),
            'config_schema' => $class::getConfigSchema(),
        ])->values()->toArray();

        return [
            'triggers' => $format($registry->triggers()),
            'actions' => $format($registry->actions()),
            'conditions' => $format($registry->conditions()),
            'waits' => $format($registry->waits()),
        ];
    }

    #[Computed]
    public function editingWorkflow(): ?Workflow
    {
        if (! $this->editingWorkflowId) {
            return null;
        }

        return Workflow::with(['nodes', 'edges'])->find($this->editingWorkflowId);
    }

    #[Computed]
    public function workflowNodes(): array
    {
        $workflow = $this->editingWorkflow;
        if (! $workflow) {
            return [];
        }

        return $workflow->nodes->map(fn (WorkflowNode $node) => [
            'id' => $node->id,
            'type' => $node->type,
            'node_class' => $node->node_class,
            'config' => $node->config ?? [],
            'position_x' => $node->position_x ?? 0,
            'position_y' => $node->position_y ?? 0,
            'label' => $node->label ?? $this->getNodeLabel($node),
            'is_enabled' => $node->is_enabled,
        ])->toArray();
    }

    #[Computed]
    public function workflowEdges(): array
    {
        $workflow = $this->editingWorkflow;
        if (! $workflow) {
            return [];
        }

        return $workflow->edges->map(fn (WorkflowEdge $edge) => [
            'id' => $edge->id,
            'from_node_id' => $edge->from_node_id,
            'to_node_id' => $edge->to_node_id,
            'branch' => $edge->branch,
            'condition_config' => $edge->condition_config,
        ])->toArray();
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
        unset($this->workflows);
        $this->editWorkflow($workflow->id);
    }

    public function editWorkflow(int $id): void
    {
        $workflow = Workflow::find($id);
        if (! $workflow) {
            return;
        }

        $this->editingWorkflowId = $id;
        $this->workflowName = $workflow->name;
        $this->workflowDescription = $workflow->description ?? '';
        $this->workflowIsActive = $workflow->is_active;
        $this->view = 'builder';

        unset($this->editingWorkflow, $this->workflowNodes, $this->workflowEdges);
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

    // === Builder Actions ===

    public function backToList(): void
    {
        $this->view = 'list';
        $this->editingWorkflowId = null;
        unset($this->workflows, $this->editingWorkflow, $this->workflowNodes, $this->workflowEdges);
    }

    public function saveWorkflowSettings(): void
    {
        $this->validate([
            'workflowName' => 'required|string|max:100',
            'workflowDescription' => 'nullable|string|max:500',
        ]);

        $workflow = Workflow::find($this->editingWorkflowId);
        if (! $workflow) {
            return;
        }

        $workflow->update([
            'name' => $this->workflowName,
            'description' => $this->workflowDescription,
            'is_active' => $this->workflowIsActive,
            'published_at' => $this->workflowIsActive ? ($workflow->published_at ?? now()) : $workflow->published_at,
        ]);
    }

    public function addNode(string $type, string $nodeClass): void
    {
        $workflow = $this->editingWorkflow;
        if (! $workflow) {
            return;
        }

        // Position new nodes below existing ones
        $maxY = $workflow->nodes->max('position_y') ?? 0;

        $node = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => $type,
            'node_class' => $nodeClass,
            'config' => [],
            'position_x' => 250,
            'position_y' => $maxY + 120,
            'is_enabled' => true,
        ]);

        // Auto-connect to last node if there's only one disconnected node
        $this->autoConnectNode($workflow, $node);

        unset($this->editingWorkflow, $this->workflowNodes, $this->workflowEdges);
    }

    public function updateNodePosition(int $nodeId, float $x, float $y): void
    {
        WorkflowNode::where('id', $nodeId)->update([
            'position_x' => $x,
            'position_y' => $y,
        ]);
    }

    public function openNodeConfig(int $nodeId): void
    {
        $node = WorkflowNode::find($nodeId);
        if (! $node) {
            return;
        }

        $this->configuringNodeId = $nodeId;
        $this->nodeLabel = $node->label ?? '';
        $this->nodeClass = $node->node_class;
        $this->nodeConfig = $node->config ?? [];
        $this->showNodeConfig = true;
    }

    public function saveNodeConfig(): void
    {
        $node = WorkflowNode::find($this->configuringNodeId);
        if (! $node) {
            return;
        }

        $node->update([
            'label' => $this->nodeLabel ?: null,
            'config' => $this->nodeConfig,
        ]);

        $this->showNodeConfig = false;
        unset($this->editingWorkflow, $this->workflowNodes, $this->workflowEdges);
    }

    public function toggleNodeEnabled(int $nodeId): void
    {
        $node = WorkflowNode::find($nodeId);
        if ($node) {
            $node->update(['is_enabled' => ! $node->is_enabled]);
            unset($this->editingWorkflow, $this->workflowNodes);
        }
    }

    public function deleteNode(int $nodeId): void
    {
        $node = WorkflowNode::find($nodeId);
        if (! $node || $node->type === 'trigger') {
            return; // Don't delete trigger nodes
        }

        // Remove connected edges
        WorkflowEdge::where('from_node_id', $nodeId)
            ->orWhere('to_node_id', $nodeId)
            ->delete();

        $node->delete();
        unset($this->editingWorkflow, $this->workflowNodes, $this->workflowEdges);
    }

    public function connectNodes(int $fromId, int $toId, ?string $branch = null): void
    {
        $workflow = $this->editingWorkflow;
        if (! $workflow) {
            return;
        }

        // Don't create duplicate edges
        $exists = WorkflowEdge::where('workflow_id', $workflow->id)
            ->where('from_node_id', $fromId)
            ->where('to_node_id', $toId)
            ->exists();

        if ($exists) {
            return;
        }

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $fromId,
            'to_node_id' => $toId,
            'branch' => $branch,
            'sort_order' => 0,
        ]);

        unset($this->editingWorkflow, $this->workflowEdges);
    }

    public function deleteEdge(int $edgeId): void
    {
        WorkflowEdge::where('id', $edgeId)->delete();
        unset($this->editingWorkflow, $this->workflowEdges);
    }

    // === Helpers ===

    protected function getNodeLabel(WorkflowNode $node): string
    {
        if ($node->label) {
            return $node->label;
        }

        $class = $node->node_class;
        if (class_exists($class) && method_exists($class, 'getName')) {
            return $class::getName();
        }

        return class_basename($class);
    }

    protected function autoConnectNode(Workflow $workflow, WorkflowNode $newNode): void
    {
        // Find nodes with no outgoing edges (leaf nodes)
        $nodeIds = $workflow->nodes->pluck('id');
        $nodesWithOutgoing = $workflow->edges->pluck('from_node_id')->unique();
        $leafNodeIds = $nodeIds->diff($nodesWithOutgoing)->diff(collect([$newNode->id]));

        if ($leafNodeIds->count() === 1) {
            $leafNode = $workflow->nodes->find($leafNodeIds->first());
            if ($leafNode && $leafNode->type !== 'goal') {
                WorkflowEdge::create([
                    'workflow_id' => $workflow->id,
                    'from_node_id' => $leafNode->id,
                    'to_node_id' => $newNode->id,
                    'sort_order' => 0,
                ]);
            }
        }
    }

    protected function getNodeTypeColor(string $type): string
    {
        return match ($type) {
            'trigger' => 'amber',
            'action' => 'blue',
            'if_else' => 'purple',
            'wait' => 'orange',
            'goal' => 'green',
            'goto' => 'gray',
            default => 'zinc',
        };
    }
};
?>

<div>
    @if ($view === 'list')
        {{-- ==================== LIST VIEW ==================== --}}
        <x-pages::settings.layout heading="Workflows" subheading="Automate repetitive tasks with visual workflow sequences." :fullWidth="true">
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div class="text-sm text-zinc-500">
                    {{ $this->workflows->count() }} {{ Str::plural('workflow', $this->workflows->count()) }}
                </div>
                <flux:button variant="primary" size="sm" wire:click="openCreateModal">
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
                            <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $workflow->id }})">
                                {{ $workflow->is_active ? 'Disable' : 'Enable' }}
                            </flux:button>
                            <flux:dropdown>
                                <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                <flux:menu>
                                    <flux:menu.item wire:click="editWorkflow({{ $workflow->id }})">Edit</flux:menu.item>
                                    <flux:menu.item wire:click="duplicateWorkflow({{ $workflow->id }})">Duplicate</flux:menu.item>
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
                    <flux:button variant="danger" wire:click="deleteWorkflow">Delete</flux:button>
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
    @else
        {{-- ==================== BUILDER VIEW ==================== --}}
        <div class="flex h-[calc(100vh-120px)] flex-col" x-data="workflowBuilder(@js($this->workflowNodes), @js($this->workflowEdges), @js($this->registryData))">
            {{-- Builder Header --}}
            <div class="flex items-center justify-between border-b border-zinc-200 pb-3 dark:border-zinc-700">
                <div class="flex items-center gap-3">
                    <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="backToList" />
                    <div>
                        <input
                            wire:model.blur="workflowName"
                            class="border-0 bg-transparent p-0 text-lg font-semibold text-zinc-900 focus:ring-0 dark:text-zinc-100"
                            placeholder="Workflow name"
                        />
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <flux:switch wire:model.live="workflowIsActive" label="Active" />
                    <flux:button size="sm" wire:click="saveWorkflowSettings" wire:loading.attr="disabled">
                        Save
                    </flux:button>
                </div>
            </div>

            <div class="flex flex-1 overflow-hidden">
                {{-- Node Palette (Left Sidebar) --}}
                <div class="w-56 shrink-0 overflow-y-auto border-r border-zinc-200 pr-3 pt-3 dark:border-zinc-700">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-400">Add Node</p>

                    @php
                        $sections = [
                            'Triggers' => ['type' => 'trigger', 'items' => $this->registryData['triggers']],
                            'Actions' => ['type' => 'action', 'items' => $this->registryData['actions']],
                            'Conditions' => ['type' => 'if_else', 'items' => $this->registryData['conditions']],
                            'Waits' => ['type' => 'wait', 'items' => $this->registryData['waits']],
                        ];
                    @endphp

                    @foreach ($sections as $sectionName => $section)
                        <div class="mb-3" x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }">
                            <button
                                @click="open = !open"
                                class="mb-1 flex w-full items-center justify-between text-xs font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200"
                            >
                                {{ $sectionName }}
                                <svg :class="{ 'rotate-180': open }" class="h-3 w-3 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="open" x-collapse class="space-y-1">
                                @foreach ($section['items'] as $item)
                                    <button
                                        wire:click="addNode('{{ $section['type'] }}', '{{ addslashes($item['class']) }}')"
                                        class="w-full rounded px-2 py-1.5 text-left text-xs text-zinc-700 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                        title="{{ $item['description'] }}"
                                    >
                                        {{ $item['name'] }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    {{-- Special nodes --}}
                    <div class="mb-3">
                        <p class="mb-1 text-xs font-medium text-zinc-600 dark:text-zinc-400">Special</p>
                        <div class="space-y-1">
                            <button
                                wire:click="addNode('goal', '')"
                                class="w-full rounded px-2 py-1.5 text-left text-xs text-zinc-700 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            >
                                Goal (Exit)
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Canvas Area --}}
                <div class="relative flex-1 overflow-auto bg-zinc-50 dark:bg-zinc-900/50" x-ref="canvas">
                    <svg
                        class="absolute inset-0 h-full min-h-[800px] w-full min-w-[800px]"
                        x-ref="svg"
                    >
                        {{-- Background grid --}}
                        <defs>
                            <pattern id="grid" width="20" height="20" patternUnits="userSpaceOnUse">
                                <path d="M 20 0 L 0 0 0 20" fill="none" stroke="currentColor" stroke-width="0.5" class="text-zinc-200 dark:text-zinc-800" />
                            </pattern>
                        </defs>
                        <rect width="100%" height="100%" fill="url(#grid)" />

                        {{-- Edges --}}
                        <template x-for="edge in edges" :key="edge.id">
                            <g>
                                <line
                                    :x1="getNodeCenter(edge.from_node_id).x"
                                    :y1="getNodeCenter(edge.from_node_id).y + 30"
                                    :x2="getNodeCenter(edge.to_node_id).x"
                                    :y2="getNodeCenter(edge.to_node_id).y - 30"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    class="text-zinc-400 dark:text-zinc-600"
                                    marker-end="url(#arrowhead)"
                                />
                                <text
                                    x-show="edge.branch"
                                    :x="(getNodeCenter(edge.from_node_id).x + getNodeCenter(edge.to_node_id).x) / 2 + 8"
                                    :y="(getNodeCenter(edge.from_node_id).y + getNodeCenter(edge.to_node_id).y) / 2"
                                    class="fill-zinc-400 text-[10px]"
                                    x-text="edge.branch"
                                ></text>
                                {{-- Delete edge button --}}
                                <circle
                                    :cx="(getNodeCenter(edge.from_node_id).x + getNodeCenter(edge.to_node_id).x) / 2"
                                    :cy="(getNodeCenter(edge.from_node_id).y + getNodeCenter(edge.to_node_id).y) / 2"
                                    r="8"
                                    class="cursor-pointer fill-red-100 opacity-0 hover:opacity-100 dark:fill-red-900/50"
                                    @click="$wire.deleteEdge(edge.id)"
                                />
                                <text
                                    :x="(getNodeCenter(edge.from_node_id).x + getNodeCenter(edge.to_node_id).x) / 2"
                                    :y="(getNodeCenter(edge.from_node_id).y + getNodeCenter(edge.to_node_id).y) / 2 + 3"
                                    text-anchor="middle"
                                    class="pointer-events-none fill-red-500 text-[10px] opacity-0"
                                    :class="{ 'opacity-0': true }"
                                >x</text>
                            </g>
                        </template>

                        {{-- Arrow marker --}}
                        <defs>
                            <marker id="arrowhead" markerWidth="10" markerHeight="7" refX="10" refY="3.5" orient="auto">
                                <polygon points="0 0, 10 3.5, 0 7" class="fill-zinc-400 dark:fill-zinc-600" />
                            </marker>
                        </defs>

                        {{-- Connection mode line --}}
                        <line
                            x-show="connecting"
                            :x1="connectFrom.x"
                            :y1="connectFrom.y"
                            :x2="connectTo.x"
                            :y2="connectTo.y"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-dasharray="5,5"
                            class="text-blue-400"
                        />
                    </svg>

                    {{-- Nodes --}}
                    <template x-for="node in nodes" :key="node.id">
                        <div
                            class="absolute cursor-move select-none rounded-lg border-2 bg-white px-3 py-2 shadow-sm transition-shadow hover:shadow-md dark:bg-zinc-800"
                            :class="{
                                'border-amber-400': node.type === 'trigger',
                                'border-blue-400': node.type === 'action',
                                'border-purple-400': node.type === 'if_else',
                                'border-orange-400': node.type === 'wait',
                                'border-green-400': node.type === 'goal',
                                'border-zinc-400': node.type === 'goto',
                                'opacity-50': !node.is_enabled,
                            }"
                            :style="{ left: node.position_x + 'px', top: node.position_y + 'px', width: '200px' }"
                            x-on:mousedown.prevent="startDrag($event, node)"
                        >
                            {{-- Node header --}}
                            <div class="flex items-center justify-between">
                                <span class="truncate text-xs font-semibold uppercase tracking-wider"
                                    :class="{
                                        'text-amber-600 dark:text-amber-400': node.type === 'trigger',
                                        'text-blue-600 dark:text-blue-400': node.type === 'action',
                                        'text-purple-600 dark:text-purple-400': node.type === 'if_else',
                                        'text-orange-600 dark:text-orange-400': node.type === 'wait',
                                        'text-green-600 dark:text-green-400': node.type === 'goal',
                                        'text-zinc-500': node.type === 'goto',
                                    }"
                                    x-text="node.type.replace('_', '/')"
                                ></span>
                                <div class="flex gap-1">
                                    <button @click.stop="$wire.openNodeConfig(node.id)" class="rounded p-0.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-200" title="Configure">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    </button>
                                    <button x-show="node.type !== 'trigger'" @click.stop="$wire.deleteNode(node.id)" class="rounded p-0.5 text-zinc-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-900/30 dark:hover:text-red-400" title="Delete">
                                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </div>
                            {{-- Node label --}}
                            <p class="mt-1 truncate text-sm text-zinc-900 dark:text-zinc-100" x-text="node.label"></p>
                            {{-- Config summary --}}
                            <p class="mt-0.5 truncate text-xs text-zinc-400" x-text="getConfigSummary(node)"></p>

                            {{-- Connection handle (bottom) --}}
                            <div
                                class="absolute -bottom-2 left-1/2 h-4 w-4 -translate-x-1/2 cursor-crosshair rounded-full border-2 border-zinc-400 bg-white dark:bg-zinc-800"
                                @mousedown.stop.prevent="startConnect($event, node)"
                                title="Drag to connect"
                            ></div>

                            {{-- Connection target (top) --}}
                            <div
                                class="absolute -top-2 left-1/2 h-4 w-4 -translate-x-1/2 rounded-full border-2 border-zinc-300 bg-white dark:bg-zinc-800"
                                x-show="connecting && connectFromId !== node.id"
                                @mouseup.stop="finishConnect(node)"
                            ></div>
                        </div>
                    </template>

                    {{-- Empty state --}}
                    @if (empty($this->workflowNodes))
                        <div class="flex h-full items-center justify-center">
                            <div class="text-center">
                                <p class="text-sm text-zinc-500">This workflow has no nodes yet.</p>
                                <p class="mt-1 text-xs text-zinc-400">Add a trigger from the left panel to get started.</p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Node Config Modal --}}
        <flux:modal wire:model="showNodeConfig" class="max-w-lg">
            <div class="space-y-4">
                <flux:heading size="lg">Configure Node</flux:heading>

                <flux:field>
                    <flux:label>Label</flux:label>
                    <flux:input wire:model="nodeLabel" placeholder="Custom label for this node" />
                </flux:field>

                @if ($nodeClass && class_exists($nodeClass) && method_exists($nodeClass, 'getConfigSchema'))
                    @foreach ($nodeClass::getConfigSchema() as $key => $schema)
                        <flux:field>
                            <flux:label>{{ $schema['label'] ?? Str::title(str_replace('_', ' ', $key)) }}</flux:label>
                            @if (!empty($schema['description']))
                                <flux:description>{{ $schema['description'] }}</flux:description>
                            @endif

                            @if (isset($schema['options']) && is_array($schema['options']))
                                <flux:select wire:model="nodeConfig.{{ $key }}">
                                    <flux:select.option value="">-- Select --</flux:select.option>
                                    @foreach ($schema['options'] as $optVal => $optLabel)
                                        <flux:select.option value="{{ $optVal }}">{{ $optLabel }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @elseif (($schema['type'] ?? 'string') === 'boolean')
                                <flux:switch wire:model="nodeConfig.{{ $key }}" />
                            @elseif (($schema['type'] ?? 'string') === 'integer' || ($schema['type'] ?? 'string') === 'number')
                                <flux:input wire:model="nodeConfig.{{ $key }}" type="number" />
                            @elseif (($schema['type'] ?? 'string') === 'text')
                                <flux:textarea wire:model="nodeConfig.{{ $key }}" rows="3" />
                            @else
                                <flux:input wire:model="nodeConfig.{{ $key }}" />
                            @endif
                        </flux:field>
                    @endforeach
                @endif

                <div class="flex justify-end gap-2">
                    <flux:button wire:click="$set('showNodeConfig', false)">Cancel</flux:button>
                    <flux:button variant="primary" wire:click="saveNodeConfig">Save</flux:button>
                </div>
            </div>
        </flux:modal>

        {{-- Alpine.js Canvas Logic --}}
        <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('workflowBuilder', (initialNodes, initialEdges, registry) => ({
                nodes: initialNodes,
                edges: initialEdges,
                registry: registry,
                dragging: null,
                dragOffset: { x: 0, y: 0 },
                connecting: false,
                connectFromId: null,
                connectFrom: { x: 0, y: 0 },
                connectTo: { x: 0, y: 0 },

                init() {
                    this.$watch('$wire.workflowNodes', (val) => { this.nodes = val; });
                    this.$watch('$wire.workflowEdges', (val) => { this.edges = val; });

                    window.addEventListener('mousemove', (e) => this.onMouseMove(e));
                    window.addEventListener('mouseup', (e) => this.onMouseUp(e));
                },

                startDrag(e, node) {
                    this.dragging = node;
                    this.dragOffset = {
                        x: e.clientX - node.position_x,
                        y: e.clientY - node.position_y,
                    };
                },

                onMouseMove(e) {
                    if (this.dragging) {
                        this.dragging.position_x = Math.max(0, e.clientX - this.dragOffset.x);
                        this.dragging.position_y = Math.max(0, e.clientY - this.dragOffset.y);
                    }
                    if (this.connecting) {
                        const canvas = this.$refs.canvas.getBoundingClientRect();
                        this.connectTo = {
                            x: e.clientX - canvas.left + this.$refs.canvas.scrollLeft,
                            y: e.clientY - canvas.top + this.$refs.canvas.scrollTop,
                        };
                    }
                },

                onMouseUp(e) {
                    if (this.dragging) {
                        this.$wire.updateNodePosition(
                            this.dragging.id,
                            Math.round(this.dragging.position_x),
                            Math.round(this.dragging.position_y)
                        );
                        this.dragging = null;
                    }
                    if (this.connecting) {
                        this.connecting = false;
                        this.connectFromId = null;
                    }
                },

                startConnect(e, node) {
                    const canvas = this.$refs.canvas.getBoundingClientRect();
                    this.connecting = true;
                    this.connectFromId = node.id;
                    this.connectFrom = {
                        x: node.position_x + 100,
                        y: node.position_y + 60,
                    };
                    this.connectTo = {
                        x: e.clientX - canvas.left + this.$refs.canvas.scrollLeft,
                        y: e.clientY - canvas.top + this.$refs.canvas.scrollTop,
                    };
                },

                finishConnect(targetNode) {
                    if (this.connectFromId && this.connectFromId !== targetNode.id) {
                        this.$wire.connectNodes(this.connectFromId, targetNode.id);
                    }
                    this.connecting = false;
                    this.connectFromId = null;
                },

                getNodeCenter(nodeId) {
                    const node = this.nodes.find(n => n.id === nodeId);
                    if (!node) return { x: 0, y: 0 };
                    return {
                        x: node.position_x + 100,
                        y: node.position_y + 30,
                    };
                },

                getConfigSummary(node) {
                    const config = node.config || {};
                    const parts = [];
                    for (const [key, val] of Object.entries(config)) {
                        if (val && typeof val === 'string' && val.length < 30) {
                            parts.push(val);
                        }
                    }
                    return parts.slice(0, 2).join(' | ') || '';
                },
            }));
        });
        </script>
    @endif
</div>
