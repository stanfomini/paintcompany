<?php

use App\Automation\Actions\CreateCustomer;
use App\Automation\Actions\CreateDeal;
use App\Automation\Actions\CreateProperty;
use App\Automation\Registry;
use App\Automation\Triggers\InboundWebhook;
use App\Models\AutomationWebhook;
use App\Models\Workflow;
use App\Models\WorkflowEdge;
use App\Models\WorkflowNode;
use App\Models\WorkflowRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;
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

    // Webhook config state
    public ?string $webhookUrl = null;
    public ?int $webhookModelId = null;
    public ?array $webhookSamplePayload = null;
    public ?array $webhookAvailableFields = null;

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

    public function addNode(string $type, string $nodeClass, ?float $x = null, ?float $y = null): void
    {
        $workflow = $this->editingWorkflow;
        if (! $workflow) {
            return;
        }

        // Position: use provided coords (drag-and-drop), or stack below existing nodes
        $posX = $x !== null ? round($x / 20) * 20 : 250;
        $posY = $y !== null ? round($y / 20) * 20 : ($workflow->nodes->max('position_y') ?? 0) + 120;

        $node = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => $type,
            'node_class' => $nodeClass,
            'config' => [],
            'position_x' => $posX,
            'position_y' => $posY,
            'is_enabled' => true,
        ]);

        // Auto-connect to last node if there's only one disconnected node
        $this->autoConnectNode($workflow, $node);

        $this->dispatchWorkflowData();
    }

    #[Renderless]
    public function updateNodePosition(int $nodeId, float $x, float $y): void
    {
        WorkflowNode::where('id', $nodeId)->update([
            'position_x' => $x,
            'position_y' => $y,
        ]);
    }

    #[Renderless]
    public function saveNodePositions(array $positions): void
    {
        foreach ($positions as $pos) {
            if (! empty($pos['id'])) {
                WorkflowNode::where('id', $pos['id'])->update([
                    'position_x' => round($pos['x']),
                    'position_y' => round($pos['y']),
                ]);
            }
        }
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

        // Reset webhook state
        $this->webhookUrl = null;
        $this->webhookModelId = null;
        $this->webhookSamplePayload = null;
        $this->webhookAvailableFields = null;

        // Special handling for InboundWebhook trigger
        if ($this->nodeClass === InboundWebhook::class) {
            $this->ensureWebhookExists();
        }

        // Special handling for record-creation actions
        if (in_array($this->nodeClass, [CreateCustomer::class, CreateDeal::class, CreateProperty::class])) {
            $this->loadWebhookFieldsFromTrigger();
        }

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
        $this->webhookUrl = null;
        $this->webhookModelId = null;
        $this->webhookSamplePayload = null;
        $this->webhookAvailableFields = null;
        $this->dispatchWorkflowData();
    }

    public function toggleNodeEnabled(int $nodeId): void
    {
        $node = WorkflowNode::find($nodeId);
        if ($node) {
            $node->update(['is_enabled' => ! $node->is_enabled]);
            $this->dispatchWorkflowData();
        }
    }

    public function deleteNode(int $nodeId): void
    {
        $node = WorkflowNode::find($nodeId);
        if (! $node) {
            return;
        }

        // Remove connected edges
        WorkflowEdge::where('from_node_id', $nodeId)
            ->orWhere('to_node_id', $nodeId)
            ->delete();

        $node->delete();
        $this->dispatchWorkflowData();
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

        $this->dispatchWorkflowData();
    }

    public function deleteEdge(int $edgeId): void
    {
        WorkflowEdge::where('id', $edgeId)->delete();
        $this->dispatchWorkflowData();
    }

    // === Webhook Config Methods ===

    public function ensureWebhookExists(): void
    {
        $token = $this->nodeConfig['webhook_token'] ?? null;

        if ($token) {
            $webhook = AutomationWebhook::where('token', $token)->first();
        } else {
            $webhook = null;
        }

        if (! $webhook) {
            $workflow = $this->editingWorkflow;
            $webhook = AutomationWebhook::create([
                'name' => ($workflow?->name ?? 'Workflow') . ' Webhook',
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);
            $this->nodeConfig['webhook_token'] = $webhook->token;
        }

        $this->webhookModelId = $webhook->id;
        $this->webhookUrl = $webhook->getWebhookUrl();
        $this->loadWebhookPayloadFromModel($webhook);
    }

    public function refreshWebhookPayload(): void
    {
        if (! $this->webhookModelId) {
            return;
        }

        $webhook = AutomationWebhook::find($this->webhookModelId);
        if (! $webhook) {
            return;
        }

        $this->loadWebhookPayloadFromModel($webhook);
    }

    public function clearWebhookPayload(): void
    {
        if (! $this->webhookModelId) {
            return;
        }

        $webhook = AutomationWebhook::find($this->webhookModelId);
        if ($webhook) {
            $webhook->update(['last_payload' => null]);
        }

        $this->webhookSamplePayload = null;
        $this->webhookAvailableFields = null;
    }

    public function loadWebhookFieldsFromTrigger(): void
    {
        $workflow = $this->editingWorkflow;
        if (! $workflow) {
            return;
        }

        $triggerNode = $workflow->nodes
            ->where('node_class', InboundWebhook::class)
            ->first();

        if (! $triggerNode) {
            return;
        }

        $token = $triggerNode->config['webhook_token'] ?? null;
        if (! $token) {
            return;
        }

        $webhook = AutomationWebhook::where('token', $token)->first();
        if (! $webhook || ! $webhook->last_payload) {
            return;
        }

        $this->loadWebhookPayloadFromModel($webhook);
    }

    protected function loadWebhookPayloadFromModel(AutomationWebhook $webhook): void
    {
        $this->webhookSamplePayload = $webhook->last_payload;

        if ($this->webhookSamplePayload) {
            $this->webhookAvailableFields = collect(Arr::dot($this->webhookSamplePayload))
                ->map(fn ($value, $key) => [
                    'path' => $key,
                    'sample' => $value,
                ])
                ->values()
                ->toArray();
        } else {
            $this->webhookAvailableFields = null;
        }
    }

    // === Helpers ===

    protected function dispatchWorkflowData(): void
    {
        unset($this->editingWorkflow, $this->workflowNodes, $this->workflowEdges);
        $this->dispatch('workflow-data-updated', nodes: $this->workflowNodes, edges: $this->workflowEdges);
    }

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
        {{-- ==================== BUILDER VIEW (full-screen overlay) ==================== --}}
        <div class="fixed inset-0 z-50 flex flex-col overflow-hidden bg-white dark:bg-zinc-900" x-data="workflowBuilder(@js($this->workflowNodes), @js($this->workflowEdges), @js($this->registryData))">
            {{-- Builder Header --}}
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
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
                    <span class="text-xs text-zinc-400" x-text="`${nodes.length} nodes`"></span>
                    <button @click="autoLayout()" class="rounded px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" title="Auto-arrange nodes">
                        <svg class="inline h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"/></svg>
                        Arrange
                    </button>
                    <button @click="fitView()" class="rounded px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" title="Fit all nodes in view">
                        <svg class="inline h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
                        Fit
                    </button>
                    <div class="mx-1 h-4 w-px bg-zinc-200 dark:bg-zinc-700"></div>
                    <flux:switch wire:model.live="workflowIsActive" label="Active" />
                    <flux:button size="sm" wire:click="saveWorkflowSettings" wire:loading.attr="disabled">
                        Save
                    </flux:button>
                </div>
            </div>

            <div class="flex flex-1 overflow-hidden">
                {{-- Node Palette (Left Sidebar) --}}
                <div class="w-56 shrink-0 overflow-y-auto border-r border-zinc-200 p-3 dark:border-zinc-700">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-400">Add Node</p>
                    <p class="mb-3 text-[10px] text-zinc-400">Drag onto canvas or click to add</p>

                    @php
                        $sections = [
                            'Triggers' => ['type' => 'trigger', 'items' => $this->registryData['triggers'], 'color' => 'amber'],
                            'Actions' => ['type' => 'action', 'items' => $this->registryData['actions'], 'color' => 'blue'],
                            'Conditions' => ['type' => 'if_else', 'items' => $this->registryData['conditions'], 'color' => 'purple'],
                            'Waits' => ['type' => 'wait', 'items' => $this->registryData['waits'], 'color' => 'orange'],
                        ];
                        $colorClasses = [
                            'amber' => 'border-l-amber-400 hover:bg-amber-50 dark:hover:bg-amber-900/20',
                            'blue' => 'border-l-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20',
                            'purple' => 'border-l-purple-400 hover:bg-purple-50 dark:hover:bg-purple-900/20',
                            'orange' => 'border-l-orange-400 hover:bg-orange-50 dark:hover:bg-orange-900/20',
                            'green' => 'border-l-green-400 hover:bg-green-50 dark:hover:bg-green-900/20',
                        ];
                    @endphp

                    @foreach ($sections as $sectionName => $section)
                        <div class="mb-3" x-data="{ open: true }">
                            <button
                                @click="open = !open"
                                class="mb-1 flex w-full items-center justify-between text-xs font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200"
                            >
                                {{ $sectionName }}
                                <svg :class="{ 'rotate-180': open }" class="h-3 w-3 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <div x-show="open" x-collapse class="space-y-1">
                                @foreach ($section['items'] as $item)
                                    <div
                                        draggable="true"
                                        x-on:dragstart="handleDragStart($event, '{{ $section['type'] }}', '{{ addslashes($item['class']) }}')"
                                        x-on:click="$wire.addNode('{{ $section['type'] }}', '{{ addslashes($item['class']) }}')"
                                        class="cursor-grab rounded border-l-2 px-2 py-1.5 text-left text-xs text-zinc-700 transition active:cursor-grabbing dark:text-zinc-300 {{ $colorClasses[$section['color']] }}"
                                        title="{{ $item['description'] }}"
                                    >
                                        {{ $item['name'] }}
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    {{-- Special nodes --}}
                    <div class="mb-3">
                        <p class="mb-1 text-xs font-medium text-zinc-600 dark:text-zinc-400">Special</p>
                        <div class="space-y-1">
                            <div
                                draggable="true"
                                x-on:dragstart="handleDragStart($event, 'goal', '')"
                                x-on:click="$wire.addNode('goal', '')"
                                class="cursor-grab rounded border-l-2 px-2 py-1.5 text-left text-xs text-zinc-700 transition active:cursor-grabbing dark:text-zinc-300 {{ $colorClasses['green'] }}"
                            >
                                Goal (Exit)
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Canvas Area --}}
                <div
                    wire:ignore
                    class="relative flex-1 select-none overflow-auto overscroll-contain bg-zinc-50 dark:bg-zinc-900/50"
                    :class="{ 'ring-2 ring-inset ring-blue-300 dark:ring-blue-700': dragOverCanvas }"
                    x-ref="canvas"
                    x-on:pointermove="onPointerMove($event)"
                    x-on:pointerup="onPointerUp($event)"
                    x-on:wheel.stop
                    x-on:dragover.prevent="dragOverCanvas = true"
                    x-on:dragleave.self="dragOverCanvas = false"
                    x-on:drop.prevent="handleCanvasDrop($event)"
                >
                    <svg
                        class="pointer-events-none absolute inset-0 h-full w-full"
                        :style="`min-width: ${canvasWidth}px; min-height: ${canvasHeight}px`"
                        x-ref="svg"
                    >
                        <defs>
                            <pattern id="grid" width="20" height="20" patternUnits="userSpaceOnUse">
                                <path d="M 20 0 L 0 0 0 20" fill="none" stroke="currentColor" stroke-width="0.5" class="text-zinc-200 dark:text-zinc-800" />
                            </pattern>
                            <marker id="arrowhead" markerWidth="10" markerHeight="7" refX="10" refY="3.5" orient="auto">
                                <polygon points="0 0, 10 3.5, 0 7" class="fill-zinc-400 dark:fill-zinc-600" />
                            </marker>
                        </defs>
                        <rect width="100%" height="100%" fill="url(#grid)" />

                        {{-- Edges rendered programmatically --}}
                        <g x-ref="edgeGroup"></g>

                        {{-- Connection drag bezier --}}
                        <path
                            x-show="connecting"
                            :d="connectPath()"
                            fill="none"
                            stroke="#60a5fa"
                            stroke-width="2"
                            stroke-dasharray="6,4"
                        />
                    </svg>

                    {{-- Nodes --}}
                    <template x-for="node in nodes" :key="node.id">
                        <div
                            class="absolute left-0 top-0 select-none overflow-hidden rounded-lg bg-white shadow-md will-change-transform touch-none dark:bg-zinc-800"
                            :class="{
                                'opacity-50': !node.is_enabled,
                                'ring-2 ring-blue-400 ring-offset-1 dark:ring-offset-zinc-900': connecting && connectFromId !== node.id,
                                'shadow-xl z-50': dragging && dragging.id === node.id,
                                'cursor-move': !connecting,
                                'cursor-crosshair': connecting,
                            }"
                            :style="`transform: translate(${node.position_x}px, ${node.position_y}px); width: 240px`"
                            x-on:pointerdown.prevent="startDrag($event, node)"
                            x-on:dblclick.stop="$wire.openNodeConfig(node.id)"
                        >
                            {{-- Colored header bar --}}
                            <div class="flex items-center justify-between px-3 py-1.5"
                                :class="{
                                    'bg-amber-50 dark:bg-amber-900/30': node.type === 'trigger',
                                    'bg-blue-50 dark:bg-blue-900/30': node.type === 'action',
                                    'bg-purple-50 dark:bg-purple-900/30': node.type === 'if_else',
                                    'bg-orange-50 dark:bg-orange-900/30': node.type === 'wait',
                                    'bg-green-50 dark:bg-green-900/30': node.type === 'goal',
                                    'bg-zinc-50 dark:bg-zinc-700': node.type === 'goto',
                                }"
                            >
                                <span class="truncate text-[10px] font-bold uppercase tracking-wider"
                                    :class="{
                                        'text-amber-700 dark:text-amber-300': node.type === 'trigger',
                                        'text-blue-700 dark:text-blue-300': node.type === 'action',
                                        'text-purple-700 dark:text-purple-300': node.type === 'if_else',
                                        'text-orange-700 dark:text-orange-300': node.type === 'wait',
                                        'text-green-700 dark:text-green-300': node.type === 'goal',
                                        'text-zinc-500 dark:text-zinc-400': node.type === 'goto',
                                    }"
                                    x-text="node.type.replace('_', '/')"
                                ></span>
                                <div class="flex items-center gap-1.5">
                                    <span x-show="needsConfig(node)" class="flex h-2 w-2" title="Needs configuration">
                                        <span class="absolute inline-flex h-2 w-2 animate-ping rounded-full bg-orange-400 opacity-75"></span>
                                        <span class="relative inline-flex h-2 w-2 rounded-full bg-orange-500"></span>
                                    </span>
                                    <span x-show="!node.is_enabled" class="text-[10px] font-medium text-zinc-400">OFF</span>
                                </div>
                            </div>

                            {{-- Node body --}}
                            <div class="px-3 py-2">
                                <p class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100" x-text="node.label"></p>
                                <p class="mt-0.5 truncate text-xs text-zinc-400" x-text="getConfigSummary(node) || 'Double-click to configure'"></p>
                            </div>

                            {{-- Always-visible action buttons --}}
                            <div class="flex border-t border-zinc-100 dark:border-zinc-700">
                                <button @click.stop="$wire.openNodeConfig(node.id)"
                                    class="flex flex-1 items-center justify-center gap-1 py-1.5 text-[11px] text-zinc-500 hover:bg-zinc-50 hover:text-zinc-800 dark:hover:bg-zinc-700/50 dark:hover:text-zinc-200">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                    Edit
                                </button>
                                    <button @click.stop="$wire.deleteNode(node.id)"
                                        class="flex flex-1 items-center justify-center gap-1 border-l border-zinc-100 py-1.5 text-[11px] text-zinc-500 hover:bg-red-50 hover:text-red-600 dark:border-zinc-700 dark:hover:bg-red-900/20 dark:hover:text-red-400">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        Delete
                                    </button>
                            </div>

                            {{-- Connection output handle (bottom) --}}
                            <div class="absolute -bottom-3 left-1/2 flex h-6 w-6 -translate-x-1/2 cursor-crosshair items-center justify-center"
                                @pointerdown.stop.prevent="startConnect($event, node)" title="Drag to connect">
                                <div class="h-3 w-3 rounded-full border-2 border-zinc-400 bg-white transition-transform hover:scale-150 dark:bg-zinc-800"></div>
                            </div>

                            {{-- Connection input indicator (top) --}}
                            <div class="absolute -top-3 left-1/2 flex h-6 w-6 -translate-x-1/2 items-center justify-center"
                                x-show="connecting && connectFromId !== node.id">
                                <div class="h-3 w-3 animate-pulse rounded-full border-2 border-blue-400 bg-blue-100 dark:bg-blue-900/50"></div>
                            </div>
                        </div>
                    </template>

                    {{-- Empty state --}}
                    <div x-show="nodes.length === 0" x-cloak class="flex h-full items-center justify-center">
                        <div class="text-center">
                            <p class="text-sm text-zinc-500">This workflow has no nodes yet.</p>
                            <p class="mt-1 text-xs text-zinc-400">Drag a trigger from the left panel to get started.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Node Config Modal --}}
        <flux:modal wire:model="showNodeConfig" class="max-w-2xl">
            <div class="space-y-4">
                <flux:heading size="lg">Configure Node</flux:heading>

                <flux:field>
                    <flux:label>Label</flux:label>
                    <flux:input wire:model="nodeLabel" placeholder="Custom label for this node" />
                </flux:field>

                @if ($nodeClass === \App\Automation\Triggers\InboundWebhook::class)
                    @include('components.settings._webhook-trigger-config')
                @elseif ($nodeClass && class_exists($nodeClass) && method_exists($nodeClass, 'getConfigSchema'))
                    @include('components.settings._workflow-schema-fields')
                @endif

                <div class="flex justify-end gap-2">
                    <flux:button wire:click="$set('showNodeConfig', false)">Cancel</flux:button>
                    <flux:button variant="primary" wire:click="saveNodeConfig">Save</flux:button>
                </div>
            </div>
        </flux:modal>

    @endif
