<?php

use App\Automation\Actions\CreateCustomer;
use App\Automation\Actions\CreateDeal;
use App\Automation\Actions\CreateProperty;
use App\Automation\Registry;
use App\Automation\Triggers\ExternalApiPoll;
use App\Automation\Triggers\InboundWebhook;
use App\Models\AutomationWebhook;
use App\Models\ExternalApiSource;
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
    public int $workflowId;
    public string $workflowName = '';
    public string $workflowDescription = '';
    public bool $workflowIsActive = true;

    // Node config modal
    public bool $showNodeConfig = false;
    public ?int $configuringNodeId = null;
    public string $nodeLabel = '';
    public string $nodeClass = '';
    public array $nodeConfig = [];

    // Run history modal
    public bool $showRunHistory = false;
    public ?int $runHistoryWorkflowId = null;

    // Add node panel
    public string $addNodeType = '';

    // Dynamic field mapping for all triggers
    public ?array $availableDynamicFields = null;

    // Webhook config state
    public ?string $webhookUrl = null;
    public ?int $webhookModelId = null;
    public ?array $webhookSamplePayload = null;
    public ?array $webhookAvailableFields = null;
    public string $webhookAuthType = 'none';
    public string $webhookAuthHeader = '';
    public string $webhookAuthValue = '';

    // API source config state
    public ?int $apiSourceModelId = null;
    public ?string $apiSourceUrl = null;
    public ?array $apiSourceSamplePayload = null;
    public ?array $apiSourceAvailableFields = null;

    public function mount(Workflow $workflow): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $this->workflowId = $workflow->id;
        $this->workflowName = $workflow->name;
        $this->workflowDescription = $workflow->description ?? '';
        $this->workflowIsActive = $workflow->is_active;
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
    public function workflow(): ?Workflow
    {
        return Workflow::with(['nodes', 'edges'])->find($this->workflowId);
    }

    #[Computed]
    public function workflowNodes(): array
    {
        $workflow = $this->workflow;
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
        $workflow = $this->workflow;
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

    // === Navigation ===

    public function backToList(): void
    {
        $this->redirect(route('workflows.edit'));
    }

    // === Builder Actions ===

    public function saveWorkflowSettings(): void
    {
        $this->validate([
            'workflowName' => 'required|string|max:100',
            'workflowDescription' => 'nullable|string|max:500',
        ]);

        $workflow = Workflow::find($this->workflowId);
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
        $workflow = $this->workflow;
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
        $this->skipRender();
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

        // Reset all field mapping state
        $this->availableDynamicFields = null;
        $this->webhookUrl = null;
        $this->webhookModelId = null;
        $this->webhookSamplePayload = null;
        $this->webhookAvailableFields = null;
        $this->webhookAuthType = 'none';
        $this->webhookAuthHeader = '';
        $this->webhookAuthValue = '';
        $this->apiSourceModelId = null;
        $this->apiSourceUrl = null;
        $this->apiSourceSamplePayload = null;
        $this->apiSourceAvailableFields = null;

        // Special handling for InboundWebhook trigger
        if ($this->nodeClass === InboundWebhook::class) {
            $this->ensureWebhookExists();
        }

        // Special handling for ExternalApiPoll trigger
        if ($this->nodeClass === ExternalApiPoll::class) {
            $this->ensureApiSourceExists();
        }

        // Load dynamic fields for action and condition nodes
        if (in_array($node->type, ['action', 'if_else'])) {
            $this->loadDynamicFieldsFromTrigger();
            $this->autoMapFields();
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

        // Save webhook auth config if applicable
        if ($this->nodeClass === InboundWebhook::class && $this->webhookModelId) {
            $this->saveWebhookAuthConfig();
        }

        // Save API source config if applicable
        if ($this->nodeClass === ExternalApiPoll::class && $this->apiSourceModelId) {
            $this->saveApiSourceConfig();
        }

        $this->showNodeConfig = false;
        $this->availableDynamicFields = null;
        $this->webhookUrl = null;
        $this->webhookModelId = null;
        $this->webhookSamplePayload = null;
        $this->webhookAvailableFields = null;
        $this->webhookAuthType = 'none';
        $this->webhookAuthHeader = '';
        $this->webhookAuthValue = '';
        $this->apiSourceModelId = null;
        $this->apiSourceUrl = null;
        $this->apiSourceSamplePayload = null;
        $this->apiSourceAvailableFields = null;
        $this->dispatchWorkflowData();
    }

    public function toggleNodeEnabled(int $nodeId): void
    {
        $node = WorkflowNode::find($nodeId);
        if ($node) {
            $node->update(['is_enabled' => ! $node->is_enabled]);
            $this->dispatchWorkflowData();
            $this->skipRender();
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
        $this->skipRender();
    }

    public function connectNodes(int $fromId, int $toId, ?string $branch = null): void
    {
        $workflow = $this->workflow;
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
        $this->skipRender();
    }

    public function deleteEdge(int $edgeId): void
    {
        WorkflowEdge::where('id', $edgeId)->delete();
        $this->dispatchWorkflowData();
        $this->skipRender();
    }

    public function viewRunHistory(int $id): void
    {
        $this->runHistoryWorkflowId = $id;
        $this->showRunHistory = true;
        unset($this->runs);
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
            $workflow = $this->workflow;
            $webhook = AutomationWebhook::create([
                'name' => ($workflow?->name ?? 'Workflow') . ' Webhook',
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);
            $this->nodeConfig['webhook_token'] = $webhook->token;
        }

        $this->webhookModelId = $webhook->id;
        $this->webhookUrl = $webhook->getWebhookUrl();
        $this->webhookAuthType = $webhook->auth_type ?? 'none';
        $this->webhookAuthHeader = $webhook->auth_header ?? '';
        $this->webhookAuthValue = $webhook->auth_value ?? '';
        $this->loadWebhookPayloadFromModel($webhook);
    }

    public function saveWebhookAuthConfig(): void
    {
        $webhook = AutomationWebhook::find($this->webhookModelId);
        if (! $webhook) {
            return;
        }

        $webhook->update([
            'auth_type' => $this->webhookAuthType,
            'auth_header' => in_array($this->webhookAuthType, ['api_key', 'query_param']) ? $this->webhookAuthHeader : null,
            'auth_value' => in_array($this->webhookAuthType, ['api_key', 'query_param', 'bearer']) ? $this->webhookAuthValue : null,
        ]);
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
        $workflow = $this->workflow;
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

    // === API Source Config Methods ===

    public function ensureApiSourceExists(): void
    {
        $token = $this->nodeConfig['api_source_token'] ?? null;

        if ($token) {
            $source = ExternalApiSource::where('token', $token)->first();
        } else {
            $source = null;
        }

        if (! $source) {
            $workflow = $this->workflow;
            $source = ExternalApiSource::create([
                'name' => ($workflow?->name ?? 'Workflow') . ' API Source',
                'url' => '',
                'is_active' => true,
                'created_by' => auth()->id(),
            ]);
            $this->nodeConfig['api_source_token'] = $source->token;
        }

        $this->apiSourceModelId = $source->id;
        $this->apiSourceUrl = $source->url ?: '';
        $this->nodeConfig['http_method'] = $source->http_method ?? 'GET';
        $this->nodeConfig['poll_interval'] = $source->poll_interval ?? 'every_5_min';
        $this->nodeConfig['auth_type'] = $source->auth_type ?? 'none';
        $this->nodeConfig['auth_header'] = $source->auth_header ?? '';
        $this->nodeConfig['auth_value'] = $source->auth_value ?? '';
        $this->nodeConfig['data_path'] = $source->data_path ?? '';
        $this->nodeConfig['dedup_field'] = $source->dedup_field ?? '';
        $this->loadApiSourcePayloadFromModel($source);
    }

    public function saveApiSourceConfig(): void
    {
        $source = ExternalApiSource::find($this->apiSourceModelId);
        if (! $source) {
            return;
        }

        $source->update([
            'url' => $this->apiSourceUrl ?? '',
            'http_method' => $this->nodeConfig['http_method'] ?? 'GET',
            'poll_interval' => $this->nodeConfig['poll_interval'] ?? 'every_5_min',
            'auth_type' => $this->nodeConfig['auth_type'] ?? 'none',
            'auth_header' => $this->nodeConfig['auth_header'] ?? null,
            'auth_value' => $this->nodeConfig['auth_value'] ?? null,
            'data_path' => $this->nodeConfig['data_path'] ?? null,
            'dedup_field' => $this->nodeConfig['dedup_field'] ?? null,
        ]);
    }

    public function testApiSource(): void
    {
        if (! $this->apiSourceModelId) {
            return;
        }

        $source = ExternalApiSource::find($this->apiSourceModelId);
        if (! $source) {
            return;
        }

        // Save current config first
        $this->saveApiSourceConfig();
        $source->refresh();

        if (! $source->url) {
            return;
        }

        try {
            $request = $source->buildHttpRequest();
            $method = strtolower($source->http_method ?? 'get');
            $response = $request->{$method}($source->url);

            if ($response->successful()) {
                $data = $response->json();
                $source->update([
                    'last_response' => $data,
                    'last_success_at' => now(),
                    'last_error' => null,
                ]);
                $this->loadApiSourcePayloadFromModel($source);
            } else {
                $source->update([
                    'last_error' => "HTTP {$response->status()}: {$response->body()}",
                    'error_count' => $source->error_count + 1,
                ]);
            }
        } catch (\Exception $e) {
            $source->update([
                'last_error' => $e->getMessage(),
                'error_count' => $source->error_count + 1,
            ]);
        }
    }

    public function clearApiSourcePayload(): void
    {
        if (! $this->apiSourceModelId) {
            return;
        }

        $source = ExternalApiSource::find($this->apiSourceModelId);
        if ($source) {
            $source->update(['last_response' => null]);
        }

        $this->apiSourceSamplePayload = null;
        $this->apiSourceAvailableFields = null;
    }

    public function loadApiSourceFieldsFromTrigger(): void
    {
        $workflow = $this->workflow;
        if (! $workflow) {
            return;
        }

        $triggerNode = $workflow->nodes
            ->where('node_class', ExternalApiPoll::class)
            ->first();

        if (! $triggerNode) {
            return;
        }

        $token = $triggerNode->config['api_source_token'] ?? null;
        if (! $token) {
            return;
        }

        $source = ExternalApiSource::where('token', $token)->first();
        if (! $source || ! $source->last_response) {
            return;
        }

        $this->loadApiSourcePayloadFromModel($source);
    }

    protected function loadApiSourcePayloadFromModel(ExternalApiSource $source): void
    {
        $payload = $source->last_response;

        // Extract records using data_path if set
        if ($source->data_path && $payload) {
            $records = data_get($payload, $source->data_path);
            if (is_array($records) && ! empty($records)) {
                // Use first record as sample
                $sample = is_array($records[0] ?? null) ? $records[0] : $records;
                $this->apiSourceSamplePayload = $sample;
            } else {
                $this->apiSourceSamplePayload = $payload;
            }
        } else {
            $this->apiSourceSamplePayload = $payload;
        }

        if ($this->apiSourceSamplePayload) {
            $this->apiSourceAvailableFields = collect(Arr::dot($this->apiSourceSamplePayload))
                ->map(fn ($value, $key) => [
                    'path' => $key,
                    'sample' => $value,
                ])
                ->values()
                ->toArray();

            // Make webhook fields available too so the schema fields dropdown works
            if (! $this->webhookAvailableFields) {
                $this->webhookAvailableFields = $this->apiSourceAvailableFields;
            }
        } else {
            $this->apiSourceAvailableFields = null;
        }
    }

    // === Dynamic Field Mapping ===

    public function loadDynamicFieldsFromTrigger(): void
    {
        $workflow = $this->workflow;
        if (! $workflow) {
            return;
        }

        $registry = app(Registry::class);
        $triggerNode = $workflow->nodes->where('type', 'trigger')->first();

        if ($triggerNode && class_exists($triggerNode->node_class)) {
            $triggerClass = $triggerNode->node_class;
            $this->availableDynamicFields = $registry->getAvailableFieldsForTrigger($triggerClass);

            // Also load webhook/API payload fields for backward compatibility
            if ($triggerClass === InboundWebhook::class) {
                $this->loadWebhookFieldsFromTrigger();
            } elseif ($triggerClass === ExternalApiPoll::class) {
                $this->loadApiSourceFieldsFromTrigger();
            }
        } else {
            // No trigger node yet — show all available dynamic values as fallback
            $fields = [];
            foreach ($registry->dynamicValues() as $resolverClass) {
                $prefix = $resolverClass::getPrefix();
                foreach ($resolverClass::getAvailableKeys() as $key) {
                    $fields[] = [
                        'prefix' => $prefix,
                        'key' => $key,
                        'path' => "{$prefix}.{$key}",
                        'label' => \Illuminate\Support\Str::title(str_replace('_', ' ', $prefix)).': '.\Illuminate\Support\Str::title(str_replace('_', ' ', $key)),
                    ];
                }
            }
            $this->availableDynamicFields = $fields;
        }
    }

    public function autoMapFields(): void
    {
        if (empty($this->availableDynamicFields)) {
            return;
        }

        if (! $this->nodeClass || ! class_exists($this->nodeClass) || ! method_exists($this->nodeClass, 'getConfigSchema')) {
            return;
        }

        $schema = $this->nodeClass::getConfigSchema();
        $dynamicFieldsByKey = collect($this->availableDynamicFields)->keyBy('key');

        foreach ($schema as $configKey => $fieldSchema) {
            // Skip if already configured
            if (! empty($this->nodeConfig[$configKey])) {
                continue;
            }

            // Skip non-string fields (booleans, integers, dropdowns with options)
            $type = $fieldSchema['type'] ?? 'string';
            if (! in_array($type, ['string', 'text'])) {
                continue;
            }

            // Skip fields with fixed options (dropdowns)
            if (isset($fieldSchema['options']) && is_array($fieldSchema['options'])) {
                continue;
            }

            // Try to find a matching dynamic field by key name (case-insensitive)
            $match = $dynamicFieldsByKey->first(function ($field) use ($configKey) {
                return strcasecmp($field['key'], $configKey) === 0;
            });

            if ($match) {
                $this->nodeConfig[$configKey] = '{{' . $match['path'] . '}}';
            }
        }
    }

    // === Helpers ===

    protected function dispatchWorkflowData(): void
    {
        unset($this->workflow, $this->workflowNodes, $this->workflowEdges);
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
                        class="group/node absolute left-0 top-0 select-none rounded-xl border bg-white shadow-sm will-change-transform touch-none dark:bg-zinc-800"
                        :class="{
                            'opacity-40': !node.is_enabled,
                            'ring-2 ring-blue-400 ring-offset-2 dark:ring-offset-zinc-900': connecting && connectFromId !== node.id,
                            'shadow-lg z-50 scale-[1.02]': dragging && dragging.id === node.id,
                            'cursor-grab': !connecting && !(dragging && dragging.id === node.id),
                            'cursor-grabbing': dragging && dragging.id === node.id,
                            'cursor-crosshair': connecting,
                            'border-amber-300 dark:border-amber-700': node.type === 'trigger',
                            'border-blue-300 dark:border-blue-700': node.type === 'action',
                            'border-purple-300 dark:border-purple-700': node.type === 'if_else',
                            'border-orange-300 dark:border-orange-700': node.type === 'wait',
                            'border-green-300 dark:border-green-700': node.type === 'goal',
                            'border-zinc-300 dark:border-zinc-600': node.type === 'goto',
                        }"
                        :style="`transform: translate(${node.position_x}px, ${node.position_y}px); width: ${NODE_W}px`"
                        x-on:pointerdown.prevent="startDrag($event, node)"
                        x-on:dblclick.stop="$wire.openNodeConfig(node.id)"
                    >
                        {{-- Input port (always visible) --}}
                        <div class="absolute -top-2.5 left-1/2 -translate-x-1/2">
                            <div class="h-2.5 w-2.5 rounded-full border-2 bg-white transition-all dark:bg-zinc-800"
                                :class="{
                                    'border-blue-400 scale-150 bg-blue-50 dark:bg-blue-900/50': connecting && connectFromId !== node.id,
                                    'border-zinc-300 dark:border-zinc-600': !(connecting && connectFromId !== node.id),
                                }"
                            ></div>
                        </div>

                        {{-- Colored header --}}
                        <div class="flex items-center justify-between rounded-t-xl px-3 py-2"
                            :class="{
                                'bg-amber-50 dark:bg-amber-900/20': node.type === 'trigger',
                                'bg-blue-50 dark:bg-blue-900/20': node.type === 'action',
                                'bg-purple-50 dark:bg-purple-900/20': node.type === 'if_else',
                                'bg-orange-50 dark:bg-orange-900/20': node.type === 'wait',
                                'bg-green-50 dark:bg-green-900/20': node.type === 'goal',
                                'bg-zinc-50 dark:bg-zinc-700/50': node.type === 'goto',
                            }"
                        >
                            <div class="flex items-center gap-2 min-w-0">
                                {{-- Type icon --}}
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded text-xs"
                                    :class="{
                                        'bg-amber-200 text-amber-800 dark:bg-amber-800 dark:text-amber-200': node.type === 'trigger',
                                        'bg-blue-200 text-blue-800 dark:bg-blue-800 dark:text-blue-200': node.type === 'action',
                                        'bg-purple-200 text-purple-800 dark:bg-purple-800 dark:text-purple-200': node.type === 'if_else',
                                        'bg-orange-200 text-orange-800 dark:bg-orange-800 dark:text-orange-200': node.type === 'wait',
                                        'bg-green-200 text-green-800 dark:bg-green-800 dark:text-green-200': node.type === 'goal',
                                        'bg-zinc-200 text-zinc-600 dark:bg-zinc-600 dark:text-zinc-300': node.type === 'goto',
                                    }"
                                >
                                    <template x-if="node.type === 'trigger'">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                    </template>
                                    <template x-if="node.type === 'action'">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/></svg>
                                    </template>
                                    <template x-if="node.type === 'if_else'">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </template>
                                    <template x-if="node.type === 'wait'">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </template>
                                    <template x-if="node.type === 'goal'">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                    </template>
                                    <template x-if="node.type === 'goto'">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                                    </template>
                                </span>
                                <span class="truncate text-[10px] font-bold uppercase tracking-wider"
                                    :class="{
                                        'text-amber-700 dark:text-amber-300': node.type === 'trigger',
                                        'text-blue-700 dark:text-blue-300': node.type === 'action',
                                        'text-purple-700 dark:text-purple-300': node.type === 'if_else',
                                        'text-orange-700 dark:text-orange-300': node.type === 'wait',
                                        'text-green-700 dark:text-green-300': node.type === 'goal',
                                        'text-zinc-500 dark:text-zinc-400': node.type === 'goto',
                                    }"
                                    x-text="node.type === 'if_else' ? 'IF / ELSE' : node.type"
                                ></span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <span x-show="needsConfig(node)" class="relative flex h-2.5 w-2.5" title="Needs configuration">
                                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-orange-400 opacity-75"></span>
                                    <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-orange-500"></span>
                                </span>
                                <span x-show="!node.is_enabled" class="rounded bg-zinc-200 px-1 py-0.5 text-[9px] font-bold text-zinc-500 dark:bg-zinc-700">OFF</span>
                            </div>
                        </div>

                        {{-- Node body --}}
                        <div class="px-3 py-2.5">
                            <p class="truncate text-[13px] font-semibold text-zinc-900 dark:text-zinc-100" x-text="node.label"></p>
                            <p class="mt-1 truncate text-xs text-zinc-400" x-text="getConfigSummary(node) || 'Double-click to configure'"></p>
                        </div>

                        {{-- Action buttons - use pointerdown.stop so drag doesn't fire --}}
                        <div class="flex border-t border-zinc-100 dark:border-zinc-700/50">
                            <button @pointerdown.stop @click.stop="$wire.openNodeConfig(node.id)"
                                class="flex flex-1 items-center justify-center gap-1.5 py-1.5 text-[11px] text-zinc-400 hover:bg-zinc-50 hover:text-zinc-700 dark:hover:bg-zinc-700/50 dark:hover:text-zinc-200">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                Config
                            </button>
                            <button @pointerdown.stop @click.stop="$wire.deleteNode(node.id)"
                                class="flex flex-1 items-center justify-center gap-1.5 border-l border-zinc-100 py-1.5 text-[11px] text-zinc-400 hover:bg-red-50 hover:text-red-600 dark:border-zinc-700/50 dark:hover:bg-red-900/20 dark:hover:text-red-400">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                Delete
                            </button>
                        </div>

                        {{-- Output handle: single port for non-condition, dual ports for if/else --}}
                        <template x-if="node.type !== 'if_else'">
                            <div class="absolute -bottom-4 left-1/2 flex h-8 w-8 -translate-x-1/2 cursor-crosshair items-center justify-center"
                                @pointerdown.stop.prevent="startConnect($event, node, null)" title="Drag to connect">
                                <div class="h-4 w-4 rounded-full border-2 border-zinc-400 bg-white shadow-sm transition-all hover:scale-125 hover:border-blue-500 hover:shadow-md dark:bg-zinc-800"></div>
                            </div>
                        </template>
                        <template x-if="node.type === 'if_else'">
                            <div class="absolute -bottom-6 left-0 flex w-full justify-between px-8">
                                {{-- True (Yes) port --}}
                                <div class="flex cursor-crosshair flex-col items-center gap-0.5"
                                    @pointerdown.stop.prevent="startConnect($event, node, 'true')" title="Yes branch">
                                    <div class="h-4 w-4 rounded-full border-2 border-green-500 bg-white shadow-sm transition-all hover:scale-125 hover:shadow-md dark:bg-zinc-800"></div>
                                    <span class="text-[9px] font-bold text-green-600 dark:text-green-400">YES</span>
                                </div>
                                {{-- False (No) port --}}
                                <div class="flex cursor-crosshair flex-col items-center gap-0.5"
                                    @pointerdown.stop.prevent="startConnect($event, node, 'false')" title="No branch">
                                    <div class="h-4 w-4 rounded-full border-2 border-red-500 bg-white shadow-sm transition-all hover:scale-125 hover:shadow-md dark:bg-zinc-800"></div>
                                    <span class="text-[9px] font-bold text-red-600 dark:text-red-400">NO</span>
                                </div>
                            </div>
                        </template>
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
            @elseif ($nodeClass === \App\Automation\Triggers\ExternalApiPoll::class)
                @include('components.settings._api-poll-trigger-config')
            @elseif ($nodeClass && class_exists($nodeClass) && method_exists($nodeClass, 'getConfigSchema'))
                @include('components.settings._workflow-schema-fields')
            @endif

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showNodeConfig', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="saveNodeConfig">Save</flux:button>
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
</div>
