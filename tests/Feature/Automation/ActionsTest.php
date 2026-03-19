<?php

use App\Automation\Actions\AddNote;
use App\Automation\Actions\AdvanceDealStage;
use App\Automation\Actions\AssignToUser;
use App\Automation\Actions\CompleteTask;
use App\Automation\Actions\CreateFollowUp;
use App\Automation\Actions\CreateTask;
use App\Automation\Actions\SendNotification;
use App\Automation\Actions\TriggerWorkflow;
use App\Automation\Actions\UpdateField;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;

// --- CreateTask ---

it('creates a task activity on the run subject', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $user->id,
    ]);

    $action = new CreateTask;
    $result = $action->execute($run, [
        'title' => 'Follow up with customer',
        'type' => 'task',
        'priority' => 'high',
        'due_in_hours' => 48,
        'assigned_to' => $user->id,
    ]);

    expect($result)->toHaveKey('activity_id')
        ->and($result['activity_id'])->toBeInt();

    $activity = Activity::find($result['activity_id']);
    expect($activity->title)->toBe('Follow up with customer')
        ->and($activity->type)->toBe('task')
        ->and($activity->priority)->toBe('high')
        ->and($activity->assigned_to)->toBe($user->id)
        ->and($activity->subject_type)->toBe(Deal::class)
        ->and($activity->subject_id)->toBe($deal->id)
        ->and($activity->due_at)->not->toBeNull()
        ->and($activity->is_completed)->toBeFalse();
});

it('creates a task with default config values', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $user->id,
    ]);

    $action = new CreateTask;
    $result = $action->execute($run, ['title' => 'Quick task']);

    $activity = Activity::find($result['activity_id']);
    expect($activity->type)->toBe('task')
        ->and($activity->priority)->toBe('normal')
        ->and($activity->assigned_to)->toBeNull()
        ->and($activity->due_at)->toBeNull();
});

it('has correct metadata for CreateTask', function () {
    expect(CreateTask::getName())->toBe('Create Task')
        ->and(CreateTask::getCategory())->toBe('Task Management')
        ->and(CreateTask::getDescription())->toBeString()
        ->and(CreateTask::getConfigSchema())->toHaveKeys(['title', 'type', 'priority', 'due_in_hours', 'assigned_to']);
});

// --- CompleteTask ---

it('completes tasks matching automation key on subject', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();

    Activity::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'automation_key' => 'welcome_task',
        'is_completed' => false,
    ]);
    Activity::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'automation_key' => 'welcome_task',
        'is_completed' => false,
    ]);
    Activity::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'automation_key' => 'other_key',
        'is_completed' => false,
    ]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $user->id,
    ]);

    $action = new CompleteTask;
    $result = $action->execute($run, ['automation_key' => 'welcome_task']);

    expect($result['completed_count'])->toBe(2);
});

it('returns zero when no matching tasks found', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $user->id,
    ]);

    $action = new CompleteTask;
    $result = $action->execute($run, ['automation_key' => 'nonexistent']);

    expect($result['completed_count'])->toBe(0);
});

it('has correct metadata for CompleteTask', function () {
    expect(CompleteTask::getName())->toBe('Complete Task')
        ->and(CompleteTask::getCategory())->toBe('Task Management')
        ->and(CompleteTask::getConfigSchema())->toHaveKey('automation_key');
});

// --- CreateFollowUp ---

it('creates a follow-up activity with due date', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $user->id,
    ]);

    $action = new CreateFollowUp;
    $result = $action->execute($run, [
        'title' => 'Check in with client',
        'due_in_days' => 3,
        'type' => 'call',
    ]);

    expect($result)->toHaveKey('activity_id');

    $activity = Activity::find($result['activity_id']);
    expect($activity->title)->toBe('Check in with client')
        ->and($activity->type)->toBe('call')
        ->and($activity->due_at)->not->toBeNull()
        ->and($activity->due_at->isFuture())->toBeTrue();
});

it('has correct metadata for CreateFollowUp', function () {
    expect(CreateFollowUp::getName())->toBe('Create Follow-Up')
        ->and(CreateFollowUp::getCategory())->toBe('Task Management')
        ->and(CreateFollowUp::getConfigSchema())->toHaveKeys(['title', 'due_in_days', 'type']);
});

// --- SendNotification ---

it('sends a database notification to a specific user', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $user->id,
    ]);

    $action = new SendNotification;
    $result = $action->execute($run, [
        'user_id' => $user->id,
        'message' => 'A new task has been assigned to you.',
        'channels' => ['database'],
    ]);

    expect($result['notified'])->toBeTrue();
    expect($user->notifications)->toHaveCount(1);
    expect($user->notifications->first()->data['message'])->toBe('A new task has been assigned to you.');
});

