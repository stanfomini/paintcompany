<?php

use App\Models\Job;
use App\Models\JobServiceRequirement;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\JobDispatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Helper to get or create a service category by slug, avoiding unique constraint violations.
 */
function paintingCategory(): ServiceCategory
{
    return ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'description' => 'Interior and exterior painting services', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );
}

function cleaningCategory(): ServiceCategory
{
    return ServiceCategory::firstOrCreate(
        ['slug' => 'cleaning'],
        ['name' => 'Cleaning', 'description' => 'Post-painting cleanup and pressure washing', 'icon' => 'sparkles', 'color' => 'green', 'is_active' => true, 'sort_order' => 2]
    );
}

/**
 * Helper to create a crew user with a specific service category and crew size.
 */
function createCrewForService(ServiceCategory $category, int $crewSize = 3): User
{
    $user = User::factory()->crew()->create();
    $user->crewProfile->update([
        'service_category_id' => $category->id,
        'crew_size' => $crewSize,
    ]);
    $user->load('crewProfile');

    return $user;
}

it('getEligibleCrews filters by service_category_id on crew profiles', function () {
    $painting = paintingCategory();
    $cleaning = cleaningCategory();

    $paintCrew = createCrewForService($painting, 3);
    $cleanCrew = createCrewForService($cleaning, 3);

    $job = Job::factory()->create(['required_crew_size' => 2]);
    JobServiceRequirement::factory()->create([
        'job_id' => $job->id,
        'service_category_id' => $painting->id,
        'required_crew_size' => 2,
    ]);

    $service = app(JobDispatchService::class);
    $eligible = $service->getEligibleCrews($job, $painting);

    expect($eligible->pluck('id')->toArray())->toContain($paintCrew->id);
    expect($eligible->pluck('id')->toArray())->not->toContain($cleanCrew->id);
});

it('getEligibleCrews filters by crew_size >= required_crew_size', function () {
    $painting = paintingCategory();

    $smallCrew = createCrewForService($painting, 2);
    $largeCrew = createCrewForService($painting, 5);

    $job = Job::factory()->create(['required_crew_size' => 3]);
    JobServiceRequirement::factory()->create([
        'job_id' => $job->id,
        'service_category_id' => $painting->id,
        'required_crew_size' => 4,
    ]);

    $service = app(JobDispatchService::class);
    $eligible = $service->getEligibleCrews($job, $painting);

    // Only the large crew (5) meets the requirement (4)
    expect($eligible->pluck('id')->toArray())->toContain($largeCrew->id);
    expect($eligible->pluck('id')->toArray())->not->toContain($smallCrew->id);
});

it('dispatchToCrews with a service category tags the pivot with service_category_id', function () {
    $painting = paintingCategory();
    $crew = createCrewForService($painting, 3);

    $job = Job::factory()->create(['required_crew_size' => 2]);
    JobServiceRequirement::factory()->create([
        'job_id' => $job->id,
        'service_category_id' => $painting->id,
        'required_crew_size' => 2,
        'status' => JobServiceRequirement::STATUS_PENDING,
    ]);

    $service = app(JobDispatchService::class);
    $dispatched = $service->dispatchToCrews($job, [$crew->id], $painting);

    expect($dispatched)->toBe(1);

    $pivot = DB::table('job_crew')
        ->where('job_id', $job->id)
        ->where('crew_user_id', $crew->id)
        ->first();

    expect($pivot->service_category_id)->toBe($painting->id);
    expect($pivot->status)->toBe('dispatched');
});

it('dispatchToCrews updates the JobServiceRequirement status to dispatched', function () {
    $painting = paintingCategory();
    $crew = createCrewForService($painting, 3);

    $job = Job::factory()->create(['required_crew_size' => 2]);
    $requirement = JobServiceRequirement::factory()->create([
        'job_id' => $job->id,
        'service_category_id' => $painting->id,
        'required_crew_size' => 2,
        'status' => JobServiceRequirement::STATUS_PENDING,
    ]);

    $service = app(JobDispatchService::class);
    $service->dispatchToCrews($job, [$crew->id], $painting);

    $requirement->refresh();
    expect($requirement->status)->toBe(JobServiceRequirement::STATUS_DISPATCHED);
});

