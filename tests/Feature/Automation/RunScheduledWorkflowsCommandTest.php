<?php

use App\Automation\Triggers\ScheduledCron;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowNode;
use App\Models\WorkflowRun;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
});

it('triggers workflows matching the current schedule', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 16, 9, 5, 0)); // Monday 9:05am

    $workflow = Workflow::create([
        'name' => 'Daily Test',
        'is_active' => true,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
        'published_at' => now()->subDay(),
    ]);

    $trigger = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => ScheduledCron::class,
        'config' => ['schedule' => 'daily_9am'],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => \App\Automation\Actions\CreateTask::class,
        'config' => ['title' => 'Scheduled task', 'type' => 'task'],
        'position_x' => 0,
        'position_y' => 100,
        'is_enabled' => true,
    ]);

    $this->artisan('workflows:run-scheduled')
        ->expectsOutputToContain('Triggered 1 scheduled workflow(s)')
        ->assertSuccessful();

    expect(WorkflowRun::count())->toBe(1);
    expect(WorkflowRun::first()->status)->toBeIn(['completed', 'running']);

    Carbon::setTestNow();
});

it('does not trigger workflows outside their schedule window', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 16, 15, 0, 0)); // 3:00 PM

    $workflow = Workflow::create([
        'name' => 'Morning Only',
        'is_active' => true,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
        'published_at' => now()->subDay(),
    ]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => ScheduledCron::class,
        'config' => ['schedule' => 'daily_9am'],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    $this->artisan('workflows:run-scheduled')
        ->expectsOutputToContain('Triggered 0 scheduled workflow(s)')
        ->assertSuccessful();

    expect(WorkflowRun::count())->toBe(0);

    Carbon::setTestNow();
});

it('does not trigger inactive workflows', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 16, 9, 5, 0));

    $workflow = Workflow::create([
        'name' => 'Inactive',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
        'published_at' => now()->subDay(),
    ]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => ScheduledCron::class,
        'config' => ['schedule' => 'daily_9am'],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    $this->artisan('workflows:run-scheduled')
        ->expectsOutputToContain('Triggered 0 scheduled workflow(s)')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('filters by schedule option', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 16, 9, 5, 0)); // Monday 9:05am

    foreach (['daily_9am', 'daily_5pm'] as $schedule) {
        $workflow = Workflow::create([
            'name' => "Workflow {$schedule}",
            'is_active' => true,
            'is_system' => false,
            'version' => 1,
            'created_by' => $this->user->id,
            'published_at' => now()->subDay(),
        ]);

        WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'trigger',
            'node_class' => ScheduledCron::class,
            'config' => ['schedule' => $schedule],
            'position_x' => 0,
            'position_y' => 0,
            'is_enabled' => true,
        ]);
    }

    $this->artisan('workflows:run-scheduled', ['--schedule' => 'daily_9am'])
        ->expectsOutputToContain('Triggered 1 scheduled workflow(s)')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('triggers weekly workflows on correct day', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 16, 9, 5, 0)); // Monday

    $workflow = Workflow::create([
        'name' => 'Weekly Monday',
        'is_active' => true,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
        'published_at' => now()->subDay(),
    ]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => ScheduledCron::class,
        'config' => ['schedule' => 'weekly_monday'],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    $this->artisan('workflows:run-scheduled')
        ->expectsOutputToContain('Triggered 1 scheduled workflow(s)')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('does not trigger weekly workflows on wrong day', function () {
    Carbon::setTestNow(Carbon::create(2026, 2, 18, 9, 5, 0)); // Wednesday

    $workflow = Workflow::create([
        'name' => 'Weekly Monday',
        'is_active' => true,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
        'published_at' => now()->subDay(),
    ]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => ScheduledCron::class,
        'config' => ['schedule' => 'weekly_monday'],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    $this->artisan('workflows:run-scheduled')
        ->expectsOutputToContain('Triggered 0 scheduled workflow(s)')
        ->assertSuccessful();

    Carbon::setTestNow();
});
