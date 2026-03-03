<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\StudioTemplate;
use App\Models\User;
use App\Models\VideoProject;
use App\Services\StudioAiService;

it('fills a before/after template with deal photos', function () {
    $user = User::factory()->internal()->create();
    $property = Property::factory()->create();
    $deal = Deal::factory()->create(['property_id' => $property->id]);

    PropertyPhoto::factory()->create(['property_id' => $property->id, 'category' => 'before']);
    PropertyPhoto::factory()->create(['property_id' => $property->id, 'category' => 'after']);

    $template = StudioTemplate::factory()->create([
        'category' => 'before_after',
        'structure_json' => [
            'segments' => [
                ['type' => 'media', 'label' => 'Before', 'auto_fill' => 'before_photos', 'duration_ms' => 3000],
                ['type' => 'recording', 'label' => 'Commentary'],
                ['type' => 'media', 'label' => 'After', 'auto_fill' => 'after_photos', 'duration_ms' => 3000],
            ],
            'overlays' => [
                ['type' => 'text', 'auto_fill' => 'company_name', 'position' => 'bottom'],
            ],
            'aspect_ratio' => '9:16',
        ],
    ]);

    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'template_id' => $template->id,
        'creation_mode' => 'template',
        'metadata' => ['deal_id' => $deal->id],
    ]);

    foreach ($template->structure_json['segments'] as $i => $seg) {
        $project->scenes()->create(['sort_order' => $i, 'name' => $seg['label'], 'layout' => 'solo']);
    }

    $service = new StudioAiService($user);
    $service->fillTemplate($project);

    $beforeScene = $project->scenes()->where('sort_order', 0)->first();
    expect($beforeScene->clips()->count())->toBeGreaterThan(0);

    $afterScene = $project->scenes()->where('sort_order', 2)->first();
    expect($afterScene->clips()->count())->toBeGreaterThan(0);

    $recordingScene = $project->scenes()->where('sort_order', 1)->first();
    expect($recordingScene->clips()->count())->toBe(0);
});

it('skips fill when no deal_id in metadata', function () {
    $user = User::factory()->internal()->create();
    $template = StudioTemplate::factory()->create();
    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'template_id' => $template->id,
        'metadata' => [],
    ]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);

    $service = new StudioAiService($user);
    $service->fillTemplate($project);

    expect($project->scenes()->first()->clips()->count())->toBe(0);
});

it('skips fill when project has no template', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'template_id' => null,
    ]);

    $service = new StudioAiService($user);
    $service->fillTemplate($project);

    expect(true)->toBeTrue();
});

it('skips fill when deal does not exist', function () {
    $user = User::factory()->internal()->create();
    $template = StudioTemplate::factory()->create();
    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'template_id' => $template->id,
        'metadata' => ['deal_id' => 99999],
    ]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);

    $service = new StudioAiService($user);
    $service->fillTemplate($project);

    expect($project->scenes()->first()->clips()->count())->toBe(0);
});

it('adds company name overlay from template', function () {
    $user = User::factory()->internal()->create();
    $company = CompanySetting::getInstance();
    $company->update(['company_name' => 'Apex Painting Co']);

    $deal = Deal::factory()->create();
    $template = StudioTemplate::factory()->create([
        'structure_json' => [
            'segments' => [['type' => 'recording', 'label' => 'Intro']],
            'overlays' => [['type' => 'text', 'auto_fill' => 'company_name', 'position' => 'bottom']],
        ],
    ]);

    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'template_id' => $template->id,
        'metadata' => ['deal_id' => $deal->id],
    ]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Intro', 'layout' => 'solo']);

    $service = new StudioAiService($user);
    $service->fillTemplate($project);

    $overlays = $project->scenes()->first()->overlays;
    expect($overlays)->toHaveCount(1)
        ->and($overlays->first()->content)->toBe('Apex Painting Co')
        ->and($overlays->first()->type)->toBe('text');
});

it('adds customer name overlay from template', function () {
    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create(['first_name' => 'Sarah', 'last_name' => 'Miller']);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);

    $template = StudioTemplate::factory()->create([
        'structure_json' => [
            'segments' => [['type' => 'recording', 'label' => 'Intro']],
            'overlays' => [['type' => 'text', 'auto_fill' => 'customer_name']],
        ],
    ]);

    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'template_id' => $template->id,
        'metadata' => ['deal_id' => $deal->id],
    ]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Intro', 'layout' => 'solo']);

    $service = new StudioAiService($user);
    $service->fillTemplate($project);

    $overlay = $project->scenes()->first()->overlays->first();
    expect($overlay->content)->toBe('Sarah Miller');
});

