<?php

use App\AI\Tools\AddClipToProject;
use App\AI\Tools\AddTextOverlay;
use App\AI\Tools\CreateVideoProject;
use App\AI\Tools\GetDealMedia;
use App\AI\Tools\GetEstimatePhotos;
use App\AI\Tools\GetJobPhotos;
use App\AI\Tools\GetPropertyPhotos;
use App\AI\Tools\SearchDeals;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRecording;

it('searches deals by title', function () {
    $user = User::factory()->internal()->create();
    Deal::factory()->create(['title' => 'Johnson Kitchen Repaint']);
    Deal::factory()->create(['title' => 'Smith Exterior']);

    $tool = new SearchDeals($user);
    $result = json_decode($tool->handle(query: 'Johnson'), true);

    expect($result)->toHaveCount(1)
        ->and($result[0]['title'])->toBe('Johnson Kitchen Repaint');
});

it('filters deals by stage', function () {
    $user = User::factory()->internal()->create();
    Deal::factory()->create(['title' => 'Lead Deal', 'stage' => 'lead']);
    Deal::factory()->create(['title' => 'Quoted Deal', 'stage' => 'quoted']);

    $tool = new SearchDeals($user);
    $result = json_decode($tool->handle(query: 'Deal', status: 'quoted'), true);

    expect($result)->toHaveCount(1)
        ->and($result[0]['title'])->toBe('Quoted Deal');
});

it('limits deal search results to 10', function () {
    $user = User::factory()->internal()->create();

    Deal::factory()->count(15)->create(['title' => 'Bulk Deal']);

    $tool = new SearchDeals($user);
    $result = json_decode($tool->handle(query: 'Bulk'), true);

    expect($result)->toHaveCount(10);
});

it('returns deal relationships in search results', function () {
    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
    $deal = Deal::factory()->create([
        'title' => 'Test Deal',
        'customer_id' => $customer->id,
    ]);
    Estimate::factory()->create(['deal_id' => $deal->id]);

    $tool = new SearchDeals($user);
    $result = json_decode($tool->handle(query: 'Test Deal'), true);

    expect($result)->toHaveCount(1)
        ->and($result[0]['customer'])->toBe('Jane Doe')
        ->and($result[0]['has_estimates'])->toBeTrue()
        ->and($result[0]['has_job'])->toBeFalse();
});

it('gets deal media photos from property', function () {
    $user = User::factory()->internal()->create();
    $property = Property::factory()->create();
    $deal = Deal::factory()->create(['property_id' => $property->id]);
    PropertyPhoto::factory()->count(3)->create(['property_id' => $property->id]);

    $tool = new GetDealMedia($user);
    $result = json_decode($tool->handle(dealId: $deal->id), true);

    expect($result['photos'])->toHaveCount(3)
        ->and($result['videos'])->toBeEmpty();
});

it('gets deal media photos from estimates', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);
    PropertyPhoto::factory()->count(2)->create(['estimate_id' => $estimate->id]);

    $tool = new GetDealMedia($user);
    $result = json_decode($tool->handle(dealId: $deal->id), true);

    expect($result['photos'])->toHaveCount(2);
});

it('deduplicates photos across property and estimate in deal media', function () {
    $user = User::factory()->internal()->create();
    $property = Property::factory()->create();
    $deal = Deal::factory()->create(['property_id' => $property->id]);
    $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

    $photo = PropertyPhoto::factory()->create([
        'property_id' => $property->id,
        'estimate_id' => $estimate->id,
    ]);

    $tool = new GetDealMedia($user);
    $result = json_decode($tool->handle(dealId: $deal->id), true);

    expect($result['photos'])->toHaveCount(1);
});

it('gets job photos', function () {
    $user = User::factory()->internal()->create();
    $job = Job::factory()->create();
    $otherJob = Job::factory()->create();
    PropertyPhoto::factory()->count(3)->create(['job_id' => $job->id]);
    PropertyPhoto::factory()->count(2)->create(['job_id' => $otherJob->id]);

    $tool = new GetJobPhotos($user);
    $result = json_decode($tool->handle(jobId: $job->id), true);

    expect($result)->toHaveCount(3);
});

it('gets estimate photos', function () {
    $user = User::factory()->internal()->create();
    $estimate = Estimate::factory()->create();
    PropertyPhoto::factory()->count(4)->create(['estimate_id' => $estimate->id]);

    $tool = new GetEstimatePhotos($user);
    $result = json_decode($tool->handle(estimateId: $estimate->id), true);

    expect($result)->toHaveCount(4);
});

