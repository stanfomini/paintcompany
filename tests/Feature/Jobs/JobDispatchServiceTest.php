<?php

use App\Events\JobDispatchedToCrew;
use App\Models\Job;
use App\Models\User;
use App\Notifications\CrewJobDispatched;
use App\Services\JobDispatchService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

describe('JobDispatchService', function () {
    it('gets eligible crews filtered by crew size', function () {
        $service = new JobDispatchService;

        $smallCrew = User::factory()->crew()->create();
        $smallCrew->crewProfile->update(['crew_size' => 1]);

        $largeCrew = User::factory()->crew()->create();
        $largeCrew->crewProfile->update(['crew_size' => 4]);

        $job = Job::factory()->create(['required_crew_size' => 3]);

        $eligible = $service->getEligibleCrews($job);

        expect($eligible->pluck('id')->toArray())->toContain($largeCrew->id);
        expect($eligible->pluck('id')->toArray())->not->toContain($smallCrew->id);
    });

    it('dispatches job to crews', function () {
        $service = new JobDispatchService;
        $crew1 = User::factory()->crew()->create();
        $crew2 = User::factory()->crew()->create();
        $job = Job::factory()->create();

        $dispatched = $service->dispatchToCrews($job, [$crew1->id, $crew2->id]);

        expect($dispatched)->toBe(2);
        expect($job->crews()->count())->toBe(2);
        expect($job->fresh()->dispatched_at)->not->toBeNull();
    });

    it('does not duplicate dispatch to same crew', function () {
        $service = new JobDispatchService;
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();

        $service->dispatchToCrews($job, [$crew->id]);
        $service->dispatchToCrews($job, [$crew->id]);

        expect($job->crews()->count())->toBe(1);
    });

    it('can accept a dispatched job', function () {
        $service = new JobDispatchService;
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();

        $service->dispatchToCrews($job, [$crew->id]);
        $result = $service->acceptJob($job, $crew, 'Looking forward to it');

        expect($result)->toBeTrue();

        $pivot = $job->crews()->where('crew_user_id', $crew->id)->first()->pivot;
        expect($pivot->status)->toBe('accepted');
        expect($pivot->notes)->toBe('Looking forward to it');
        expect($pivot->responded_at)->not->toBeNull();
    });

    it('cannot accept a non-dispatched job', function () {
        $service = new JobDispatchService;
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();

        $result = $service->acceptJob($job, $crew);
        expect($result)->toBeFalse();
    });

    it('can reject a dispatched job', function () {
        $service = new JobDispatchService;
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();

        $service->dispatchToCrews($job, [$crew->id]);
        $result = $service->rejectJob($job, $crew, 'Too far away');

        expect($result)->toBeTrue();

        $pivot = $job->crews()->where('crew_user_id', $crew->id)->first()->pivot;
        expect($pivot->status)->toBe('rejected');
        expect($pivot->notes)->toBe('Too far away');
    });

    it('cannot reject an already accepted job', function () {
        $service = new JobDispatchService;
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();

        $service->dispatchToCrews($job, [$crew->id]);
        $service->acceptJob($job, $crew);

        $result = $service->rejectJob($job, $crew);
        expect($result)->toBeFalse();
    });

    it('gets available jobs for crew', function () {
        $service = new JobDispatchService;
        $crew = User::factory()->crew()->create();

        $job1 = Job::factory()->create(['title' => 'Dispatched Job']);
        $job2 = Job::factory()->create(['title' => 'Accepted Job']);
        $job3 = Job::factory()->create(['title' => 'Not My Job']);

        $service->dispatchToCrews($job1, [$crew->id]);
        $service->dispatchToCrews($job2, [$crew->id]);
        $service->acceptJob($job2, $crew);

        $available = $service->getAvailableJobsForCrew($crew);

        expect($available->pluck('title')->toArray())->toContain('Dispatched Job');
        expect($available->pluck('title')->toArray())->not->toContain('Accepted Job');
        expect($available->pluck('title')->toArray())->not->toContain('Not My Job');
    });

    it('broadcasts JobDispatchedToCrew to each crew member', function () {
        Event::fake([JobDispatchedToCrew::class]);

        $service = new JobDispatchService;
        $crew1 = User::factory()->crew()->create();
        $crew2 = User::factory()->crew()->create();
        $job = Job::factory()->create();

        $service->dispatchToCrews($job, [$crew1->id, $crew2->id]);

        Event::assertDispatched(JobDispatchedToCrew::class, function ($event) use ($job, $crew1) {
            return $event->job->id === $job->id && $event->crewUserId === $crew1->id;
        });

        Event::assertDispatched(JobDispatchedToCrew::class, function ($event) use ($job, $crew2) {
            return $event->job->id === $job->id && $event->crewUserId === $crew2->id;
        });
    });

    it('sends CrewJobDispatched email notification to each crew member', function () {
        Notification::fake();

        $service = new JobDispatchService;
        $crew1 = User::factory()->crew()->create();
        $crew2 = User::factory()->crew()->create();
        $job = Job::factory()->create();

        $service->dispatchToCrews($job, [$crew1->id, $crew2->id]);

        Notification::assertSentTo($crew1, CrewJobDispatched::class, function ($notification) use ($job) {
            return $notification->job->id === $job->id;
        });

        Notification::assertSentTo($crew2, CrewJobDispatched::class, function ($notification) use ($job) {
            return $notification->job->id === $job->id;
        });
    });

    it('gets accepted jobs for crew', function () {
        $service = new JobDispatchService;
        $crew = User::factory()->crew()->create();

        $job1 = Job::factory()->create(['title' => 'Still Dispatched']);
        $job2 = Job::factory()->create(['title' => 'Accepted One']);

        $service->dispatchToCrews($job1, [$crew->id]);
        $service->dispatchToCrews($job2, [$crew->id]);
        $service->acceptJob($job2, $crew);

        $accepted = $service->getAcceptedJobsForCrew($crew);

        expect($accepted->pluck('title')->toArray())->toContain('Accepted One');
        expect($accepted->pluck('title')->toArray())->not->toContain('Still Dispatched');
    });
});