it('adds estimate total overlay from template', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();
    Estimate::factory()->create(['deal_id' => $deal->id, 'total' => 5250.00]);

    $template = StudioTemplate::factory()->create([
        'structure_json' => [
            'segments' => [['type' => 'recording', 'label' => 'Intro']],
            'overlays' => [['type' => 'text', 'auto_fill' => 'estimate_total']],
        ],
    ]);

    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'template_id' => $template->id,
        'metadata' => ['deal_id' => $deal->id],
    ]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Intro', 'layout' => 'solo']);

    $service = new StudioAiService($user);
    $service->fillTemplate($project);

    $overlay = $project->scenes()->first()->overlays->first();
    expect($overlay->content)->toBe('$5,250');
});

it('fills clips from estimate photos', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);
    PropertyPhoto::factory()->count(3)->create(['estimate_id' => $estimate->id, 'category' => 'general']);

    $template = StudioTemplate::factory()->create([
        'structure_json' => [
            'segments' => [
                ['type' => 'media', 'label' => 'Photos', 'auto_fill' => 'all_photos'],
            ],
            'overlays' => [],
        ],
    ]);

    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'template_id' => $template->id,
        'metadata' => ['deal_id' => $deal->id],
    ]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Photos', 'layout' => 'solo']);

    $service = new StudioAiService($user);
    $service->fillTemplate($project);

    expect($project->scenes()->first()->clips()->count())->toBe(3);
});

it('limits clips per segment', function () {
    $user = User::factory()->internal()->create();
    $property = Property::factory()->create();
    $deal = Deal::factory()->create(['property_id' => $property->id]);
    PropertyPhoto::factory()->count(10)->create(['property_id' => $property->id, 'category' => 'after']);

    $template = StudioTemplate::factory()->create([
        'structure_json' => [
            'segments' => [
                ['type' => 'media', 'label' => 'After', 'auto_fill' => 'after_photos'],
            ],
            'overlays' => [],
        ],
    ]);

    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'template_id' => $template->id,
        'metadata' => ['deal_id' => $deal->id],
    ]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'After', 'layout' => 'solo']);

    $service = new StudioAiService($user);
    $service->fillTemplate($project);

    expect($project->scenes()->first()->clips()->count())->toBe(3);
});

it('places overlay on specific segment when segment_index is set', function () {
    $user = User::factory()->internal()->create();
    $company = CompanySetting::getInstance();
    $company->update(['company_name' => 'Test Co']);

    $deal = Deal::factory()->create();
    $template = StudioTemplate::factory()->create([
        'structure_json' => [
            'segments' => [
                ['type' => 'recording', 'label' => 'Scene A'],
                ['type' => 'recording', 'label' => 'Scene B'],
            ],
            'overlays' => [
                ['type' => 'text', 'auto_fill' => 'company_name', 'segment_index' => 1],
            ],
        ],
    ]);

    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'template_id' => $template->id,
        'metadata' => ['deal_id' => $deal->id],
    ]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene A', 'layout' => 'solo']);
    $project->scenes()->create(['sort_order' => 1, 'name' => 'Scene B', 'layout' => 'solo']);

    $service = new StudioAiService($user);
    $service->fillTemplate($project);

    $sceneA = $project->scenes()->where('sort_order', 0)->first();
    $sceneB = $project->scenes()->where('sort_order', 1)->first();

    expect($sceneA->overlays()->count())->toBe(0)
        ->and($sceneB->overlays()->count())->toBe(1)
        ->and($sceneB->overlays->first()->content)->toBe('Test Co');
});

it('skips overlay when auto_fill resolves to empty string', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create(['customer_id' => null]);

    $template = StudioTemplate::factory()->create([
        'structure_json' => [
            'segments' => [['type' => 'recording', 'label' => 'Intro']],
            'overlays' => [['type' => 'text', 'auto_fill' => 'customer_name']],
        ],
    ]);

    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'template_id' => $template->id,
        'metadata' => ['deal_id' => $deal->id],
    ]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Intro', 'layout' => 'solo']);

    $service = new StudioAiService($user);
    $service->fillTemplate($project);

    expect($project->scenes()->first()->overlays()->count())->toBe(0);
});

it('deduplicates photos across property and estimates', function () {
    $user = User::factory()->internal()->create();
    $property = Property::factory()->create();
    $deal = Deal::factory()->create(['property_id' => $property->id]);
    $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

    $photo = PropertyPhoto::factory()->create([
        'property_id' => $property->id,
        'estimate_id' => $estimate->id,
        'category' => 'before',
    ]);

    $template = StudioTemplate::factory()->create([
        'structure_json' => [
            'segments' => [
                ['type' => 'media', 'label' => 'Before', 'auto_fill' => 'all_photos'],
            ],
            'overlays' => [],
        ],
    ]);

    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'template_id' => $template->id,
        'metadata' => ['deal_id' => $deal->id],
    ]);
    $project->scenes()->create(['sort_order' => 0, 'name' => 'Before', 'layout' => 'solo']);

    $service = new StudioAiService($user);
    $service->fillTemplate($project);

    expect($project->scenes()->first()->clips()->count())->toBe(1);
});