it('sends notification to triggered_by user when user_id is null', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $user->id,
    ]);

    $action = new SendNotification;
    $result = $action->execute($run, [
        'message' => 'Workflow completed.',
    ]);

    expect($result['notified'])->toBeTrue();
    expect($user->fresh()->notifications)->toHaveCount(1);
});

it('returns notified false when user not found', function () {
    $run = WorkflowRun::factory()->create([
        'triggered_by' => null,
    ]);

    $action = new SendNotification;
    $result = $action->execute($run, [
        'user_id' => null,
        'message' => 'Test',
    ]);

    expect($result['notified'])->toBeFalse();
});

it('has correct metadata for SendNotification', function () {
    expect(SendNotification::getName())->toBe('Send Notification')
        ->and(SendNotification::getCategory())->toBe('Communication')
        ->and(SendNotification::getConfigSchema())->toHaveKeys(['user_id', 'message', 'channels']);
});

// --- AdvanceDealStage ---

it('advances a deal to the configured stage', function () {
    $deal = Deal::factory()->create(['stage' => 'lead']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $action = new AdvanceDealStage;
    $result = $action->execute($run, ['stage' => 'quoted']);

    expect($result['from_stage'])->toBe('lead')
        ->and($result['to_stage'])->toBe('quoted');
    expect($deal->fresh()->stage)->toBe('quoted');
});

it('returns null to_stage when subject is not a deal', function () {
    $user = User::factory()->internal()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => User::class,
        'subject_id' => $user->id,
    ]);

    $action = new AdvanceDealStage;
    $result = $action->execute($run, ['stage' => 'quoted']);

    expect($result['from_stage'])->toBeNull()
        ->and($result['to_stage'])->toBeNull();
});

it('returns null to_stage for invalid stage', function () {
    $deal = Deal::factory()->create(['stage' => 'lead']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $action = new AdvanceDealStage;
    $result = $action->execute($run, ['stage' => 'invalid_stage']);

    expect($result['to_stage'])->toBeNull();
    expect($deal->fresh()->stage)->toBe('lead');
});

it('has correct metadata for AdvanceDealStage', function () {
    expect(AdvanceDealStage::getName())->toBe('Advance Deal Stage')
        ->and(AdvanceDealStage::getCategory())->toBe('Deal Management')
        ->and(AdvanceDealStage::getConfigSchema())->toHaveKey('stage');
});

// --- UpdateField ---

it('updates a field on the subject', function () {
    $deal = Deal::factory()->create(['title' => 'Old Title']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $action = new UpdateField;
    $result = $action->execute($run, [
        'field' => 'title',
        'value' => 'New Title',
    ]);

    expect($result['field'])->toBe('title')
        ->and($result['old_value'])->toBe('Old Title')
        ->and($result['new_value'])->toBe('New Title');
    expect($deal->fresh()->title)->toBe('New Title');
});

it('returns null values when no subject', function () {
    $run = WorkflowRun::factory()->create([
        'subject_type' => null,
        'subject_id' => null,
    ]);

    $action = new UpdateField;
    $result = $action->execute($run, [
        'field' => 'title',
        'value' => 'Test',
    ]);

    expect($result['old_value'])->toBeNull()
        ->and($result['new_value'])->toBeNull();
});

it('has correct metadata for UpdateField', function () {
    expect(UpdateField::getName())->toBe('Update Field')
        ->and(UpdateField::getCategory())->toBe('Data Management')
        ->and(UpdateField::getConfigSchema())->toHaveKeys(['field', 'value']);
});

// --- AssignToUser ---

it('assigns the subject to a user', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create(['assigned_to' => null]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $action = new AssignToUser;
    $result = $action->execute($run, ['user_id' => $user->id]);

    expect($result['assigned_to'])->toBe($user->id);
    expect($deal->fresh()->assigned_to)->toBe($user->id);
});

it('returns null when no subject for assignment', function () {
    $run = WorkflowRun::factory()->create([
        'subject_type' => null,
        'subject_id' => null,
    ]);

    $action = new AssignToUser;
    $result = $action->execute($run, ['user_id' => 1]);

    expect($result['assigned_to'])->toBeNull();
});

it('has correct metadata for AssignToUser', function () {
    expect(AssignToUser::getName())->toBe('Assign to User')
        ->and(AssignToUser::getCategory())->toBe('Data Management')
        ->and(AssignToUser::getConfigSchema())->toHaveKey('user_id');
});

// --- AddNote ---

it('creates a note activity on the subject', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $user->id,
    ]);

    $action = new AddNote;
    $result = $action->execute($run, [
        'title' => 'Automated note',
        'description' => 'This was added by a workflow.',
    ]);

    expect($result)->toHaveKey('activity_id');

    $activity = Activity::find($result['activity_id']);
    expect($activity->title)->toBe('Automated note')
        ->and($activity->description)->toBe('This was added by a workflow.')
        ->and($activity->type)->toBe('note')
        ->and($activity->subject_type)->toBe(Deal::class)
        ->and($activity->subject_id)->toBe($deal->id);
});