it('gets property photos', function () {
    $user = User::factory()->internal()->create();
    $property = Property::factory()->create();
    PropertyPhoto::factory()->count(5)->create(['property_id' => $property->id]);

    $tool = new GetPropertyPhotos($user);
    $result = json_decode($tool->handle(propertyId: $property->id), true);

    expect($result)->toHaveCount(5);
});

it('creates a video project with ai_generated mode', function () {
    $user = User::factory()->internal()->create();

    $tool = new CreateVideoProject($user);
    $result = json_decode($tool->handle(title: 'AI Generated Video'), true);

    expect($result['title'])->toBe('AI Generated Video')
        ->and($result['status'])->toBe('draft');

    $project = VideoProject::find($result['id']);
    expect($project->creation_mode)->toBe('ai_generated')
        ->and($project->aspect_ratio)->toBe('9:16')
        ->and($project->user_id)->toBe($user->id)
        ->and($project->scenes()->count())->toBe(1);
});

it('creates a video project with custom aspect ratio', function () {
    $user = User::factory()->internal()->create();

    $tool = new CreateVideoProject($user);
    $result = json_decode($tool->handle(title: 'Landscape Video', aspectRatio: '16:9'), true);

    $project = VideoProject::find($result['id']);
    expect($project->aspect_ratio)->toBe('16:9');
});

it('adds a photo clip to a project', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);
    $photo = PropertyPhoto::factory()->create();

    $tool = new AddClipToProject($user);
    $result = json_decode($tool->handle(
        projectId: $project->id,
        sourceType: 'property-photo',
        sourceId: $photo->id,
    ), true);

    expect($result)->toHaveKey('id')
        ->and($result['sort_order'])->toBe(1);

    $scene = $project->scenes()->first();
    expect($scene->clips()->count())->toBe(1);

    $clip = $scene->clips()->first();
    expect($clip->source_type)->toBe(PropertyPhoto::class)
        ->and($clip->source_id)->toBe($photo->id);
});

it('adds a video recording clip to a project', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);
    $video = VideoRecording::factory()->create();

    $tool = new AddClipToProject($user);
    $result = json_decode($tool->handle(
        projectId: $project->id,
        sourceType: 'video-recording',
        sourceId: $video->id,
        durationMs: 5000,
    ), true);

    $clip = $project->scenes()->first()->clips()->first();
    expect($clip->source_type)->toBe(VideoRecording::class)
        ->and($clip->out_point_ms)->toBe(5000);
});

it('increments clip sort order', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);

    $tool = new AddClipToProject($user);
    $result1 = json_decode($tool->handle($project->id, 'property-photo', PropertyPhoto::factory()->create()->id), true);
    $result2 = json_decode($tool->handle($project->id, 'property-photo', PropertyPhoto::factory()->create()->id), true);

    expect($result1['sort_order'])->toBe(1)
        ->and($result2['sort_order'])->toBe(2);
});

it('rejects invalid source type for clip', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);

    $tool = new AddClipToProject($user);
    $tool->handle($project->id, 'invalid-type', 1);
})->throws(InvalidArgumentException::class);

it('adds a text overlay to a project', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);

    $tool = new AddTextOverlay($user);
    $result = json_decode($tool->handle(
        projectId: $project->id,
        content: 'Hello World',
        position: 'center',
    ), true);

    expect($result)->toHaveKey('id');

    $overlay = $project->scenes()->first()->overlays()->first();
    expect($overlay->type)->toBe('text')
        ->and($overlay->content)->toBe('Hello World')
        ->and((float) $overlay->position_y)->toBe(0.5);
});

it('positions text overlay at bottom by default', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);

    $tool = new AddTextOverlay($user);
    $tool->handle(projectId: $project->id, content: 'Bottom text');

    $overlay = $project->scenes()->first()->overlays()->first();
    expect((float) $overlay->position_y)->toBe(0.85);
});

it('returns prism tool definitions', function () {
    $user = User::factory()->internal()->create();

    $tools = [
        SearchDeals::definition($user),
        GetDealMedia::definition($user),
        GetJobPhotos::definition($user),
        GetEstimatePhotos::definition($user),
        GetPropertyPhotos::definition($user),
        CreateVideoProject::definition($user),
        AddClipToProject::definition($user),
        AddTextOverlay::definition($user),
    ];

    expect($tools)->toHaveCount(8);

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(\Prism\Prism\Tool::class)
            ->and($tool->name())->not->toBeEmpty()
            ->and($tool->description())->not->toBeEmpty();
    }
});
