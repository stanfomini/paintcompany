<?php

use App\Models\ChatThread;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\JobServiceRequirement;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\JobDispatchService;
use Illuminate\Support\Facades\DB;

function removeCrewCategory(): ServiceCategory
{
    return ServiceCategory::firstOrCreate(
        ['slug' => 'remove-crew-painting'],
        ['name' => 'Painting', 'description' => 'Painting services', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );
}

function createCrewForRemoveTest(ServiceCategory $category, int $crewSize = 3): User
{
    $user = User::factory()->crew()->create();
    $user->crewProfile->update([
        'service_category_id' => $category->id,
        'crew_size' => $crewSize,
    ]);
    $user->load('crewProfile');

    return $user;
}

describe('removeCrew', function () {
    it('removes a dispatched crew by deleting the pivot row', function () {
        $category = removeCrewCategory();
        $crew = createCrewForRemoveTest($category);
        $job = Job::factory()->create();

        $service = app(JobDispatchService::class);
        $service->dispatchToCrews($job, [$crew->id], $category);

        expect($job->crews()->count())->toBe(1);

        $result = $service->removeCrew($job, $crew, $category);

        expect($result)->toBeTrue();
        expect($job->crews()->count())->toBe(0);
    });

    it('removes an accepted crew and reverts service requirement to pending', function () {
        $category = removeCrewCategory();
        $crew = createCrewForRemoveTest($category);
        $job = Job::factory()->create();

        JobServiceRequirement::factory()->create([
            'job_id' => $job->id,
            'service_category_id' => $category->id,
            'required_crew_size' => 2,
            'status' => JobServiceRequirement::STATUS_PENDING,
        ]);

        $service = app(JobDispatchService::class);
        $service->dispatchToCrews($job, [$crew->id], $category);
        $service->acceptJob($job, $crew);

        // Verify accepted
        $pivot = $job->crews()->where('crew_user_id', $crew->id)->first()->pivot;
        expect($pivot->status)->toBe('accepted');

        $result = $service->removeCrew($job, $crew, $category);

        expect($result)->toBeTrue();
        expect($job->crews()->count())->toBe(0);

        // Service requirement should revert to pending since no other dispatched crews
        $requirement = $job->serviceRequirements()
            ->where('service_category_id', $category->id)
            ->first();
        expect($requirement->status)->toBe(JobServiceRequirement::STATUS_PENDING);
    });

    it('reverts service requirement to dispatched when other dispatched crews remain', function () {
        $category = removeCrewCategory();
        $acceptedCrew = createCrewForRemoveTest($category);
        $dispatchedCrew = createCrewForRemoveTest($category, 4);
        $job = Job::factory()->create();

        JobServiceRequirement::factory()->create([
            'job_id' => $job->id,
            'service_category_id' => $category->id,
            'required_crew_size' => 2,
            'status' => JobServiceRequirement::STATUS_PENDING,
        ]);

        $service = app(JobDispatchService::class);
        // Dispatch both, then manually accept only one (without auto-reject for testing)
        $service->dispatchToCrews($job, [$acceptedCrew->id, $dispatchedCrew->id], $category);

        // Manually set the first crew to accepted without auto-rejecting the second
        DB::table('job_crew')
            ->where('job_id', $job->id)
            ->where('crew_user_id', $acceptedCrew->id)
            ->update(['status' => 'accepted']);

        $result = $service->removeCrew($job, $acceptedCrew, $category);

        expect($result)->toBeTrue();

        $requirement = $job->serviceRequirements()
            ->where('service_category_id', $category->id)
            ->first();
        expect($requirement->status)->toBe(JobServiceRequirement::STATUS_DISPATCHED);
    });

    it('returns false for crew not on job', function () {
        $category = removeCrewCategory();
        $crew = createCrewForRemoveTest($category);
        $job = Job::factory()->create();

        $service = app(JobDispatchService::class);
        $result = $service->removeCrew($job, $crew, $category);

        expect($result)->toBeFalse();
    });

    it('removes crew from deal chat thread', function () {
        $category = removeCrewCategory();
        $crew = createCrewForRemoveTest($category);
        $job = Job::factory()->create();

        // Create a deal chat thread and add the crew as a participant
        $deal = $job->deal;
        $thread = ChatThread::factory()->forDeal($deal)->create();
        $thread->addParticipant($crew);

        expect($thread->hasParticipant($crew))->toBeTrue();

        $service = app(JobDispatchService::class);
        $service->dispatchToCrews($job, [$crew->id], $category);
        $service->removeCrew($job, $crew, $category);

        // Crew should be removed from the chat thread
        expect($thread->fresh()->hasParticipant($crew))->toBeFalse();
    });

    it('does not remove crew from chat if crew has other job links to same deal', function () {
        $category = removeCrewCategory();
        $crew = createCrewForRemoveTest($category);
        $deal = \App\Models\Deal::factory()->create();
        $job1 = Job::factory()->create(['deal_id' => $deal->id]);
        $job2 = Job::factory()->create(['deal_id' => $deal->id]);

        // Create deal chat thread and add crew
        $thread = ChatThread::factory()->forDeal($deal)->create();
        $thread->addParticipant($crew);

        $service = app(JobDispatchService::class);
        // Dispatch crew to both jobs
        $service->dispatchToCrews($job1, [$crew->id], $category);
        $service->dispatchToCrews($job2, [$crew->id], $category);

        // Remove from job1 only
        $service->removeCrew($job1, $crew, $category);

        // Crew should still be a participant because they're on job2
        expect($thread->fresh()->hasParticipant($crew))->toBeTrue();

        // Remove from job2 too
        $service->removeCrew($job2, $crew, $category);

        // Now crew should be removed from chat
        expect($thread->fresh()->hasParticipant($crew))->toBeFalse();
    });

    it('logs crew_removed activity', function () {
        $category = removeCrewCategory();
        $crew = createCrewForRemoveTest($category);
        $job = Job::factory()->create();

        $service = app(JobDispatchService::class);
        $service->dispatchToCrews($job, [$crew->id], $category);
        $service->removeCrew($job, $crew, $category);

        $activity = JobActivity::where('job_id', $job->id)
            ->where('activity_type', 'crew_removed')
            ->first();

        expect($activity)->not->toBeNull();
        expect($activity->description)->toContain($crew->name);
        expect($activity->metadata['crew_user_id'])->toBe($crew->id);
        expect($activity->metadata['previous_status'])->toBe('dispatched');
        expect($activity->metadata['service_category_id'])->toBe($category->id);
    });
});
