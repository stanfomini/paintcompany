<?php

use App\Automation\Triggers\ApplicationReceived;
use App\Automation\Triggers\ContactCreated;
use App\Automation\Triggers\DealStageChanged;
use App\Automation\Triggers\EstimateSent;
use App\Automation\Triggers\EstimateSigned;
use App\Automation\Triggers\JobCompleted;
use App\Automation\Triggers\JobCreated;
use App\Automation\Triggers\JobStageChanged;
use App\Automation\Triggers\ScheduledCron;
use App\Automation\Triggers\TaskCompleted;
use App\Automation\Triggers\TaskOverdue;
use App\Models\Applicant;
use App\Models\Deal;
use Illuminate\Support\Facades\Event;

// --- DealStageChanged ---

it('matches deal.stage_changed when to_stage matches config', function () {
    $trigger = new DealStageChanged;
    $subject = Deal::factory()->make();

    expect($trigger->matches('deal.stage_changed', $subject, ['to_stage' => 'quoted'], ['target_stage' => 'quoted']))
        ->toBeTrue();
});

it('does not match deal.stage_changed when to_stage differs', function () {
    $trigger = new DealStageChanged;
    $subject = Deal::factory()->make();

    expect($trigger->matches('deal.stage_changed', $subject, ['to_stage' => 'lead'], ['target_stage' => 'quoted']))
        ->toBeFalse();
});

it('matches deal.stage_changed with null target_stage as wildcard', function () {
    $trigger = new DealStageChanged;
    $subject = Deal::factory()->make();

    expect($trigger->matches('deal.stage_changed', $subject, ['to_stage' => 'anything'], []))
        ->toBeTrue();
});

it('does not match wrong event for deal stage changed', function () {
    $trigger = new DealStageChanged;
    $subject = Deal::factory()->make();

    expect($trigger->matches('estimate.sent', $subject, ['to_stage' => 'quoted'], ['target_stage' => 'quoted']))
        ->toBeFalse();
});

it('has correct metadata for DealStageChanged', function () {
    expect(DealStageChanged::getName())->toBe('Deal Stage Changed')
        ->and(DealStageChanged::getCategory())->toBe('Deals')
        ->and(DealStageChanged::getDescription())->toBeString()
        ->and(DealStageChanged::getConfigSchema())->toHaveKey('target_stage');
});

// --- EstimateSent ---

it('matches estimate.sent event', function () {
    $trigger = new EstimateSent;
    $subject = Deal::factory()->make();

    expect($trigger->matches('estimate.sent', $subject, [], []))->toBeTrue();
});

it('does not match wrong event for estimate sent', function () {
    $trigger = new EstimateSent;
    $subject = Deal::factory()->make();

    expect($trigger->matches('estimate.signed', $subject, [], []))->toBeFalse();
});

it('has correct metadata for EstimateSent', function () {
    expect(EstimateSent::getName())->toBe('Estimate Sent')
        ->and(EstimateSent::getCategory())->toBe('Estimates')
        ->and(EstimateSent::getConfigSchema())->toBeEmpty();
});

// --- EstimateSigned ---

it('matches estimate.signed event', function () {
    $trigger = new EstimateSigned;
    $subject = Deal::factory()->make();

    expect($trigger->matches('estimate.signed', $subject, [], []))->toBeTrue();
});

it('does not match wrong event for estimate signed', function () {
    $trigger = new EstimateSigned;
    $subject = Deal::factory()->make();

    expect($trigger->matches('estimate.sent', $subject, [], []))->toBeFalse();
});

// --- JobCreated ---

it('matches job.created event', function () {
    $trigger = new JobCreated;
    $subject = Deal::factory()->make();

    expect($trigger->matches('job.created', $subject, [], []))->toBeTrue();
});

it('does not match wrong event for job created', function () {
    $trigger = new JobCreated;
    $subject = Deal::factory()->make();

    expect($trigger->matches('job.completed', $subject, [], []))->toBeFalse();
});

// --- JobStageChanged ---

it('matches job.stage_changed when to_stage matches config', function () {
    $trigger = new JobStageChanged;
    $subject = Deal::factory()->make();

    expect($trigger->matches('job.stage_changed', $subject, ['to_stage' => 'in_progress'], ['target_stage' => 'in_progress']))
        ->toBeTrue();
});

it('does not match job.stage_changed when to_stage differs', function () {
    $trigger = new JobStageChanged;
    $subject = Deal::factory()->make();

    expect($trigger->matches('job.stage_changed', $subject, ['to_stage' => 'completed'], ['target_stage' => 'in_progress']))
        ->toBeFalse();
});

