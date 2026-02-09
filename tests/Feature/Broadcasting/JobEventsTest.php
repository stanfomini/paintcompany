<?php

use App\Events\CrewDispatched;
use App\Events\JobNotification;
use App\Events\JobStageChanged;
use App\Events\PhotoUpdated;
use App\Events\PrepApprovalChanged;
use App\Events\PrepPlanUpdated;
use App\Models\Job;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->job = Job::factory()->create();
});

describe('Events implement ShouldBroadcast', function () {

    it('JobStageChanged implements ShouldBroadcast', function () {
        $event = new JobStageChanged($this->job, 'estimate_accepted', 'scheduling_colors');

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });

    it('CrewDispatched implements ShouldBroadcast', function () {
        $event = new CrewDispatched($this->job, 3);

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });

    it('PrepPlanUpdated implements ShouldBroadcast', function () {
        $event = new PrepPlanUpdated($this->job, 'task_added', 'Added sanding task');

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });

    it('PrepApprovalChanged implements ShouldBroadcast', function () {
        $event = new PrepApprovalChanged($this->job, 'customer', null, true);

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });

    it('PhotoUpdated implements ShouldBroadcast', function () {
        $event = new PhotoUpdated($this->job, 1, 'uploaded', 1);

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });

    it('JobNotification implements ShouldBroadcast', function () {
        $event = new JobNotification($this->job, 'Test notification');

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });
});

describe('Events have correct broadcastOn channels', function () {

    it('JobStageChanged broadcasts on job, customer.job, and crew.job channels', function () {
        $event = new JobStageChanged($this->job, 'estimate_accepted', 'scheduling_colors');
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(3);

        $channelNames = array_map(fn ($ch) => $ch->name, $channels);
        expect($channelNames)->toContain('private-job.'.$this->job->id)
            ->toContain('private-customer.job.'.$this->job->id)
            ->toContain('private-crew.job.'.$this->job->id);
    });

    it('CrewDispatched broadcasts on job and crew.job channels', function () {
        $event = new CrewDispatched($this->job, 2);
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(2);

        $channelNames = array_map(fn ($ch) => $ch->name, $channels);
        expect($channelNames)->toContain('private-job.'.$this->job->id)
            ->toContain('private-crew.job.'.$this->job->id);
    });

    it('PrepPlanUpdated broadcasts on job, crew.job, and customer.job channels', function () {
        $event = new PrepPlanUpdated($this->job, 'task_added');
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(3);

        $channelNames = array_map(fn ($ch) => $ch->name, $channels);
        expect($channelNames)->toContain('private-job.'.$this->job->id)
            ->toContain('private-crew.job.'.$this->job->id)
            ->toContain('private-customer.job.'.$this->job->id);
    });

    it('PrepApprovalChanged broadcasts on job, crew.job, and customer.job channels', function () {
        $event = new PrepApprovalChanged($this->job, 'company');
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(3);

        $channelNames = array_map(fn ($ch) => $ch->name, $channels);
        expect($channelNames)->toContain('private-job.'.$this->job->id)
            ->toContain('private-crew.job.'.$this->job->id)
            ->toContain('private-customer.job.'.$this->job->id);
    });

    it('PhotoUpdated broadcasts on job, crew.job, and customer.job channels', function () {
        $event = new PhotoUpdated($this->job);
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(3);

        $channelNames = array_map(fn ($ch) => $ch->name, $channels);
        expect($channelNames)->toContain('private-job.'.$this->job->id)
            ->toContain('private-crew.job.'.$this->job->id)
            ->toContain('private-customer.job.'.$this->job->id);
    });

    it('JobNotification defaults to job channel when no custom channels provided', function () {
        $event = new JobNotification($this->job, 'Hello');
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(1);
        expect($channels[0]->name)->toBe('private-job.'.$this->job->id);
    });

    it('JobNotification uses custom channels when provided', function () {
        $customChannels = ['crew.job.'.$this->job->id, 'customer.job.'.$this->job->id];
        $event = new JobNotification($this->job, 'Hello', 'info', $customChannels);
        $channels = $event->broadcastOn();

        expect($channels)->toHaveCount(2);

        $channelNames = array_map(fn ($ch) => $ch->name, $channels);
        expect($channelNames)->toContain('private-crew.job.'.$this->job->id)
            ->toContain('private-customer.job.'.$this->job->id);
    });

    it('all channels are PrivateChannel instances', function () {
        $events = [
            new JobStageChanged($this->job, 'a', 'b'),
            new CrewDispatched($this->job, 1),
            new PrepPlanUpdated($this->job, 'test'),
            new PrepApprovalChanged($this->job, 'company'),
            new PhotoUpdated($this->job),
            new JobNotification($this->job, 'msg'),
        ];

        foreach ($events as $event) {
            foreach ($event->broadcastOn() as $channel) {
                expect($channel)->toBeInstanceOf(PrivateChannel::class);
            }
        }
    });
});

