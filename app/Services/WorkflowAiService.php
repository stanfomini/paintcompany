<?php

namespace App\Services;

use App\Automation\Registry;
use App\Models\Workflow;
use App\Models\WorkflowEdge;
use App\Models\WorkflowNode;
use Illuminate\Support\Str;

class WorkflowAiService
{
    public function __construct(
        protected Registry $registry,
    ) {}

    /**
     * Get cached registry data from disk cache, rebuilding only when automation files change.
     * This avoids calling Registry::discoverClasses() during AI tool execution,
     * which can cause class redeclaration fatals under OPcache.
     *
     * @return array{triggers: array, actions: array, conditions: array, waits: array}
     */
    protected function getRegistryCache(): array
    {
        $cachePath = storage_path('framework/cache/workflow-ai-registry.php');

        if (file_exists($cachePath)) {
            return require $cachePath;
        }

        return $this->rebuildRegistryCache();
    }

    /**
     * Rebuild the registry cache file.
     *
     * @return array{triggers: array, actions: array, conditions: array, waits: array}
     */
    public function rebuildRegistryCache(): array
    {
        $data = [
            'triggers' => $this->registry->triggers()->map(function (string $class) {
                return [
                    'class' => $class,
                    'name' => $class::getName(),
                    'category' => $class::getCategory(),
                    'description' => $class::getDescription(),
                    'config_schema' => $class::getConfigSchema(),
                    'dynamic_prefixes' => $class::getAvailableDynamicPrefixes(),
                ];
            })->values()->all(),
            'actions' => $this->registry->actions()->map(function (string $class) {
                return [
                    'class' => $class,
                    'name' => $class::getName(),
                    'category' => $class::getCategory(),
                    'description' => $class::getDescription(),
                    'config_schema' => $class::getConfigSchema(),
                ];
            })->values()->all(),
            'conditions' => $this->registry->conditions()->map(function (string $class) {
                return [
                    'class' => $class,
                    'name' => $class::getName(),
                    'category' => $class::getCategory(),
                    'description' => $class::getDescription(),
                    'config_schema' => $class::getConfigSchema(),
                ];
            })->values()->all(),
            'waits' => $this->registry->waits()->map(function (string $class) {
                return [
                    'class' => $class,
                    'name' => $class::getName(),
                    'category' => $class::getCategory(),
                    'description' => $class::getDescription(),
                    'config_schema' => $class::getConfigSchema(),
                ];
            })->values()->all(),
        ];

        $cachePath = storage_path('framework/cache/workflow-ai-registry.php');
        file_put_contents($cachePath, '<?php return '.var_export($data, true).';');

        return $data;
    }

