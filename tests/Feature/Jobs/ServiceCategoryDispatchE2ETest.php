<?php

use App\Models\Job;
use App\Models\JobServiceRequirement;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\JobDispatchService;
use Livewire\Livewire;

beforeEach(function () {
    $this->painting = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );
    $this->cleaning = ServiceCategory::firstOrCreate(
        ['slug' => 'cleaning'],
        ['name' => 'Cleaning', 'icon' => 'sparkles', 'color' => 'green', 'is_active' => true, 'sort_order' => 2]
    );
});

it('crew without service category sees warning on available jobs', function () {
    $crew = User::factory()->crew()->create();

    $this->actingAs($crew, 'crew');

    Livewire::test('crew.available-jobs')
        ->assertSee('Service Category Required');
});

it('crew with service category does not see warning', function () {
    $crew = User::factory()->crew()->create();
    $crew->crewProfile->update(['service_category_id' => $this->painting->id]);

    $this->actingAs($crew, 'crew');

    Livewire::test('crew.available-jobs')
        ->assertDontSee('Service Category Required');
});

it('crew can set service category in settings', function () {
    $crew = User::factory()->crew()->create();

    $this->actingAs($crew, 'crew');

    Livewire::test('crew.settings')
        ->set('serviceCategoryId', $this->painting->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($crew->fresh()->crewProfile->service_category_id)->toBe($this->painting->id);
});

it('dispatch only finds crews matching service category', function () {
    $paintCrew = User::factory()->crew()->create();
    $paintCrew->crewProfile->update([
        'service_category_id' => $this->painting->id,
        'crew_size' => 3,
    ]);

    $cleanCrew = User::factory()->crew()->create();
    $cleanCrew->crewProfile->update([
        'service_category_id' => $this->cleaning->id,
        'crew_size' => 3,
    ]);

    $job = Job::factory()->create(['required_crew_size' => 2]);
    JobServiceRequirement::factory()->create([
        'job_id' => $job->id,
        'service_category_id' => $this->painting->id,
        'required_crew_size' => 2,
    ]);

    $service = app(JobDispatchService::class);
    $eligible = $service->getEligibleCrews($job, $this->painting);

    expect($eligible->pluck('id')->toArray())->toContain($paintCrew->id);
    expect($eligible->pluck('id')->toArray())->not->toContain($cleanCrew->id);
});

it('crew with null service category is not eligible for any dispatch', function () {
    $nullCrew = User::factory()->crew()->create();
    $nullCrew->crewProfile->update(['crew_size' => 5]);

    $job = Job::factory()->create(['required_crew_size' => 2]);
    JobServiceRequirement::factory()->create([
        'job_id' => $job->id,
        'service_category_id' => $this->painting->id,
        'required_crew_size' => 2,
    ]);

    $service = app(JobDispatchService::class);
    $eligible = $service->getEligibleCrews($job, $this->painting);

    expect($eligible->pluck('id')->toArray())->not->toContain($nullCrew->id);
});