it('acceptJob scoped to crew service category only auto-rejects same-service crews', function () {
    $painting = paintingCategory();
    $cleaning = cleaningCategory();

    $paintCrew1 = createCrewForService($painting, 3);
    $paintCrew2 = createCrewForService($painting, 3);
    $cleanCrew = createCrewForService($cleaning, 3);

    $job = Job::factory()->create(['required_crew_size' => 2]);

    $service = app(JobDispatchService::class);

    // Dispatch painting crews for the painting category
    $service->dispatchToCrews($job, [$paintCrew1->id, $paintCrew2->id], $painting);
    // Dispatch cleaning crew for the cleaning category
    $service->dispatchToCrews($job, [$cleanCrew->id], $cleaning);

    Event::fake();

    // Paint crew 1 accepts
    $result = $service->acceptJob($job, $paintCrew1);
    expect($result)->toBeTrue();

    // Paint crew 2 should be auto-rejected (same service)
    $paintCrew2Pivot = DB::table('job_crew')
        ->where('job_id', $job->id)
        ->where('crew_user_id', $paintCrew2->id)
        ->where('service_category_id', $painting->id)
        ->first();
    expect($paintCrew2Pivot->status)->toBe('rejected');

    // Cleaning crew should still be dispatched (different service)
    $cleanCrewPivot = DB::table('job_crew')
        ->where('job_id', $job->id)
        ->where('crew_user_id', $cleanCrew->id)
        ->where('service_category_id', $cleaning->id)
        ->first();
    expect($cleanCrewPivot->status)->toBe('dispatched');
});

it('acceptJob race condition: only first crew of same service can accept', function () {
    $painting = paintingCategory();

    $crew1 = createCrewForService($painting, 3);
    $crew2 = createCrewForService($painting, 3);

    $job = Job::factory()->create(['required_crew_size' => 2]);

    $service = app(JobDispatchService::class);
    $service->dispatchToCrews($job, [$crew1->id, $crew2->id], $painting);

    Event::fake();

    // First crew accepts
    expect($service->acceptJob($job, $crew1))->toBeTrue();

    // Second crew tries to accept - should fail
    expect($service->acceptJob($job, $crew2))->toBeFalse();
});

it('rejectJob scoped to crew service category', function () {
    $painting = paintingCategory();
    $cleaning = cleaningCategory();

    $paintCrew = createCrewForService($painting, 3);

    $job = Job::factory()->create(['required_crew_size' => 2]);

    $service = app(JobDispatchService::class);

    // Dispatch for painting category
    $service->dispatchToCrews($job, [$paintCrew->id], $painting);
    // Also dispatch same crew for cleaning (different pivot row via different service_category_id)
    $service->dispatchToCrews($job, [$paintCrew->id], $cleaning);

    // Reject only the painting assignment
    $result = $service->rejectJob($job, $paintCrew, 'Not available for painting');
    expect($result)->toBeTrue();

    // Painting assignment should be rejected
    $paintPivot = DB::table('job_crew')
        ->where('job_id', $job->id)
        ->where('crew_user_id', $paintCrew->id)
        ->where('service_category_id', $painting->id)
        ->first();
    expect($paintPivot->status)->toBe('rejected');

    // Cleaning assignment should still be dispatched
    $cleanPivot = DB::table('job_crew')
        ->where('job_id', $job->id)
        ->where('crew_user_id', $paintCrew->id)
        ->where('service_category_id', $cleaning->id)
        ->first();
    expect($cleanPivot->status)->toBe('dispatched');
});