    /**
     * Add a node to a workflow with auto-positioning.
     *
     * @return array{node_id: int, position: array{x: float, y: float}}
     */
    public function addNode(
        Workflow $workflow,
        string $type,
        string $nodeClass,
        ?string $label = null,
        ?float $x = null,
        ?float $y = null,
    ): array {
        $this->validateNodeType($type);
        $this->validateNodeClass($type, $nodeClass);

        $posX = $x !== null ? round($x / 20) * 20 : 250;
        $posY = $y !== null ? round($y / 20) * 20 : ($workflow->nodes()->max('position_y') ?? 0) + 160;

        // Triggers should be at y=40 if this is the first trigger (unless custom y was provided)
        if ($y === null && $type === 'trigger' && $workflow->nodes()->where('type', 'trigger')->doesntExist()) {
            $posY = 40;
        }

        $node = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => $type,
            'node_class' => $nodeClass,
            'config' => [],
            'position_x' => $posX,
            'position_y' => $posY,
            'label' => $label,
            'is_enabled' => true,
        ]);

        // Auto-connect to leaf node if there's exactly one
        $this->autoConnectNode($workflow, $node);

        return [
            'node_id' => $node->id,
            'position' => ['x' => $node->position_x, 'y' => $node->position_y],
        ];
    }

    /**
     * Connect two nodes with an edge (with dedup check).
     *
     * @return array{edge_id: int}
     */
    public function connectNodes(
        Workflow $workflow,
        int $fromNodeId,
        int $toNodeId,
        ?string $branch = null,
    ): array {
        // Validate nodes belong to this workflow
        $fromNode = $workflow->nodes()->where('id', $fromNodeId)->firstOrFail();
        $toNode = $workflow->nodes()->where('id', $toNodeId)->firstOrFail();

        // Don't create duplicate edges
        $exists = WorkflowEdge::where('workflow_id', $workflow->id)
            ->where('from_node_id', $fromNodeId)
            ->where('to_node_id', $toNodeId)
            ->exists();

        if ($exists) {
            $edge = WorkflowEdge::where('workflow_id', $workflow->id)
                ->where('from_node_id', $fromNodeId)
                ->where('to_node_id', $toNodeId)
                ->first();

            return ['edge_id' => $edge->id];
        }

        $edge = WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $fromNodeId,
            'to_node_id' => $toNodeId,
            'branch' => $branch,
            'sort_order' => 0,
        ]);

        return ['edge_id' => $edge->id];
    }

    /**
     * Update a node's configuration and optional label.
     *
     * @return array{node_id: int, config: array<string, mixed>}
     */
    public function configureNode(
        int $nodeId,
        array $config,
        ?string $label = null,
    ): array {
        $node = WorkflowNode::findOrFail($nodeId);

        $updateData = ['config' => array_merge($node->config ?? [], $config)];
        if ($label !== null) {
            $updateData['label'] = $label;
        }

        $node->update($updateData);

        return [
            'node_id' => $node->id,
            'config' => $node->fresh()->config,
        ];
    }

    /**
     * Delete a node and its connected edges.
     *
     * @return array{deleted: bool}
     */
    public function removeNode(int $nodeId): array
    {
        $node = WorkflowNode::findOrFail($nodeId);

        WorkflowEdge::where('from_node_id', $nodeId)
            ->orWhere('to_node_id', $nodeId)
            ->delete();

        $node->delete();

        return ['deleted' => true];
    }

    /**
     * Delete an edge.
     *
     * @return array{deleted: bool}
     */
    public function removeEdge(int $edgeId): array
    {
        WorkflowEdge::findOrFail($edgeId)->delete();

        return ['deleted' => true];
    }

    /**
     * Return the full workflow state (all nodes + edges) as a structured array.
     *
     * @return array{nodes: array<int, mixed>, edges: array<int, mixed>}
     */
    public function getWorkflowState(Workflow $workflow): array
    {
        $workflow->load(['nodes', 'edges']);

        // Use cached registry to resolve labels without triggering autoloading
        $cache = $this->getRegistryCache();
        $classNameMap = collect($cache['triggers'])
            ->merge($cache['actions'])
            ->merge($cache['conditions'])
            ->merge($cache['waits'])
            ->pluck('name', 'class')
            ->all();

        $nodes = $workflow->nodes->map(function (WorkflowNode $node) use ($classNameMap) {
            $label = $node->label ?: ($classNameMap[$node->node_class] ?? class_basename($node->node_class));

            return [
                'id' => $node->id,
                'type' => $node->type,
                'node_class' => $node->node_class,
                'label' => $label,
                'config' => $node->config ?? [],
                'position' => ['x' => $node->position_x, 'y' => $node->position_y],
                'is_enabled' => $node->is_enabled,
            ];
        })->values()->all();

        $edges = $workflow->edges->map(function (WorkflowEdge $edge) {
            return [
                'id' => $edge->id,
                'from_node_id' => $edge->from_node_id,
                'to_node_id' => $edge->to_node_id,
                'branch' => $edge->branch,
            ];
        })->values()->all();

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * List all available triggers with metadata.
     *
     * @return array<int, array{class: string, name: string, category: string, description: string, config_schema: array, dynamic_prefixes: array}>
     */
    public function listTriggers(): array
    {
        return $this->getRegistryCache()['triggers'];
    }

    /**
     * List all available actions with metadata.
     *
     * @return array<int, array{class: string, name: string, category: string, description: string, config_schema: array}>
     */
    public function listActions(): array
    {
        return $this->getRegistryCache()['actions'];
    }

    /**
     * List all available conditions with metadata.
     *
     * @return array<int, array{class: string, name: string, category: string, description: string, config_schema: array}>
     */
    public function listConditions(): array
    {
        return $this->getRegistryCache()['conditions'];
    }

    /**
     * List all available waits with metadata.
     *
     * @return array<int, array{class: string, name: string, category: string, description: string, config_schema: array}>
     */
    public function listWaits(): array
    {
        return $this->getRegistryCache()['waits'];
    }

    /**
     * List available dynamic value fields for a given trigger class.
     *
     * @return array<int, array{prefix: string, key: string, path: string, label: string}>
     */
    public function listDynamicValues(string $triggerClass): array
    {
        // Find the trigger's dynamic prefixes from the cached data
        $cache = $this->getRegistryCache();
        $trigger = collect($cache['triggers'])->firstWhere('class', $triggerClass);

        if (! $trigger || empty($trigger['dynamic_prefixes'])) {
            return [];
        }

        // Build fields from prefixes using DynamicValue resolvers (already autoloaded)
        $fields = [];
        foreach ($trigger['dynamic_prefixes'] as $prefix) {
            $resolver = $this->registry->findDynamicValue($prefix);

            if (! $resolver) {
                continue;
            }

            foreach ($resolver::getAvailableKeys() as $key) {
                $fields[] = [
                    'prefix' => $prefix,
                    'key' => $key,
                    'path' => "{$prefix}.{$key}",
                    'label' => \Illuminate\Support\Str::title(str_replace('_', ' ', $prefix)).': '.\Illuminate\Support\Str::title(str_replace('_', ' ', $key)),
                ];
            }
        }

        return $fields;
    }

    /**
     * Generate a Claude Code prompt for building a missing feature.
     */
    public function generateMissingFeaturePrompt(
        string $featureName,
        string $featureType,
        string $description,
    ): string {
        $interface = match ($featureType) {
            'trigger' => 'TriggerInterface',
            'action' => 'ActionInterface',
            'condition' => 'ConditionInterface',
            'wait' => 'WaitInterface',
            default => 'ActionInterface',
        };

        $directory = match ($featureType) {
            'trigger' => 'Triggers',
            'action' => 'Actions',
            'condition' => 'Conditions',
            'wait' => 'Waits',
            default => 'Actions',
        };

        $className = Str::studly($featureName);

        return <<<PROMPT
        Create a new workflow {$featureType} for the painting company CRM automation system.

        ## What to Build
        - **Name**: {$featureName}
        - **Type**: {$featureType}
        - **Description**: {$description}

        ## File Location
        `app/Automation/{$directory}/{$className}.php`

        ## Interface to Implement
        `App\Automation\Contracts\\{$interface}`

        ## Required Methods
        - `getName()`: Return "{$featureName}"
        - `getCategory()`: Return an appropriate category string
        - `getDescription()`: Return "{$description}"
        - `getConfigSchema()`: Return array of config fields the user can set in the builder UI

        ## Reference
        Look at existing implementations in `app/Automation/{$directory}/` for patterns.
        The Registry (`app/Automation/Registry.php`) auto-discovers classes, so no registration needed.

        ## Testing
        Create a test at `tests/Feature/Automation/{$className}Test.php` covering the core logic.
        PROMPT;
    }

    /**
     * Validate that the node type is valid.
     */
    protected function validateNodeType(string $type): void
    {
        $validTypes = ['trigger', 'action', 'if_else', 'wait', 'goal', 'goto'];

        if (! in_array($type, $validTypes)) {
            throw new \InvalidArgumentException("Invalid node type: {$type}. Valid types: ".implode(', ', $validTypes));
        }
    }

    /**
     * Validate that the node class exists in the Registry for the given type.
     */
    protected function validateNodeClass(string $type, string $nodeClass): void
    {
        if (! class_exists($nodeClass)) {
            throw new \InvalidArgumentException("Node class does not exist: {$nodeClass}");
        }

        $cache = $this->getRegistryCache();
        $classesForType = match ($type) {
            'trigger' => collect($cache['triggers'])->pluck('class'),
            'action' => collect($cache['actions'])->pluck('class'),
            'if_else' => collect($cache['conditions'])->pluck('class'),
            'wait' => collect($cache['waits'])->pluck('class'),
            'goal', 'goto' => collect([$nodeClass]),
            default => collect(),
        };

        if (! $classesForType->contains($nodeClass)) {
            throw new \InvalidArgumentException("Node class {$nodeClass} is not registered for type {$type}");
        }
    }

    /**
     * Auto-connect a new node to the single leaf node if one exists.
     */
    protected function autoConnectNode(Workflow $workflow, WorkflowNode $newNode): void
    {
        $workflow->load(['nodes', 'edges']);

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
}
