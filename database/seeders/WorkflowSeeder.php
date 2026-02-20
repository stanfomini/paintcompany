<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use App\Models\WorkflowEdge;
use App\Models\WorkflowNode;
use App\Models\WorkflowTrigger;
use Illuminate\Database\Seeder;

class WorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $this->createNewLeadFollowUp();
        $this->createEstimateSentFollowUp();
        $this->createStaleDealAlert();
        $this->createJobCompletionFollowUp();
        $this->createDoorKnockCustomerPipeline();

        // Phase 4a engine-based workflows (trigger/action model)
        $this->seedEngineWorkflows();
    }

    /**
     * Workflow 1: New Lead Follow-Up Sequence
     *
     * [Trigger: Deal Stage → "lead"]
     *   → [Action: Create Call Task]
     *   → [Wait: 24 hours]
     *   → [IF/ELSE: Deal advanced to quote_scheduled?]
     *       Yes → [End]
     *       No  → [Action: Follow-up call]
     *            → [Wait: 48 hours]
     *            → [Action: Follow-up email task]
     *            → [Wait: 7 days]
     *            → [Action: Final attempt task]
     */
    protected function createNewLeadFollowUp(): void
    {
        $workflow = Workflow::create([
            'name' => 'New Lead Follow-Up Sequence',
            'description' => 'Automated follow-up sequence when a new lead enters the pipeline. Creates call tasks at increasing intervals until the deal advances.',
            'is_active' => true,
            'is_system' => true,
            'version' => 1,
            'published_at' => now(),
        ]);

        // Nodes
        $trigger = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'trigger',
            'node_class' => \App\Automation\Triggers\DealStageChanged::class,
            'config' => ['target_stage' => 'lead'],
            'label' => 'New Lead Arrives',
            'position_x' => 400,
            'position_y' => 50,
        ]);

        $createCall = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\CreateTask::class,
            'config' => [
                'title' => 'Call {{contact.name}}',
                'type' => 'call',
                'priority' => 'high',
                'due_in_hours' => 0,
            ],
            'label' => 'Create Initial Call Task',
            'position_x' => 400,
            'position_y' => 150,
        ]);

        $wait24h = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'wait',
            'node_class' => \App\Automation\Waits\TimeDelay::class,
            'config' => ['hours' => 24],
            'label' => 'Wait 24 Hours',
            'position_x' => 400,
            'position_y' => 250,
        ]);

        $ifElse = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'if_else',
            'node_class' => 'if_else',
            'config' => [],
            'label' => 'Deal Advanced?',
            'position_x' => 400,
            'position_y' => 350,
        ]);

        $followUpCall = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\CreateTask::class,
            'config' => [
                'title' => 'Follow up: {{contact.name}}',
                'type' => 'call',
                'priority' => 'high',
                'due_in_hours' => 0,
            ],
            'label' => 'Create Follow-Up Call',
            'position_x' => 600,
            'position_y' => 450,
        ]);

        $wait48h = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'wait',
            'node_class' => \App\Automation\Waits\TimeDelay::class,
            'config' => ['hours' => 48],
            'label' => 'Wait 48 Hours',
            'position_x' => 600,
            'position_y' => 550,
        ]);

        $emailTask = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\CreateTask::class,
            'config' => [
                'title' => 'Send follow-up email to {{contact.name}}',
                'type' => 'email',
                'priority' => 'normal',
                'due_in_hours' => 0,
            ],
            'label' => 'Create Email Task',
            'position_x' => 600,
            'position_y' => 650,
        ]);

        $wait7d = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'wait',
            'node_class' => \App\Automation\Waits\TimeDelay::class,
            'config' => ['hours' => 168],
            'label' => 'Wait 7 Days',
            'position_x' => 600,
            'position_y' => 750,
        ]);

        $finalAttempt = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\CreateTask::class,
            'config' => [
                'title' => 'Final attempt: {{contact.name}}',
                'type' => 'task',
                'priority' => 'urgent',
                'due_in_hours' => 0,
            ],
            'label' => 'Final Attempt Task',
            'position_x' => 600,
            'position_y' => 850,
        ]);

        // Edges
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $trigger->id,
            'to_node_id' => $createCall->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $createCall->id,
            'to_node_id' => $wait24h->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $wait24h->id,
            'to_node_id' => $ifElse->id,
            'sort_order' => 0,
        ]);

        // IF/ELSE: "yes" branch - deal has advanced past lead stage → follow-up sequence
        // (If deal advanced, no further follow-up needed - this branch goes to end)
        // We use the "else" branch for continuing follow-ups when deal hasn't advanced

        // IF/ELSE: "yes" branch - deal has NOT advanced, continue follow-up
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $ifElse->id,
            'to_node_id' => $followUpCall->id,
            'branch' => 'yes',
            'condition_config' => [
                'logic' => 'and',
                'conditions' => [
                    [
                        'class' => \App\Automation\Conditions\DealStageIs::class,
                        'config' => ['stages' => ['lead', 'not_contacted', 'follow_up']],
                    ],
                ],
            ],
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $followUpCall->id,
            'to_node_id' => $wait48h->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $wait48h->id,
            'to_node_id' => $emailTask->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $emailTask->id,
            'to_node_id' => $wait7d->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $wait7d->id,
            'to_node_id' => $finalAttempt->id,
            'sort_order' => 0,
        ]);
    }

    /**
     * Workflow 2: Estimate Sent Follow-Up
     *
     * [Trigger: Estimate Sent]
     *   → [Wait: 48 hours]
     *   → [IF/ELSE: Estimate status still 'sent'?]
     *       Yes → [Action: Create task to discuss estimate]
     *       No  → [End - already responded]
     *   → [Goal: Estimate accepted → complete workflow]
     */
    protected function createEstimateSentFollowUp(): void
    {
        $workflow = Workflow::create([
            'name' => 'Estimate Sent Follow-Up',
            'description' => 'Follow up with customers after sending an estimate. Creates reminder tasks if the customer hasn\'t responded.',
            'is_active' => true,
            'is_system' => true,
            'version' => 1,
            'published_at' => now(),
        ]);

        $trigger = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'trigger',
            'node_class' => \App\Automation\Triggers\EstimateSent::class,
            'config' => [],
            'label' => 'Estimate Sent',
            'position_x' => 400,
            'position_y' => 50,
        ]);

        $wait48h = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'wait',
            'node_class' => \App\Automation\Waits\TimeDelay::class,
            'config' => ['hours' => 48],
            'label' => 'Wait 48 Hours',
            'position_x' => 400,
            'position_y' => 150,
        ]);

        $ifElse = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'if_else',
            'node_class' => 'if_else',
            'config' => [],
            'label' => 'Still Awaiting Response?',
            'position_x' => 400,
            'position_y' => 250,
        ]);

        $createDiscussTask = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\CreateTask::class,
            'config' => [
                'title' => 'Discuss estimate with {{contact.name}}',
                'type' => 'call',
                'priority' => 'high',
                'due_in_hours' => 0,
            ],
            'label' => 'Create Discussion Task',
            'position_x' => 250,
            'position_y' => 400,
        ]);

        $sendReminder = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\SendNotification::class,
            'config' => [
                'message' => '{{contact.name}} hasn\'t responded to estimate after 48 hours',
                'channels' => ['database'],
            ],
            'label' => 'Notify Team',
            'position_x' => 250,
            'position_y' => 500,
        ]);

        $wait5d = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'wait',
            'node_class' => \App\Automation\Waits\TimeDelay::class,
            'config' => ['hours' => 120],
            'label' => 'Wait 5 Days',
            'position_x' => 250,
            'position_y' => 600,
        ]);

        $finalFollowUp = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\CreateTask::class,
            'config' => [
                'title' => 'Final follow-up on estimate for {{contact.name}}',
                'type' => 'call',
                'priority' => 'urgent',
                'due_in_hours' => 0,
            ],
            'label' => 'Final Follow-Up Task',
            'position_x' => 250,
            'position_y' => 700,
        ]);

        // Goal: Estimate accepted at any point completes the workflow
        $goal = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'goal',
            'node_class' => 'goal',
            'config' => [
                'conditions' => [
                    [
                        'class' => \App\Automation\Conditions\FieldValue::class,
                        'config' => [
                            'field' => 'status',
                            'operator' => '=',
                            'value' => 'accepted',
                        ],
                    ],
                ],
            ],
            'label' => 'Estimate Accepted',
            'position_x' => 600,
            'position_y' => 400,
        ]);

        // Edges
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $trigger->id,
            'to_node_id' => $wait48h->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $wait48h->id,
            'to_node_id' => $ifElse->id,
            'sort_order' => 0,
        ]);

        // IF/ELSE: "yes" - estimate still in 'sent' status (no response)
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $ifElse->id,
            'to_node_id' => $createDiscussTask->id,
            'branch' => 'yes',
            'condition_config' => [
                'logic' => 'and',
                'conditions' => [
                    [
                        'class' => \App\Automation\Conditions\FieldValue::class,
                        'config' => [
                            'field' => 'status',
                            'operator' => '=',
                            'value' => 'sent',
                        ],
                    ],
                ],
            ],
            'sort_order' => 0,
        ]);

        // IF/ELSE: "else" branch - estimate already responded to, workflow ends naturally
        // (No edge needed - engine completes the run when no outgoing edges match)

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $createDiscussTask->id,
            'to_node_id' => $sendReminder->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $sendReminder->id,
            'to_node_id' => $wait5d->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $wait5d->id,
            'to_node_id' => $finalFollowUp->id,
            'sort_order' => 0,
        ]);
    }

    /**
     * Workflow 3: Stale Deal Alert
     *
     * [Trigger: Scheduled Daily at 9am]
     *   → [IF/ELSE: Deal with no activity in 7+ days?]
     *       Yes → [Action: Create re-engage task]
     *       No  → [End]
     */
    protected function createStaleDealAlert(): void
    {
        $workflow = Workflow::create([
            'name' => 'Stale Deal Alert',
            'description' => 'Daily check for deals with no activity in the last 7 days. Creates re-engagement tasks for stale deals.',
            'is_active' => true,
            'is_system' => true,
            'version' => 1,
            'published_at' => now(),
        ]);

        $trigger = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'trigger',
            'node_class' => \App\Automation\Triggers\ScheduledCron::class,
            'config' => ['schedule' => 'daily_9am'],
            'label' => 'Daily at 9 AM',
            'position_x' => 400,
            'position_y' => 50,
        ]);

        $ifElse = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'if_else',
            'node_class' => 'if_else',
            'config' => [],
            'label' => 'Stale Deal?',
            'position_x' => 400,
            'position_y' => 150,
        ]);

        $createTask = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\CreateTask::class,
            'config' => [
                'title' => 'Re-engage stale deal: {{deal.title}}',
                'type' => 'call',
                'priority' => 'high',
                'due_in_hours' => 0,
            ],
            'label' => 'Create Re-Engage Task',
            'position_x' => 250,
            'position_y' => 300,
        ]);

        $notify = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\SendNotification::class,
            'config' => [
                'message' => 'Deal "{{deal.title}}" has had no activity in 7+ days.',
                'channels' => ['database'],
            ],
            'label' => 'Notify Assigned User',
            'position_x' => 250,
            'position_y' => 400,
        ]);

        // Edges
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $trigger->id,
            'to_node_id' => $ifElse->id,
            'sort_order' => 0,
        ]);

        // IF/ELSE: "yes" - no activity in 7 days
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $ifElse->id,
            'to_node_id' => $createTask->id,
            'branch' => 'yes',
            'condition_config' => [
                'logic' => 'and',
                'conditions' => [
                    [
                        'class' => \App\Automation\Conditions\HasNoActivityIn::class,
                        'config' => ['days' => 7],
                    ],
                ],
            ],
            'sort_order' => 0,
        ]);

        // IF/ELSE: "else" branch - recent activity exists, workflow ends naturally
        // (No edge needed - engine completes the run when no outgoing edges match)

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $createTask->id,
            'to_node_id' => $notify->id,
            'sort_order' => 0,
        ]);
    }

    /**
     * Workflow 4: Job Completion Follow-Up
     *
     * [Trigger: Job Completed]
     *   → [Action: Create thank-you task]
     *   → [Wait: 3 days]
     *   → [Action: Create review request task]
     *   → [Wait: 30 days]
     *   → [Action: Create referral opportunity task]
     */
    protected function createJobCompletionFollowUp(): void
    {
        $workflow = Workflow::create([
            'name' => 'Job Completion Follow-Up',
            'description' => 'Post-job follow-up sequence: thank-you, review request, and referral outreach at timed intervals.',
            'is_active' => true,
            'is_system' => true,
            'version' => 1,
            'published_at' => now(),
        ]);

        $trigger = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'trigger',
            'node_class' => \App\Automation\Triggers\JobCompleted::class,
            'config' => [],
            'label' => 'Job Completed',
            'position_x' => 400,
            'position_y' => 50,
        ]);

        $thankYou = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\CreateTask::class,
            'config' => [
                'title' => 'Send thank you to {{contact.name}}',
                'type' => 'task',
                'priority' => 'normal',
                'due_in_hours' => 0,
            ],
            'label' => 'Thank You Task',
            'position_x' => 400,
            'position_y' => 150,
        ]);

        $wait3d = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'wait',
            'node_class' => \App\Automation\Waits\TimeDelay::class,
            'config' => ['hours' => 72],
            'label' => 'Wait 3 Days',
            'position_x' => 400,
            'position_y' => 250,
        ]);

        $reviewRequest = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\CreateTask::class,
            'config' => [
                'title' => 'Request review from {{contact.name}}',
                'type' => 'task',
                'priority' => 'normal',
                'due_in_hours' => 0,
            ],
            'label' => 'Request Review Task',
            'position_x' => 400,
            'position_y' => 350,
        ]);

        $wait30d = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'wait',
            'node_class' => \App\Automation\Waits\TimeDelay::class,
            'config' => ['hours' => 720],
            'label' => 'Wait 30 Days',
            'position_x' => 400,
            'position_y' => 450,
        ]);

        $referral = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\CreateTask::class,
            'config' => [
                'title' => 'Check in: referral opportunity with {{contact.name}}',
                'type' => 'call',
                'priority' => 'normal',
                'due_in_hours' => 0,
            ],
            'label' => 'Referral Outreach Task',
            'position_x' => 400,
            'position_y' => 550,
        ]);

        // Edges - linear flow
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $trigger->id,
            'to_node_id' => $thankYou->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $thankYou->id,
            'to_node_id' => $wait3d->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $wait3d->id,
            'to_node_id' => $reviewRequest->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $reviewRequest->id,
            'to_node_id' => $wait30d->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $wait30d->id,
            'to_node_id' => $referral->id,
            'sort_order' => 0,
        ]);
    }

    /**
     * Workflow 5: Door Knock → Customer Pipeline
     *
     * [Trigger: DoorKnockRecorded]
     *   → [Action: CreateCustomer]        from {{doorknock.*}} values
     *   → [Action: CreateProperty]        from {{doorknock.*}} address fields
     *   → [Action: CreateDeal]            title="Door Knock - {{doorknock.address}}"
     *   → [Action: CreateEstimate]        blank draft estimate
     *   → [IF/ELSE: Has Email?]
     *       Yes → [Action: InviteToPortal]
     *       No  → [End]
     */
    protected function createDoorKnockCustomerPipeline(): void
    {
        $workflow = Workflow::create([
            'name' => 'Door Knock → Customer Pipeline',
            'description' => 'Automatically converts door knocks with contact info into full CRM records: customer, property, deal, and estimate. Sends portal invitation if email is available.',
            'is_active' => true,
            'is_system' => true,
            'version' => 1,
            'published_at' => now(),
        ]);

        // Trigger
        $trigger = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'trigger',
            'node_class' => \App\Automation\Triggers\DoorKnockRecorded::class,
            'config' => [],
            'label' => 'Door Knock Recorded',
            'position_x' => 400,
            'position_y' => 50,
        ]);

        // Action: Create Customer
        $createCustomer = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\CreateCustomer::class,
            'config' => [
                'first_name' => '{{doorknock.first_name}}',
                'last_name' => '{{doorknock.last_name}}',
                'email' => '{{doorknock.email}}',
                'phone' => '{{doorknock.phone}}',
                'address' => '{{doorknock.address}}',
                'city' => '{{doorknock.city}}',
                'state' => '{{doorknock.state}}',
                'zip' => '{{doorknock.zip}}',
                'source' => 'door_knock',
                'deduplicate_by_email' => true,
            ],
            'label' => 'Create Customer',
            'position_x' => 400,
            'position_y' => 150,
        ]);

        // Action: Create Property
        $createProperty = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\CreateProperty::class,
            'config' => [
                'address' => '{{doorknock.address}}',
                'city' => '{{doorknock.city}}',
                'state' => '{{doorknock.state}}',
                'zip' => '{{doorknock.zip}}',
            ],
            'label' => 'Create Property',
            'position_x' => 400,
            'position_y' => 250,
        ]);

        // Action: Create Deal
        $createDeal = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\CreateDeal::class,
            'config' => [
                'title' => 'Door Knock - {{doorknock.address}}',
                'stage' => 'lead',
                'lead_source' => 'door_knock',
            ],
            'label' => 'Create Deal',
            'position_x' => 400,
            'position_y' => 350,
        ]);

        // Action: Create Estimate
        $createEstimate = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\CreateEstimate::class,
            'config' => [
                'title' => 'Estimate - {{doorknock.address}}',
                'project_type' => 'interior',
                'estimator_mode' => 'template',
            ],
            'label' => 'Create Estimate',
            'position_x' => 400,
            'position_y' => 450,
        ]);

        // IF/ELSE: Has Email?
        $ifElse = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'if_else',
            'node_class' => 'if_else',
            'config' => [],
            'label' => 'Has Email?',
            'position_x' => 400,
            'position_y' => 550,
        ]);

        // Action: Invite to Portal (yes branch)
        $inviteToPortal = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'action',
            'node_class' => \App\Automation\Actions\InviteToPortal::class,
            'config' => [
                'email' => '{{doorknock.email}}',
            ],
            'label' => 'Invite to Portal',
            'position_x' => 250,
            'position_y' => 700,
        ]);

        // Edges - linear flow through create steps
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $trigger->id,
            'to_node_id' => $createCustomer->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $createCustomer->id,
            'to_node_id' => $createProperty->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $createProperty->id,
            'to_node_id' => $createDeal->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $createDeal->id,
            'to_node_id' => $createEstimate->id,
            'sort_order' => 0,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $createEstimate->id,
            'to_node_id' => $ifElse->id,
            'sort_order' => 0,
        ]);

        // IF/ELSE: "yes" branch - has email → invite to portal
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $ifElse->id,
            'to_node_id' => $inviteToPortal->id,
            'branch' => 'yes',
            'condition_config' => [
                'logic' => 'and',
                'conditions' => [
                    [
                        'class' => \App\Automation\Conditions\FieldValue::class,
                        'config' => [
                            'field' => 'email',
                            'operator' => 'is_not_empty',
                        ],
                    ],
                ],
            ],
            'sort_order' => 0,
        ]);

        // "else" branch (no email) - workflow ends naturally
    }

    // =========================================================================
    // Phase 4a Engine-Based Workflows (Trigger/Action model)
    // =========================================================================

    protected function seedEngineWorkflows(): void
    {
        $this->seedDoorKnockFlatRate();
        $this->seedSalesCommissionOnSignedEstimate();
        $this->seedPaintingCrewDeposit();
        $this->seedPaintingCrewFinal();
    }

    /**
     * Engine Workflow 1: Door Knock Flat Rate
     *
     * When a Deal stage changes to "quoted" and the deal came from a door knock
     * with a lead generator, pay the lead generator a $50 flat rate.
     */
    protected function seedDoorKnockFlatRate(): void
    {
        $workflow = Workflow::create([
            'name' => 'Door Knock Flat Rate',
            'description' => 'Pay lead generator $50 flat rate when a door knock deal reaches the quoted stage.',
            'is_active' => true,
            'is_system' => true,
            'version' => 1,
            'priority' => 10,
            'user_scope_type' => Workflow::USER_SCOPE_ANY,
            'published_at' => now(),
        ]);

        WorkflowTrigger::create([
            'workflow_id' => $workflow->id,
            'trigger_model' => 'deal',
            'trigger_event' => 'status_changed',
            'trigger_field' => 'stage',
            'trigger_conditions' => [
                'logic' => 'all',
                'conditions' => [
                    ['field' => 'stage', 'operator' => 'changed_to', 'value' => 'quoted'],
                    ['field' => 'lead_source', 'operator' => 'equals', 'value' => 'door_knock'],
                    ['field' => 'lead_generator_id', 'operator' => 'is_not_null'],
                ],
            ],
            'order' => 1,
        ]);

        WorkflowAction::create([
            'workflow_id' => $workflow->id,
            'action_type' => WorkflowAction::ACTION_CREATE_FLAT_RATE,
            'action_config' => [
                'amount' => 50.00,
                'description' => 'Door knock flat rate',
            ],
            'target_user_field' => 'lead_generator_id',
            'order' => 1,
        ]);
    }

    /**
     * Engine Workflow 2: Sales Commission on Signed Estimate
     *
     * When an Estimate status changes to "accepted", create a commission
     * for the assigned sales person (resolved through the deal).
     */
    protected function seedSalesCommissionOnSignedEstimate(): void
    {
        $workflow = Workflow::create([
            'name' => 'Sales Commission on Signed Estimate',
            'description' => 'Create a 10% sales commission when a customer accepts an estimate.',
            'is_active' => true,
            'is_system' => true,
            'version' => 1,
            'priority' => 20,
            'user_scope_type' => Workflow::USER_SCOPE_ANY,
            'published_at' => now(),
        ]);

        WorkflowTrigger::create([
            'workflow_id' => $workflow->id,
            'trigger_model' => 'estimate',
            'trigger_event' => 'status_changed',
            'trigger_field' => 'status',
            'trigger_conditions' => [
                'logic' => 'all',
                'conditions' => [
                    ['field' => 'status', 'operator' => 'changed_to', 'value' => 'accepted'],
                ],
            ],
            'order' => 1,
        ]);

        WorkflowAction::create([
            'workflow_id' => $workflow->id,
            'action_type' => WorkflowAction::ACTION_CREATE_COMMISSION,
            'action_config' => [
                'type' => 'percent',
                'rate' => 0.10,
                'basis' => 'estimate_total',
                'description' => 'Sales commission',
            ],
            'target_user_field' => 'assigned_to',
            'order' => 1,
        ]);
    }

    /**
     * Engine Workflow 3: Painting Crew Deposit
     *
     * When a Job stage changes to "in_progress" and the job is in the
     * painting service category, create a crew deposit payable.
     */
    protected function seedPaintingCrewDeposit(): void
    {
        $painting = ServiceCategory::firstOrCreate(
            ['slug' => 'painting'],
            ['name' => 'Painting', 'is_active' => true, 'sort_order' => 1]
        );

        $workflow = Workflow::create([
            'name' => 'Painting Crew Deposit',
            'description' => 'Create crew deposit payable when a painting job starts.',
            'is_active' => true,
            'is_system' => true,
            'version' => 1,
            'priority' => 30,
            'user_scope_type' => Workflow::USER_SCOPE_ANY,
            'service_category_id' => $painting->id,
            'published_at' => now(),
        ]);

        WorkflowTrigger::create([
            'workflow_id' => $workflow->id,
            'trigger_model' => 'job',
            'trigger_event' => 'status_changed',
            'trigger_field' => 'stage',
            'trigger_conditions' => [
                'logic' => 'all',
                'conditions' => [
                    ['field' => 'stage', 'operator' => 'changed_to', 'value' => 'in_progress'],
                ],
            ],
            'order' => 1,
        ]);

        WorkflowAction::create([
            'workflow_id' => $workflow->id,
            'action_type' => WorkflowAction::ACTION_CREATE_PAYABLE,
            'action_config' => [
                'formula' => 'crew_deposit',
                'description' => 'Crew deposit payment',
            ],
            'target_user_field' => 'crew_lead_id',
            'order' => 1,
        ]);
    }

    /**
     * Engine Workflow 4: Painting Crew Final
     *
     * When a Job stage changes to "completed" and the job is in the
     * painting service category, create a crew final payable.
     */
    protected function seedPaintingCrewFinal(): void
    {
        $painting = ServiceCategory::firstOrCreate(
            ['slug' => 'painting'],
            ['name' => 'Painting', 'is_active' => true, 'sort_order' => 1]
        );

        $workflow = Workflow::create([
            'name' => 'Painting Crew Final',
            'description' => 'Create crew final payable when a painting job is completed.',
            'is_active' => true,
            'is_system' => true,
            'version' => 1,
            'priority' => 40,
            'user_scope_type' => Workflow::USER_SCOPE_ANY,
            'service_category_id' => $painting->id,
            'published_at' => now(),
        ]);

        WorkflowTrigger::create([
            'workflow_id' => $workflow->id,
            'trigger_model' => 'job',
            'trigger_event' => 'status_changed',
            'trigger_field' => 'stage',
            'trigger_conditions' => [
                'logic' => 'all',
                'conditions' => [
                    ['field' => 'stage', 'operator' => 'changed_to', 'value' => 'completed'],
                ],
            ],
            'order' => 1,
        ]);

        WorkflowAction::create([
            'workflow_id' => $workflow->id,
            'action_type' => WorkflowAction::ACTION_CREATE_PAYABLE,
            'action_config' => [
                'formula' => 'crew_final',
                'description' => 'Crew final payment',
            ],
            'target_user_field' => 'crew_lead_id',
            'order' => 1,
        ]);
    }
}
