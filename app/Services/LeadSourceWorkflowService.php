<?php

namespace App\Services;

use App\Models\LeadSource;
use App\Models\WebhookEndpoint;
use App\Models\Workflow;
use App\Models\WorkflowEdge;
use App\Models\WorkflowNode;

class LeadSourceWorkflowService
{
    /**
     * Create a default workflow and webhook endpoint for a lead source.
     */
    public function createDefaultWorkflow(LeadSource $source): Workflow
    {
        $workflow = Workflow::create([
            'name' => "Lead: {$source->name}",
            'description' => "Auto-created workflow for lead source: {$source->name}",
            'is_active' => true,
            'is_system' => false,
            'version' => 1,
            'priority' => 0,
            'user_scope_type' => Workflow::USER_SCOPE_ANY,
            'created_by' => auth()->id(),
        ]);

        $nodes = $this->createDefaultNodes($workflow);
        $this->createEdgesForNodes($workflow, $nodes);

        $source->update(['workflow_id' => $workflow->id]);
        $this->ensureWebhookEndpoint($source);

        return $workflow;
    }

    /**
     * Create a default workflow with a commission node for vendor-linked sources.
     */
    public function createDefaultWorkflowWithCommission(LeadSource $source): Workflow
    {
        $workflow = Workflow::create([
            'name' => "Lead: {$source->name}",
            'description' => "Auto-created workflow for lead source: {$source->name} (with commission)",
            'is_active' => true,
            'is_system' => false,
            'version' => 1,
            'priority' => 0,
            'user_scope_type' => Workflow::USER_SCOPE_ANY,
            'created_by' => auth()->id(),
        ]);

        $nodes = $this->createDefaultNodes($workflow);

        $commissionNode = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => 'App\\Automation\\Actions\\CreateLeadSourceCommission',
            'label' => 'Create Commission',
            'position_x' => 250,
            'position_y' => 650,
            'is_enabled' => true,
            'config' => [],
        ]);

        // Reposition SendNotification below commission
        $notificationNode = $nodes[array_key_last($nodes)];
        $notificationNode->update([
            'position_y' => 800,
        ]);

        // Insert commission before notification
        $nodesWithCommission = array_slice($nodes, 0, -1);
        $nodesWithCommission[] = $commissionNode;
        $nodesWithCommission[] = $notificationNode;

        $this->createEdgesForNodes($workflow, $nodesWithCommission);

        $source->update(['workflow_id' => $workflow->id]);
        $this->ensureWebhookEndpoint($source);

        return $workflow;
    }

    /**
     * Ensure a lead source has a webhook endpoint. Creates one if missing.
     */
    public function ensureWebhookEndpoint(LeadSource $source): WebhookEndpoint
    {
        return $source->webhookEndpoint ?? WebhookEndpoint::create([
            'lead_source_id' => $source->id,
            'is_active' => true,
            'auth_type' => 'none',
            'content_type' => 'application/json',
            'total_leads' => 0,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Backfill workflows and webhook endpoints for existing lead sources that lack them.
     */
    public function backfillExistingSources(): int
    {
        $sources = LeadSource::query()
            ->where(function ($q) {
                $q->whereNull('workflow_id')->orWhereDoesntHave('webhookEndpoint');
            })
            ->get();

        $count = 0;

        foreach ($sources as $source) {
            if (! $source->workflow_id) {
                if ($source->vendor_id) {
                    $this->createDefaultWorkflowWithCommission($source);
                } else {
                    $this->createDefaultWorkflow($source);
                }
            } else {
                $this->ensureWebhookEndpoint($source);
            }
            $count++;
        }

        return $count;
    }

    /**
     * Create the standard nodes for a lead source workflow.
     *
     * @return array<int, WorkflowNode>
     */
    protected function createDefaultNodes(Workflow $workflow): array
    {
        $nodes = [];

        $nodes[] = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'trigger',
            'node_class' => 'App\\Automation\\Triggers\\InboundWebhook',
            'label' => 'Inbound Webhook',
            'position_x' => 250,
            'position_y' => 50,
            'is_enabled' => true,
            'config' => [],
        ]);

        $nodes[] = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => 'App\\Automation\\Actions\\CreateCustomer',
            'label' => 'Create Customer',
            'position_x' => 250,
            'position_y' => 200,
            'is_enabled' => true,
            'config' => [],
        ]);

        $nodes[] = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => 'App\\Automation\\Actions\\CreateProperty',
            'label' => 'Create Property',
            'position_x' => 250,
            'position_y' => 350,
            'is_enabled' => true,
            'config' => [],
        ]);

        $nodes[] = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => 'App\\Automation\\Actions\\CreateDeal',
            'label' => 'Create Deal',
            'position_x' => 250,
            'position_y' => 500,
            'is_enabled' => true,
            'config' => [],
        ]);

        $nodes[] = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => 'App\\Automation\\Actions\\SendNotification',
            'label' => 'Send Notification',
            'position_x' => 250,
            'position_y' => 650,
            'is_enabled' => true,
            'config' => [
                'message' => 'New lead from {{webhook.source}}',
                'channels' => ['database'],
            ],
        ]);

        return $nodes;
    }

    /**
     * Create sequential edges connecting the given nodes.
     *
     * @param  array<int, WorkflowNode>  $nodes
     */
    protected function createEdgesForNodes(Workflow $workflow, array $nodes): void
    {
        for ($i = 0; $i < count($nodes) - 1; $i++) {
            WorkflowEdge::create([
                'workflow_id' => $workflow->id,
                'from_node_id' => $nodes[$i]->id,
                'to_node_id' => $nodes[$i + 1]->id,
                'branch' => 'default',
                'sort_order' => $i,
            ]);
        }
    }
}