describe('Events have correct broadcastAs names', function () {

    it('JobStageChanged broadcasts as stage.changed', function () {
        $event = new JobStageChanged($this->job, 'a', 'b');

        expect($event->broadcastAs())->toBe('stage.changed');
    });

    it('CrewDispatched broadcasts as crew.dispatched', function () {
        $event = new CrewDispatched($this->job, 1);

        expect($event->broadcastAs())->toBe('crew.dispatched');
    });

    it('PrepPlanUpdated broadcasts as prep.plan.updated', function () {
        $event = new PrepPlanUpdated($this->job, 'test');

        expect($event->broadcastAs())->toBe('prep.plan.updated');
    });

    it('PrepApprovalChanged broadcasts as prep.approval.changed', function () {
        $event = new PrepApprovalChanged($this->job, 'company');

        expect($event->broadcastAs())->toBe('prep.approval.changed');
    });

    it('PhotoUpdated broadcasts as photo.updated', function () {
        $event = new PhotoUpdated($this->job);

        expect($event->broadcastAs())->toBe('photo.updated');
    });

    it('JobNotification broadcasts as job.notification', function () {
        $event = new JobNotification($this->job, 'msg');

        expect($event->broadcastAs())->toBe('job.notification');
    });
});

describe('Event payloads are under 1KB', function () {

    it('JobStageChanged payload is under 1KB', function () {
        $event = new JobStageChanged($this->job, 'estimate_accepted', 'scheduling_colors');
        $payload = json_encode($event->broadcastWith());

        expect(strlen($payload))->toBeLessThan(1024);
    });

    it('CrewDispatched payload is under 1KB', function () {
        $event = new CrewDispatched($this->job, 5, 1);
        $payload = json_encode($event->broadcastWith());

        expect(strlen($payload))->toBeLessThan(1024);
    });

    it('PrepPlanUpdated payload is under 1KB', function () {
        $event = new PrepPlanUpdated($this->job, 'task_added', 'Added primer coat to all walls');
        $payload = json_encode($event->broadcastWith());

        expect(strlen($payload))->toBeLessThan(1024);
    });

    it('PrepApprovalChanged payload is under 1KB', function () {
        $event = new PrepApprovalChanged($this->job, 'customer', 1, true);
        $payload = json_encode($event->broadcastWith());

        expect(strlen($payload))->toBeLessThan(1024);
    });

    it('PhotoUpdated payload is under 1KB', function () {
        $event = new PhotoUpdated($this->job, 99, 'uploaded', 42);
        $payload = json_encode($event->broadcastWith());

        expect(strlen($payload))->toBeLessThan(1024);
    });

    it('JobNotification payload is under 1KB', function () {
        $event = new JobNotification($this->job, 'Your job has been updated with new details.');
        $payload = json_encode($event->broadcastWith());

        expect(strlen($payload))->toBeLessThan(1024);
    });
});