</div>

{{-- Alpine.js Canvas Logic --}}
@script
<script>
    Alpine.data('workflowBuilder', (initialNodes, initialEdges, registry) => ({
        nodes: initialNodes,
        edges: initialEdges,
        registry: registry,

        // Drag state
        dragging: null,
        dragOffset: { x: 0, y: 0 },
        _rafId: null,
        _saveTimer: null,
        _dirtyPositions: new Map(),
        _cachedRect: null,
        _pointerId: null,

        // Connection state
        connecting: false,
        connectFromId: null,
        connectFrom: { x: 0, y: 0 },
        connectTo: { x: 0, y: 0 },

        // Sidebar drag-and-drop state
        dragOverCanvas: false,
        _dragType: null,
        _dragClass: null,

        // Edge element cache
        _edgeCache: new Map(),

        // Node lookup map for O(1) access during edge rendering
        _nodeMap: new Map(),

        // Keyboard listener ref
        _onKeyDown: null,

        // Node dimensions (keep in sync with template)
        NODE_W: 240,
        NODE_H: 96,

        // Dynamic canvas bounds based on actual node positions
        get canvasWidth() {
            let max = 800;
            for (const node of this.nodes) {
                max = Math.max(max, node.position_x + this.NODE_W + 200);
            }
            return max;
        },

        get canvasHeight() {
            let max = 600;
            for (const node of this.nodes) {
                max = Math.max(max, node.position_y + 200);
            }
            return max;
        },

        init() {
            // Lock page scroll while builder is open
            this._prevBodyOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';

            this._buildNodeMap();

            this.$wire.$on('workflow-data-updated', ({ nodes, edges }) => {
                this.nodes = nodes;
                this.edges = edges;
                this._buildNodeMap();
                this._edgeCache.clear();
                const group = this.$refs.edgeGroup;
                if (group) { while (group.firstChild) group.removeChild(group.firstChild); }
                this.$nextTick(() => this.renderEdges());
            });

            this._onKeyDown = (e) => {
                if (e.key === 'Escape') {
                    if (this.connecting) {
                        this.connecting = false;
                        this.connectFromId = null;
                    }
                    if (this.dragging) {
                        this.dragging = null;
                        this._cachedRect = null;
                        this._releaseCapture();
                        this._unlockScroll();
                    }
                }
            };
            document.addEventListener('keydown', this._onKeyDown);

            this.$nextTick(() => {
                this.renderEdges();
                // Auto-layout if all nodes are stacked on top of each other
                if (this.nodes.length > 1) {
                    const allStacked = this.nodes.every(n =>
                        n.position_x === this.nodes[0].position_x &&
                        n.position_y === this.nodes[0].position_y
                    );
                    if (allStacked) this.autoLayout();
                }
            });
        },

        destroy() {
            // Restore page scroll
            document.body.style.overflow = this._prevBodyOverflow || '';
            document.documentElement.style.overflow = '';

            if (this._rafId) cancelAnimationFrame(this._rafId);
            if (this._saveTimer) clearTimeout(this._saveTimer);
            if (this._onKeyDown) document.removeEventListener('keydown', this._onKeyDown);
            this._edgeCache.clear();
            this._nodeMap.clear();
        },

        _buildNodeMap() {
            this._nodeMap.clear();
            for (const node of this.nodes) {
                this._nodeMap.set(node.id, node);
            }
        },

        _releaseCapture() {
            const canvas = this.$refs.canvas;
            if (canvas && this._pointerId != null) {
                try { canvas.releasePointerCapture(this._pointerId); } catch (ex) {}
                this._pointerId = null;
            }
        },

        _lockScroll() {
            const canvas = this.$refs.canvas;
            if (canvas) canvas.style.overflow = 'hidden';
        },

        _unlockScroll() {
            const canvas = this.$refs.canvas;
            if (canvas) canvas.style.overflow = '';
        },

        // === Debounced position persistence ===
        // Collects dirty positions and batch-saves after 500ms idle.
        // Server uses #[Renderless] so no morph/re-render happens.

        markPositionDirty(node) {
            this._dirtyPositions.set(node.id, { id: node.id, x: node.position_x, y: node.position_y });
            if (this._saveTimer) clearTimeout(this._saveTimer);
            this._saveTimer = setTimeout(() => this.flushPositions(), 500);
        },

        flushPositions() {
            if (this._dirtyPositions.size === 0) return;
            const positions = Array.from(this._dirtyPositions.values());
            this._dirtyPositions.clear();
            this.$wire.saveNodePositions(positions);
        },

        // === Sidebar drag-and-drop ===

        handleDragStart(e, type, nodeClass) {
            this._dragType = type;
            this._dragClass = nodeClass;
            e.dataTransfer.effectAllowed = 'copy';
            e.dataTransfer.setData('text/plain', type);
        },

        handleCanvasDrop(e) {
            this.dragOverCanvas = false;
            if (!this._dragType) return;

            const canvas = this.$refs.canvas;
            const rect = canvas.getBoundingClientRect();
            const x = e.clientX - rect.left + canvas.scrollLeft;
            const y = e.clientY - rect.top + canvas.scrollTop;

            this.$wire.addNode(this._dragType, this._dragClass, x, y);
            this._dragType = null;
            this._dragClass = null;
        },

        // === Bezier path generation ===

        bezierPath(sx, sy, tx, ty) {
            const dy = Math.abs(ty - sy);
            const offset = Math.max(dy * 0.4, 40);
            return `M ${sx} ${sy} C ${sx} ${sy + offset}, ${tx} ${ty - offset}, ${tx} ${ty}`;
        },

        connectPath() {
            return this.bezierPath(
                this.connectFrom.x, this.connectFrom.y,
                this.connectTo.x, this.connectTo.y
            );
        },

        // === Optimized edge rendering with bezier curves ===
        // Uses element cache: updates path d-attribute during drag, creates SVG on structural changes.

        renderEdges() {
            const group = this.$refs.edgeGroup;
            if (!group) return;

            const currentIds = new Set(this.edges.map(e => e.id));
            const isDark = document.documentElement.classList.contains('dark');
            const strokeColor = isDark ? '#52525b' : '#a1a1aa';

            // Remove deleted edges from cache and DOM
            for (const [id, els] of this._edgeCache) {
                if (!currentIds.has(id)) {
                    els.g.remove();
                    this._edgeCache.delete(id);
                }
            }

            const svgNS = 'http://www.w3.org/2000/svg';

            for (const edge of this.edges) {
                const fromNode = this._nodeMap.get(edge.from_node_id);
                const toNode = this._nodeMap.get(edge.to_node_id);
                if (!fromNode || !toNode) continue;

                // Source: bottom center of from node
                const sx = fromNode.position_x + this.NODE_W / 2;
                const sy = fromNode.position_y + this.NODE_H;
                // Target: top center of to node
                const tx = toNode.position_x + this.NODE_W / 2;
                const ty = toNode.position_y;

                const d = this.bezierPath(sx, sy, tx, ty);
                const midX = (sx + tx) / 2;
                const midY = (sy + ty) / 2;

                if (this._edgeCache.has(edge.id)) {
                    // Fast path: update bezier d-attribute only
                    const els = this._edgeCache.get(edge.id);
                    els.path.setAttribute('d', d);
                    els.hitPath.setAttribute('d', d);
                    if (els.branchText) {
                        els.branchText.setAttribute('x', midX + 8);
                        els.branchText.setAttribute('y', midY);
                    }
                    els.circle.setAttribute('cx', midX);
                    els.circle.setAttribute('cy', midY);
                    els.xMark.setAttribute('x', midX);
                    els.xMark.setAttribute('y', midY + 4);
                } else {
                    // Create new SVG edge elements
                    const g = document.createElementNS(svgNS, 'g');
                    g.style.pointerEvents = 'auto';

                    const path = document.createElementNS(svgNS, 'path');
                    path.setAttribute('d', d);
                    path.setAttribute('fill', 'none');
                    path.setAttribute('stroke', strokeColor);
                    path.setAttribute('stroke-width', '2');
                    path.setAttribute('marker-end', 'url(#arrowhead)');
                    g.appendChild(path);

                    // Invisible wider hit area for easier hover/click
                    const hitPath = document.createElementNS(svgNS, 'path');
                    hitPath.setAttribute('d', d);
                    hitPath.setAttribute('fill', 'none');
                    hitPath.setAttribute('stroke', 'transparent');
                    hitPath.setAttribute('stroke-width', '20');
                    hitPath.setAttribute('cursor', 'pointer');
                    g.appendChild(hitPath);

                    let branchText = null;
                    if (edge.branch) {
                        branchText = document.createElementNS(svgNS, 'text');
                        branchText.setAttribute('x', midX + 8);
                        branchText.setAttribute('y', midY);
                        branchText.setAttribute('fill', isDark ? '#71717a' : '#a1a1aa');
                        branchText.setAttribute('font-size', '10');
                        branchText.textContent = edge.branch;
                        g.appendChild(branchText);
                    }

                    const circle = document.createElementNS(svgNS, 'circle');
                    circle.setAttribute('cx', midX);
                    circle.setAttribute('cy', midY);
                    circle.setAttribute('r', '10');
                    circle.setAttribute('fill', isDark ? 'rgba(127, 29, 29, 0.5)' : '#fee2e2');
                    circle.setAttribute('opacity', '0');
                    circle.setAttribute('cursor', 'pointer');
                    g.appendChild(circle);

                    const xMark = document.createElementNS(svgNS, 'text');
                    xMark.setAttribute('x', midX);
                    xMark.setAttribute('y', midY + 4);
                    xMark.setAttribute('text-anchor', 'middle');
                    xMark.setAttribute('fill', '#ef4444');
                    xMark.setAttribute('font-size', '12');
                    xMark.setAttribute('font-weight', 'bold');
                    xMark.setAttribute('pointer-events', 'none');
                    xMark.setAttribute('opacity', '0');
                    xMark.textContent = '\u00d7';
                    g.appendChild(xMark);

                    const showDelete = () => { circle.setAttribute('opacity', '1'); xMark.setAttribute('opacity', '1'); path.setAttribute('stroke', '#ef4444'); path.setAttribute('stroke-width', '3'); };
                    const hideDelete = () => { circle.setAttribute('opacity', '0'); xMark.setAttribute('opacity', '0'); path.setAttribute('stroke', strokeColor); path.setAttribute('stroke-width', '2'); };
                    hitPath.addEventListener('pointerenter', showDelete);
                    hitPath.addEventListener('pointerleave', hideDelete);
                    circle.addEventListener('pointerenter', showDelete);
                    circle.addEventListener('pointerleave', hideDelete);

                    const edgeId = edge.id;
                    hitPath.addEventListener('click', () => this.$wire.deleteEdge(edgeId));
                    circle.addEventListener('click', () => this.$wire.deleteEdge(edgeId));

                    group.appendChild(g);
                    this._edgeCache.set(edge.id, { g, path, hitPath, branchText, circle, xMark });
                }
            }
        },

        // === Node dragging with pointer capture ===
        // setPointerCapture routes all pointer events to canvas, even outside bounds.
        // Eliminates stuck-drag bugs from missed mouseup events.

        startDrag(e, node) {
            if (this.connecting) return;
            if (e.target.closest('button')) return;

            const canvas = this.$refs.canvas;
            this._cachedRect = canvas.getBoundingClientRect();
            this._pointerId = e.pointerId;
            this.dragging = node;
            this.dragOffset = {
                x: e.clientX - this._cachedRect.left + canvas.scrollLeft - node.position_x,
                y: e.clientY - this._cachedRect.top + canvas.scrollTop - node.position_y,
            };

            // Capture pointer to canvas: all future pointermove/up route here
            canvas.setPointerCapture(e.pointerId);
            this._lockScroll();
        },

        onPointerMove(e) {
            if (this.dragging) {
                const canvas = this.$refs.canvas;
                const rect = this._cachedRect;
                if (!rect) return;

                // Mutate proxy directly, then reassign array to trigger Alpine reactivity
                this.dragging.position_x = Math.max(0, e.clientX - rect.left + canvas.scrollLeft - this.dragOffset.x);
                this.dragging.position_y = Math.max(0, e.clientY - rect.top + canvas.scrollTop - this.dragOffset.y);
                this.nodes = this.nodes;

                // Throttle edge redraws to animation frames
                if (!this._rafId) {
                    this._rafId = requestAnimationFrame(() => {
                        this.renderEdges();
                        this._rafId = null;
                    });
                }
                return;
            }

            if (this.connecting) {
                const canvas = this.$refs.canvas;
                const rect = canvas.getBoundingClientRect();
                this.connectTo = {
                    x: e.clientX - rect.left + canvas.scrollLeft,
                    y: e.clientY - rect.top + canvas.scrollTop,
                };
            }
        },

        onPointerUp(e) {
            if (this.dragging) {
                // Snap to 20px grid
                this.dragging.position_x = Math.round(this.dragging.position_x / 20) * 20;
                this.dragging.position_y = Math.round(this.dragging.position_y / 20) * 20;
                this.nodes = this.nodes;

                this.markPositionDirty(this.dragging);
                this.dragging = null;
                this._cachedRect = null;
                this._releaseCapture();
                this._unlockScroll();
                this.renderEdges();
                return;
            }

            if (this.connecting) {
                // Snap to nearest node within 80px for forgiving connection UX
                const canvas = this.$refs.canvas;
                const rect = canvas.getBoundingClientRect();
                const px = e.clientX - rect.left + canvas.scrollLeft;
                const py = e.clientY - rect.top + canvas.scrollTop;

                const nearest = this.findNearestNode(px, py, 80);
                if (nearest && nearest.id !== this.connectFromId) {
                    this.$wire.connectNodes(this.connectFromId, nearest.id);
                }

                this.connecting = false;
                this.connectFromId = null;
                this._releaseCapture();
                this._unlockScroll();
            }
        },

        findNearestNode(px, py, maxDist) {
            let best = null;
            let bestDist = maxDist;
            for (const node of this.nodes) {
                const cx = node.position_x + this.NODE_W / 2;
                const cy = node.position_y + this.NODE_H / 2;
                const d = Math.hypot(px - cx, py - cy);
                if (d < bestDist) {
                    bestDist = d;
                    best = node;
                }
            }
            return best;
        },

        // === Node connections ===

        startConnect(e, node) {
            const canvas = this.$refs.canvas;
            const rect = canvas.getBoundingClientRect();
            this.connecting = true;
            this.connectFromId = node.id;
            this.connectFrom = {
                x: node.position_x + this.NODE_W / 2,
                y: node.position_y + this.NODE_H,
            };
            this.connectTo = {
                x: e.clientX - rect.left + canvas.scrollLeft,
                y: e.clientY - rect.top + canvas.scrollTop,
            };

            // Capture pointer so canvas receives all move/up events
            this._pointerId = e.pointerId;
            canvas.setPointerCapture(e.pointerId);
            this._lockScroll();
        },

        // === Auto Layout (BFS tree from triggers) ===

        autoLayout() {
            if (this.nodes.length === 0) return;

            // Build adjacency from edges
            const children = new Map();
            const hasParent = new Set();
            for (const edge of this.edges) {
                if (!children.has(edge.from_node_id)) children.set(edge.from_node_id, []);
                children.get(edge.from_node_id).push(edge.to_node_id);
                hasParent.add(edge.to_node_id);
            }

            // Find roots (triggers or nodes with no incoming edges)
            const roots = this.nodes.filter(n => n.type === 'trigger' || !hasParent.has(n.id));
            if (roots.length === 0) roots.push(this.nodes[0]);

            const placed = new Set();
            const colSpacing = this.NODE_W + 60;
            const rowSpacing = this.NODE_H + 60;
            let col = 0;

            for (const root of roots) {
                // BFS per root
                const queue = [{ id: root.id, depth: 0 }];
                const depthNodes = new Map();

                while (queue.length > 0) {
                    const { id, depth } = queue.shift();
                    if (placed.has(id)) continue;
                    placed.add(id);

                    if (!depthNodes.has(depth)) depthNodes.set(depth, []);
                    depthNodes.get(depth).push(id);

                    const kids = children.get(id) || [];
                    for (const kid of kids) {
                        if (!placed.has(kid)) queue.push({ id: kid, depth: depth + 1 });
                    }
                }

                // Position nodes in tree: each depth is a row, centered horizontally per column
                const maxWidth = Math.max(...Array.from(depthNodes.values()).map(arr => arr.length));
                for (const [depth, ids] of depthNodes) {
                    for (let i = 0; i < ids.length; i++) {
                        const node = this.nodes.find(n => n.id === ids[i]);
                        if (!node) continue;
                        const totalW = ids.length * colSpacing;
                        const startX = col * (maxWidth * colSpacing) + 40;
                        node.position_x = startX + i * colSpacing + (maxWidth * colSpacing - totalW) / 2;
                        node.position_y = depth * rowSpacing + 40;
                    }
                }
                col++;
            }

            // Place any orphan nodes not reached by BFS
            let orphanY = 40;
            for (const node of this.nodes) {
                if (!placed.has(node.id)) {
                    node.position_x = col * (this.NODE_W + 60) + 40;
                    node.position_y = orphanY;
                    orphanY += rowSpacing;
                }
            }

            this.nodes = this.nodes;
            this._buildNodeMap();
            this.renderEdges();

            // Batch save all positions
            const positions = this.nodes.map(n => ({ id: n.id, x: Math.round(n.position_x), y: Math.round(n.position_y) }));
            this.$wire.saveNodePositions(positions);
        },

        // === Fit View (scroll canvas to show all nodes centered) ===

        fitView() {
            if (this.nodes.length === 0) return;
            const canvas = this.$refs.canvas;
            if (!canvas) return;

            let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
            for (const node of this.nodes) {
                minX = Math.min(minX, node.position_x);
                minY = Math.min(minY, node.position_y);
                maxX = Math.max(maxX, node.position_x + this.NODE_W);
                maxY = Math.max(maxY, node.position_y + this.NODE_H);
            }

            const contentW = maxX - minX;
            const contentH = maxY - minY;
            const viewW = canvas.clientWidth;
            const viewH = canvas.clientHeight;

            // Scroll so content is centered in view
            const targetScrollX = Math.max(0, minX - Math.max(40, (viewW - contentW) / 2));
            const targetScrollY = Math.max(0, minY - Math.max(40, (viewH - contentH) / 2));

            canvas.scrollTo({ left: targetScrollX, top: targetScrollY, behavior: 'smooth' });
        },

        // === Node config helpers ===

        findRegistryItem(node) {
            const typeMap = { trigger: 'triggers', action: 'actions', if_else: 'conditions', wait: 'waits' };
            const section = typeMap[node.type];
            if (!section || !this.registry[section]) return null;
            return this.registry[section].find(item => item.class === node.node_class) || null;
        },

        needsConfig(node) {
            const item = this.findRegistryItem(node);
            if (!item || !item.config_schema) return false;
            const schema = item.config_schema;
            const config = node.config || {};
            for (const key of Object.keys(schema)) {
                const val = config[key];
                if (val === undefined || val === null || val === '') return true;
            }
            return false;
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
</script>
@endscript