it('has correct metadata for AddNote', function () {
    expect(AddNote::getName())->toBe('Add Note')
        ->and(AddNote::getCategory())->toBe('Task Management')
        ->and(AddNote::getConfigSchema())->toHaveKeys(['title', 'description']);
});

// --- TriggerWorkflow ---

it('creates a new workflow run for target workflow', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();
    $targetWorkflow = Workflow::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $user->id,
    ]);

    $action = new TriggerWorkflow;
    $result = $action->execute($run, ['workflow_id' => $targetWorkflow->id]);

    expect($result)->toHaveKey('workflow_run_id')
        ->and($result['workflow_run_id'])->toBeInt();

    $newRun = WorkflowRun::find($result['workflow_run_id']);
    expect($newRun->workflow_id)->toBe($targetWorkflow->id)
        ->and($newRun->subject_type)->toBe(Deal::class)
        ->and($newRun->subject_id)->toBe($deal->id)
        ->and($newRun->triggered_by)->toBe($user->id)
        ->and($newRun->status)->toBe('running');
});

it('returns null run id when workflow not found', function () {
    $run = WorkflowRun::factory()->create();

    $action = new TriggerWorkflow;
    $result = $action->execute($run, ['workflow_id' => 99999]);

    expect($result['workflow_run_id'])->toBeNull();
});

it('has correct metadata for TriggerWorkflow', function () {
    expect(TriggerWorkflow::getName())->toBe('Trigger Workflow')
        ->and(TriggerWorkflow::getCategory())->toBe('Workflow Control')
        ->and(TriggerWorkflow::getConfigSchema())->toHaveKey('workflow_id');
});

// --- SendSMS ---

it('SendSMS returns error when no phone provided', function () {
    $run = WorkflowRun::factory()->create();

    $action = new \App\Automation\Actions\SendSMS;
    $result = $action->execute($run, ['to' => '', 'message' => 'Hello']);

    expect($result['sent'])->toBeFalse()
        ->and($result['error'])->toBe('No recipient phone number');
});

it('SendSMS returns error when no message provided', function () {
    $run = WorkflowRun::factory()->create();

    $action = new \App\Automation\Actions\SendSMS;
    $result = $action->execute($run, ['to' => '+13035551234', 'message' => '']);

    expect($result['sent'])->toBeFalse()
        ->and($result['error'])->toBe('No message content');
});

it('SendSMS has correct metadata', function () {
    expect(\App\Automation\Actions\SendSMS::getName())->toBe('Send SMS')
        ->and(\App\Automation\Actions\SendSMS::getCategory())->toBe('Communication')
        ->and(\App\Automation\Actions\SendSMS::getConfigSchema())->toHaveKeys(['to', 'message']);
});

// --- SendMessenger ---

it('SendMessenger returns error when no PSID found', function () {
    $user = User::factory()->internal()->create();
    $run = WorkflowRun::factory()->create([
        'subject_type' => User::class,
        'subject_id' => $user->id,
    ]);

    $action = new \App\Automation\Actions\SendMessenger;
    $result = $action->execute($run, ['recipient_id' => '', 'message' => 'Hello']);

    expect($result['sent'])->toBeFalse()
        ->and($result['error'])->toBe('No Facebook PSID found');
});

it('SendMessenger returns error when no message provided', function () {
    $run = WorkflowRun::factory()->create();

    $action = new \App\Automation\Actions\SendMessenger;
    $result = $action->execute($run, ['recipient_id' => '12345', 'message' => '']);

    expect($result['sent'])->toBeFalse()
        ->and($result['error'])->toBe('No message content');
});

it('SendMessenger has correct metadata', function () {
    expect(\App\Automation\Actions\SendMessenger::getName())->toBe('Send Messenger')
        ->and(\App\Automation\Actions\SendMessenger::getCategory())->toBe('Communication')
        ->and(\App\Automation\Actions\SendMessenger::getConfigSchema())->toHaveKeys(['recipient_id', 'message']);
});