it('has correct metadata for JobStageChanged', function () {
    expect(JobStageChanged::getName())->toBe('Job Stage Changed')
        ->and(JobStageChanged::getCategory())->toBe('Jobs')
        ->and(JobStageChanged::getConfigSchema())->toHaveKey('target_stage');
});

// --- JobCompleted ---

it('matches job.completed event', function () {
    $trigger = new JobCompleted;
    $subject = Deal::factory()->make();

    expect($trigger->matches('job.completed', $subject, [], []))->toBeTrue();
});

// --- TaskOverdue ---

it('matches task.overdue event', function () {
    $trigger = new TaskOverdue;
    $subject = Deal::factory()->make();

    expect($trigger->matches('task.overdue', $subject, [], []))->toBeTrue();
});

it('has correct metadata for TaskOverdue', function () {
    expect(TaskOverdue::getName())->toBe('Task Overdue')
        ->and(TaskOverdue::getCategory())->toBe('Tasks');
});

// --- TaskCompleted ---

it('matches task.completed event', function () {
    $trigger = new TaskCompleted;
    $subject = Deal::factory()->make();

    expect($trigger->matches('task.completed', $subject, [], []))->toBeTrue();
});

// --- ScheduledCron ---

it('matches cron.tick when schedule matches config', function () {
    $trigger = new ScheduledCron;
    $subject = Deal::factory()->make();

    expect($trigger->matches('cron.tick', $subject, ['schedule' => 'daily_9am'], ['schedule' => 'daily_9am']))
        ->toBeTrue();
});

it('does not match cron.tick when schedule differs', function () {
    $trigger = new ScheduledCron;
    $subject = Deal::factory()->make();

    expect($trigger->matches('cron.tick', $subject, ['schedule' => 'hourly'], ['schedule' => 'daily_9am']))
        ->toBeFalse();
});

it('does not match cron.tick without config schedule', function () {
    $trigger = new ScheduledCron;
    $subject = Deal::factory()->make();

    expect($trigger->matches('cron.tick', $subject, ['schedule' => 'hourly'], []))
        ->toBeFalse();
});

it('has correct metadata for ScheduledCron', function () {
    expect(ScheduledCron::getName())->toBe('Scheduled (Cron)')
        ->and(ScheduledCron::getCategory())->toBe('Time-Based')
        ->and(ScheduledCron::getConfigSchema())->toHaveKey('schedule');
});

// --- ContactCreated ---

it('matches contact.created for any type when no filter', function () {
    $trigger = new ContactCreated;
    $subject = Deal::factory()->make();

    expect($trigger->matches('contact.created', $subject, ['user_type' => 'customer'], []))
        ->toBeTrue();
});

it('matches contact.created when user_type filter matches', function () {
    $trigger = new ContactCreated;
    $subject = Deal::factory()->make();

    expect($trigger->matches('contact.created', $subject, ['user_type' => 'agent'], ['user_type' => 'agent']))
        ->toBeTrue();
});

it('does not match contact.created when user_type filter differs', function () {
    $trigger = new ContactCreated;
    $subject = Deal::factory()->make();

    expect($trigger->matches('contact.created', $subject, ['user_type' => 'customer'], ['user_type' => 'agent']))
        ->toBeFalse();
});

it('has correct metadata for ContactCreated', function () {
    expect(ContactCreated::getName())->toBe('Contact Created')
        ->and(ContactCreated::getCategory())->toBe('Contacts')
        ->and(ContactCreated::getConfigSchema())->toHaveKey('user_type');
});

// --- ApplicationReceived ---

it('matches application.received for any source when no filter', function () {
    $trigger = new ApplicationReceived;
    $subject = Applicant::factory()->make();

    expect($trigger->matches('application.received', $subject, ['source' => 'web'], []))
        ->toBeTrue();
});

it('matches application.received when source filter matches', function () {
    $trigger = new ApplicationReceived;
    $subject = Applicant::factory()->make();

    expect($trigger->matches('application.received', $subject, ['source' => 'qr_code'], ['source' => 'qr_code']))
        ->toBeTrue();
});

it('does not match application.received when source filter differs', function () {
    $trigger = new ApplicationReceived;
    $subject = Applicant::factory()->make();

    expect($trigger->matches('application.received', $subject, ['source' => 'web'], ['source' => 'referral']))
        ->toBeFalse();
});

it('does not match wrong event for application received', function () {
    $trigger = new ApplicationReceived;
    $subject = Applicant::factory()->make();

    expect($trigger->matches('contact.created', $subject, ['source' => 'web'], []))
        ->toBeFalse();
});

it('has correct metadata for ApplicationReceived', function () {
    expect(ApplicationReceived::getName())->toBe('Application Received')
        ->and(ApplicationReceived::getCategory())->toBe('Hiring')
        ->and(ApplicationReceived::getDescription())->toBeString()
        ->and(ApplicationReceived::getConfigSchema())->toHaveKey('source');
});