it('replaceCrew revokes accepted crew and opens slot', function () {
    $painting = paintingCategory();

    $crew = createCrewForService($painting, 3);

    $job = Job::factory()->create(['required_crew_size' => 2]);
    $requirement = JobServiceRequirement::factory()->create([
        'job_id' => $job->id,
        'service_category_id' => $painting->id,
        'required_crew_size' => 2,
        'status' => JobServiceRequirement::STATUS_ACCEPTED,
    ]);

    $service = app(JobDispatchService::class);

    // Dispatch and accept the crew
    $service->dispatchToCrews($job, [$crew->id], $painting);

    Event::fake();
    $service->acceptJob($job, $crew);

    // Now replace the crew
    $service->replaceCrew($job, $crew, $painting);

    // Pivot should be 'replaced'
    $pivot = DB::table('job_crew')
        ->where('job_id', $job->id)
        ->where('crew_user_id', $crew->id)
        ->where('service_category_id', $painting->id)
        ->first();
    expect($pivot->status)->toBe('replaced');
    expect($pivot->notes)->toBe('Replaced by company');

    // Requirement should be back to 'dispatched'
    $requirement->refresh();
    expect($requirement->status)->toBe(JobServiceRequirement::STATUS_DISPATCHED);
});

it('different service types can have independent dispatch', function () {
    $painting = paintingCategory();
    $cleaning = cleaningCategory();

    $paintCrew = createCrewForService($painting, 3);
    $cleanCrew = createCrewForService($cleaning, 3);

    $job = Job::factory()->create(['required_crew_size' => 2]);

    $paintReq = JobServiceRequirement::factory()->create([
        'job_id' => $job->id,
        'service_category_id' => $painting->id,
        'required_crew_size' => 2,
        'status' => JobServiceRequirement::STATUS_PENDING,
    ]);
    $cleanReq = JobServiceRequirement::factory()->create([
        'job_id' => $job->id,
        'service_category_id' => $cleaning->id,
        'required_crew_size' => 2,
        'status' => JobServiceRequirement::STATUS_PENDING,
    ]);

    $service = app(JobDispatchService::class);

    // Dispatch painting crew for painting
    $service->dispatchToCrews($job, [$paintCrew->id], $painting);
    // Dispatch cleaning crew for cleaning
    $service->dispatchToCrews($job, [$cleanCrew->id], $cleaning);

    Event::fake();

    // Both can independently accept their respective service
    expect($service->acceptJob($job, $paintCrew))->toBeTrue();
    expect($service->acceptJob($job, $cleanCrew))->toBeTrue();

    // Both pivots should be accepted
    $paintPivot = DB::table('job_crew')
        ->where('job_id', $job->id)
        ->where('crew_user_id', $paintCrew->id)
        ->where('service_category_id', $painting->id)
        ->first();
    expect($paintPivot->status)->toBe('accepted');

    $cleanPivot = DB::table('job_crew')
        ->where('job_id', $job->id)
        ->where('crew_user_id', $cleanCrew->id)
        ->where('service_category_id', $cleaning->id)
        ->first();
    expect($cleanPivot->status)->toBe('accepted');

    // Both requirements should be accepted
    $paintReq->refresh();
    $cleanReq->refresh();
    expect($paintReq->status)->toBe(JobServiceRequirement::STATUS_ACCEPTED);
    expect($cleanReq->status)->toBe(JobServiceRequirement::STATUS_ACCEPTED);
});

it('available jobs for crew filtered by crew service_category_id', function () {
    $painting = paintingCategory();
    $cleaning = cleaningCategory();

    $paintCrew = createCrewForService($painting, 3);
    $cleanCrew = createCrewForService($cleaning, 3);

    $job1 = Job::factory()->create(['title' => 'Paint Job Alpha']);
    $job2 = Job::factory()->create(['title' => 'Clean Job Beta']);

    $service = app(JobDispatchService::class);

    // Dispatch paint job to paint crew, clean job to clean crew
    $service->dispatchToCrews($job1, [$paintCrew->id], $painting);
    $service->dispatchToCrews($job2, [$cleanCrew->id], $cleaning);

    // Paint crew should see only the painting job
    $paintJobs = $service->getAvailableJobsForCrew($paintCrew);
    expect($paintJobs->pluck('id')->toArray())->toContain($job1->id);
    expect($paintJobs->pluck('id')->toArray())->not->toContain($job2->id);

    // Clean crew should see only the cleaning job
    $cleanJobs = $service->getAvailableJobsForCrew($cleanCrew);
    expect($cleanJobs->pluck('id')->toArray())->toContain($job2->id);
    expect($cleanJobs->pluck('id')->toArray())->not->toContain($job1->id);
});