describe('Events can be dispatched with Event::fake()', function () {

    it('JobStageChanged can be faked and dispatched', function () {
        Event::fake([JobStageChanged::class]);

        JobStageChanged::dispatch($this->job, 'estimate_accepted', 'scheduling_colors');

        Event::assertDispatched(JobStageChanged::class, function ($event) {
            return $event->job->id === $this->job->id
                && $event->fromStage === 'estimate_accepted'
                && $event->toStage === 'scheduling_colors';
        });
    });

    it('CrewDispatched can be faked and dispatched', function () {
        Event::fake([CrewDispatched::class]);

        CrewDispatched::dispatch($this->job, 4, 2);

        Event::assertDispatched(CrewDispatched::class, function ($event) {
            return $event->job->id === $this->job->id
                && $event->crewCount === 4
                && $event->serviceCategoryId === 2;
        });
    });

    it('PrepPlanUpdated can be faked and dispatched', function () {
        Event::fake([PrepPlanUpdated::class]);

        PrepPlanUpdated::dispatch($this->job, 'task_added', 'Added sanding');

        Event::assertDispatched(PrepPlanUpdated::class, function ($event) {
            return $event->job->id === $this->job->id
                && $event->changeType === 'task_added'
                && $event->detail === 'Added sanding';
        });
    });

    it('PrepApprovalChanged can be faked and dispatched', function () {
        Event::fake([PrepApprovalChanged::class]);

        PrepApprovalChanged::dispatch($this->job, 'customer', 3, true);

        Event::assertDispatched(PrepApprovalChanged::class, function ($event) {
            return $event->job->id === $this->job->id
                && $event->party === 'customer'
                && $event->serviceCategoryId === 3
                && $event->approved === true;
        });
    });

    it('PhotoUpdated can be faked and dispatched', function () {
        Event::fake([PhotoUpdated::class]);

        PhotoUpdated::dispatch($this->job, 10, 'deleted', 5);

        Event::assertDispatched(PhotoUpdated::class, function ($event) {
            return $event->job->id === $this->job->id
                && $event->photoId === 10
                && $event->action === 'deleted'
                && $event->actorId === 5;
        });
    });

    it('JobNotification can be faked and dispatched', function () {
        Event::fake([JobNotification::class]);

        JobNotification::dispatch($this->job, 'Job completed', 'success');

        Event::assertDispatched(JobNotification::class, function ($event) {
            return $event->job->id === $this->job->id
                && $event->message === 'Job completed'
                && $event->type === 'success';
        });
    });
});

describe('Event payload contents are correct', function () {

    it('JobStageChanged broadcastWith contains correct keys', function () {
        $event = new JobStageChanged($this->job, 'estimate_accepted', 'in_progress');
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['job_id', 'from_stage', 'to_stage']);
        expect($payload['job_id'])->toBe($this->job->id);
        expect($payload['from_stage'])->toBe('estimate_accepted');
        expect($payload['to_stage'])->toBe('in_progress');
    });

    it('CrewDispatched broadcastWith contains correct keys', function () {
        $event = new CrewDispatched($this->job, 3, 7);
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['job_id', 'crew_count', 'service_category_id']);
        expect($payload['job_id'])->toBe($this->job->id);
        expect($payload['crew_count'])->toBe(3);
        expect($payload['service_category_id'])->toBe(7);
    });

    it('PrepPlanUpdated broadcastWith contains correct keys', function () {
        $event = new PrepPlanUpdated($this->job, 'task_removed', 'Removed caulking');
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['job_id', 'change_type', 'detail']);
        expect($payload['job_id'])->toBe($this->job->id);
        expect($payload['change_type'])->toBe('task_removed');
        expect($payload['detail'])->toBe('Removed caulking');
    });

    it('PrepApprovalChanged broadcastWith contains correct keys', function () {
        $event = new PrepApprovalChanged($this->job, 'company', 5, false);
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['job_id', 'party', 'service_category_id', 'approved']);
        expect($payload['job_id'])->toBe($this->job->id);
        expect($payload['party'])->toBe('company');
        expect($payload['service_category_id'])->toBe(5);
        expect($payload['approved'])->toBeFalse();
    });

    it('PhotoUpdated broadcastWith contains correct keys', function () {
        $event = new PhotoUpdated($this->job, 42, 'annotated', 8);
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['job_id', 'photo_id', 'action', 'actor_id']);
        expect($payload['job_id'])->toBe($this->job->id);
        expect($payload['photo_id'])->toBe(42);
        expect($payload['action'])->toBe('annotated');
        expect($payload['actor_id'])->toBe(8);
    });

    it('JobNotification broadcastWith contains correct keys', function () {
        $event = new JobNotification($this->job, 'Paint delivered', 'info');
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKeys(['job_id', 'message', 'type']);
        expect($payload['job_id'])->toBe($this->job->id);
        expect($payload['message'])->toBe('Paint delivered');
        expect($payload['type'])->toBe('info');
    });
});