it('dispatches ApplicationReceived event when applicant is created', function () {
    Event::fake([\App\Events\Automation\ApplicationReceived::class]);

    $applicant = Applicant::factory()->create();

    Event::assertDispatched(\App\Events\Automation\ApplicationReceived::class, function ($event) use ($applicant) {
        return $event->applicant->is($applicant)
            && $event->getEventName() === 'application.received'
            && $event->getSubject()->is($applicant)
            && $event->getContext()['source'] === 'web';
    });
});

// --- DealCreated ---

it('matches deal.created event', function () {
    $trigger = new \App\Automation\Triggers\DealCreated;
    $subject = Deal::factory()->make();

    expect($trigger->matches('deal.created', $subject, ['stage' => 'lead'], []))
        ->toBeTrue();
});

it('matches deal.created with specific stage filter', function () {
    $trigger = new \App\Automation\Triggers\DealCreated;
    $subject = Deal::factory()->make();

    expect($trigger->matches('deal.created', $subject, ['stage' => 'lead'], ['initial_stage' => 'lead']))
        ->toBeTrue();
});

it('does not match deal.created when stage filter does not match', function () {
    $trigger = new \App\Automation\Triggers\DealCreated;
    $subject = Deal::factory()->make();

    expect($trigger->matches('deal.created', $subject, ['stage' => 'lead'], ['initial_stage' => 'quoted']))
        ->toBeFalse();
});

it('does not match wrong event for deal created', function () {
    $trigger = new \App\Automation\Triggers\DealCreated;
    $subject = Deal::factory()->make();

    expect($trigger->matches('deal.stage_changed', $subject, ['stage' => 'lead'], []))
        ->toBeFalse();
});

it('has correct metadata for DealCreated trigger', function () {
    expect(\App\Automation\Triggers\DealCreated::getName())->toBe('Deal Created')
        ->and(\App\Automation\Triggers\DealCreated::getCategory())->toBe('Deals')
        ->and(\App\Automation\Triggers\DealCreated::getAvailableDynamicPrefixes())->toContain('deal', 'preview')
        ->and(\App\Automation\Triggers\DealCreated::getConfigSchema())->toHaveKey('initial_stage');
});

// --- InboundSmsReceived ---

it('matches sms.received event', function () {
    $trigger = new \App\Automation\Triggers\InboundSmsReceived;
    $subject = \App\Models\Customer::factory()->make();

    expect($trigger->matches('sms.received', $subject, ['body' => 'Hello'], []))
        ->toBeTrue();
});

it('matches sms.received with keyword filter', function () {
    $trigger = new \App\Automation\Triggers\InboundSmsReceived;
    $subject = \App\Models\Customer::factory()->make();

    expect($trigger->matches('sms.received', $subject, ['body' => 'I need a QUOTE please'], ['keyword_filter' => 'quote']))
        ->toBeTrue();
});

it('does not match sms.received when keyword not present', function () {
    $trigger = new \App\Automation\Triggers\InboundSmsReceived;
    $subject = \App\Models\Customer::factory()->make();

    expect($trigger->matches('sms.received', $subject, ['body' => 'Hello world'], ['keyword_filter' => 'quote']))
        ->toBeFalse();
});

it('does not match wrong event for inbound sms', function () {
    $trigger = new \App\Automation\Triggers\InboundSmsReceived;
    $subject = \App\Models\Customer::factory()->make();

    expect($trigger->matches('messenger.received', $subject, ['body' => 'Hello'], []))
        ->toBeFalse();
});

// --- InboundMessengerReceived ---

it('matches messenger.received event', function () {
    $trigger = new \App\Automation\Triggers\InboundMessengerReceived;
    $subject = \App\Models\Customer::factory()->make();

    expect($trigger->matches('messenger.received', $subject, ['message_text' => 'Hi'], []))
        ->toBeTrue();
});

it('matches messenger.received with keyword filter', function () {
    $trigger = new \App\Automation\Triggers\InboundMessengerReceived;
    $subject = \App\Models\Customer::factory()->make();

    expect($trigger->matches('messenger.received', $subject, ['message_text' => 'I want a QUOTE'], ['keyword_filter' => 'quote']))
        ->toBeTrue();
});

it('does not match messenger.received when keyword not present', function () {
    $trigger = new \App\Automation\Triggers\InboundMessengerReceived;
    $subject = \App\Models\Customer::factory()->make();

    expect($trigger->matches('messenger.received', $subject, ['message_text' => 'Hello'], ['keyword_filter' => 'estimate']))
        ->toBeFalse();
});
