<?php

use App\Automation\Actions\SendNotification;
use App\Automation\Actions\WorkflowNotification;
use App\Models\Deal;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
});

it('sends database notification to specific user', function () {
    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $this->user->id,
    ]);

    $action = new SendNotification;
    $result = $action->execute($run, [
        'notify_type' => 'specific_user',
        'user_id' => $this->user->id,
        'message' => 'Test notification message.',
        'channels' => ['database'],
    ]);

    expect($result['notified'])->toBeTrue()
        ->and($result['recipient_count'])->toBe(1);

    $notification = $this->user->notifications->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['message'])->toBe('Test notification message.')
        ->and($notification->data['workflow_id'])->toBe($run->workflow_id);
});

it('sends database notification to triggering user when no user_id configured', function () {
    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $this->user->id,
    ]);

    $action = new SendNotification;
    $result = $action->execute($run, [
        'message' => 'Workflow completed.',
    ]);

    expect($result['notified'])->toBeTrue();
    expect($this->user->fresh()->notifications)->toHaveCount(1);
});

it('sends notification to assigned user from context', function () {
    $assignedUser = User::factory()->internal()->create();
    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $this->user->id,
        'context' => ['assigned_to' => $assignedUser->id],
    ]);

    $action = new SendNotification;
    $result = $action->execute($run, [
        'notify_type' => 'assigned_user',
        'message' => 'You have been assigned.',
        'channels' => ['database'],
    ]);

    expect($result['notified'])->toBeTrue()
        ->and($result['recipient_count'])->toBe(1);
    expect($assignedUser->fresh()->notifications)->toHaveCount(1);
    expect($this->user->fresh()->notifications)->toHaveCount(0);
});

it('sends notification to all users of a role', function () {
    Notification::fake();

    // Create a non-internal user who should NOT be notified
    $customer = User::factory()->create(['type' => 'customer', 'is_active' => true]);

    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $this->user->id,
    ]);

    // Count how many internal active users exist (includes factory-created users)
    $internalCount = User::where('type', 'internal')->where('is_active', true)->count();

    $action = new SendNotification;
    $result = $action->execute($run, [
        'notify_type' => 'role',
        'notify_role' => 'internal',
        'message' => 'Team announcement.',
        'channels' => ['database'],
    ]);

    expect($result['notified'])->toBeTrue()
        ->and($result['recipient_count'])->toBe($internalCount);

    // Each internal user should receive the notification exactly once
    Notification::assertSentTo($this->user, WorkflowNotification::class);

    // The customer should NOT receive any notifications
    Notification::assertNotSentTo($customer, WorkflowNotification::class);
});

it('resolves dynamic values in message', function () {
    $deal = Deal::factory()->create(['title' => 'Kitchen Remodel']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $this->user->id,
    ]);

    $action = new SendNotification;
    $result = $action->execute($run, [
        'user_id' => $this->user->id,
        'message' => 'Deal: {{deal.title}}',
        'channels' => ['database'],
    ]);

    expect($result['notified'])->toBeTrue();

    $notification = $this->user->fresh()->notifications->first();
    expect($notification->data['message'])->toBe('Deal: Kitchen Remodel');
});

it('includes subject in mail notification', function () {
    Notification::fake();

    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'triggered_by' => $this->user->id,
    ]);

    $action = new SendNotification;
    $action->execute($run, [
        'user_id' => $this->user->id,
        'message' => 'Check your email.',
        'subject' => 'Important Update',
        'channels' => ['mail'],
    ]);

    Notification::assertSentTo($this->user, WorkflowNotification::class, function ($notification) {
        $mail = $notification->toMail($this->user);

        return $mail instanceof MailMessage
            && $mail->subject === 'Important Update';
    });
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

it('returns notified false when assigned user not in context', function () {
    $run = WorkflowRun::factory()->create([
        'triggered_by' => $this->user->id,
        'context' => [],
    ]);

    $action = new SendNotification;
    $result = $action->execute($run, [
        'notify_type' => 'assigned_user',
        'message' => 'Test',
    ]);

    expect($result['notified'])->toBeFalse();
});

it('has updated config schema with new fields', function () {
    $schema = SendNotification::getConfigSchema();

    expect($schema)->toHaveKeys(['user_id', 'message', 'channels', 'subject', 'notify_type', 'notify_role']);
    expect($schema['notify_type']['default'])->toBe('specific_user');
    expect($schema['subject']['default'])->toBe('Workflow Notification');
});
